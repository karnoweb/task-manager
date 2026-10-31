<?php

namespace Karnoweb\TaskManager\Integration;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Scheduling\LocalTime;
use Karnoweb\TaskManager\Schema\Tables;

final class EventPump
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly EventAccess $access, private readonly ConsumerRegistry $consumers)
    {
        Tables::assertConnection($db);
    }

    private function authorize(ExecutionContext $context): void
    {
        $context->require('subscription.manage');
        $this->authorization->authorize($context, 'subscription.manage');
    }

    public function subscribe(ExecutionContext $context, string $eventType, string $consumer, string $operation): array
    {
        $context->validateTenant();
        $this->authorize($context);
        $this->consumers->require($consumer);
        if (! preg_match('/^[a-z][a-z0-9_]{0,99}$/D', $eventType)) {
            throw new DomainError('subscription_invalid');
        }

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'subscribe_event', 'event' => $eventType, 'consumer' => $consumer], function () use ($context, $eventType, $consumer, $operation) {
            $key = (string) Str::uuid();
            $this->db->table(Tables::name('event_subscriptions'))->insertOrIgnore(['subscription_key' => $key, 'scope_key' => $context->scope, 'actor_ref' => $context->actor, 'event_type' => $eventType, 'consumer_key' => $consumer]);
            $row = $this->db->table(Tables::name('event_subscriptions'))->where('scope_key', $context->scope)->where('actor_ref', $context->actor)->where('event_type', $eventType)->where('consumer_key', $consumer)->first();
            (new EventRecorder($this->db, $this->clock))->append($context, 'event_subscription', $row->subscription_key, 'event_subscribed', $operation, []);

            return (array) $row;
        }, fn ($result) => $this->subscription($context, $result['subscription_key']));
    }

    private function subscription(ExecutionContext $context, string $key): array
    {
        $this->authorize($context);
        $row = $this->db->table(Tables::name('event_subscriptions'))->where('scope_key', $context->scope)->where('actor_ref', $context->actor)->where('subscription_key', $key)->first();
        if (! $row) {
            throw new DomainError('not_found');
        }

        return (array) $row;
    }

    public function toggle(ExecutionContext $context, string $key, int $version, bool $active, string $operation): array
    {
        $context->validateTenant();
        $this->authorize($context);

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'toggle_subscription', 'key' => $key, 'version' => $version, 'active' => $active], function () use ($context, $key, $version, $active, $operation) {
            $row = $this->subscription($context, $key);
            $changed = $this->db->table(Tables::name('event_subscriptions'))->where('id', $row['id'])->where('version', $version)->update(['active' => $active, 'version' => $version + 1]);
            if ($changed !== 1) {
                throw new DomainError('version_conflict');
            }(new EventRecorder($this->db, $this->clock))->append($context, 'event_subscription', $key, 'event_subscription_toggled', $operation, ['active' => $active, 'version' => $version + 1]);

            return $this->subscription($context, $key);
        }, fn ($result) => $this->subscription($context, $result['subscription_key']));
    }

    public function enqueue(ExecutionContext $context, int $limit = 100, ?int $replayAfter = null): array
    {
        $context->validateTenant();
        $this->worker($context, $limit);

        return $this->db->transaction(function () use ($context, $limit, $replayAfter) {
            $this->db->table(Tables::name('event_offsets'))->insertOrIgnore(['scope_key' => $context->scope, 'actor_ref' => $context->actor]);
            $offset = $this->db->table(Tables::name('event_offsets'))->where('scope_key', $context->scope)->where('actor_ref', $context->actor)->lockForUpdate()->first();
            $after = $replayAfter ?? $offset->last_id;
            if ($after < 0) {
                throw new DomainError('event_offset_invalid');
            }
            $events = $this->db->table(Tables::name('outbox'))->where('scope_key', $context->scope)->where('id', '>', $after)->orderBy('id')->limit($limit)->get();
            $subscriptions = $this->db->table(Tables::name('event_subscriptions'))->where('scope_key', $context->scope)->where('actor_ref', $context->actor)->where('active', true)->orderBy('id')->limit(101)->get();
            if ($subscriptions->count() > 100) {
                throw new DomainError('subscription_limit');
            }$made = 0;
            foreach ($events as $event) {
                $unsupported = false;
                try {
                    $this->access->source($context, (array) $event);
                } catch (DomainError $error) {
                    if ($error->getMessage() === 'event_schema_unsupported') {
                        $unsupported = true;
                    } else {
                        continue;
                    }
                }foreach ($subscriptions as $subscription) {
                    if ($subscription->event_type !== $event->action) {
                        continue;
                    }$made += $this->db->table(Tables::name('event_deliveries'))->insertOrIgnore(['subscription_id' => $subscription->id, 'event_id' => $event->event_id, 'state' => $unsupported ? 'dead' : 'pending', 'reason_code' => $unsupported ? 'event_schema_unsupported' : null]);
                }
            }
            if ($events->isNotEmpty()) {
                $this->db->table(Tables::name('event_offsets'))->where('scope_key', $context->scope)->where('actor_ref', $context->actor)->update(['last_id' => max($offset->last_id, $events->last()->id)]);
            }

            return ['queued' => $made, 'after_id' => $events->last()?->id ?? $after];
        }, 3);
    }

    private function worker(ExecutionContext $context, int $limit): void
    {
        $this->authorize($context);
        if (! $context->serviceActor || $this->db->transactionLevel() !== 0 || $limit < 1 || $limit > 1000) {
            throw new DomainError('event_worker_context_invalid');
        }
    }

    public function deliveries(ExecutionContext $context, int $afterId = 0, int $limit = 100): array
    {
        $context->validateTenant();
        $this->authorize($context);
        if ($afterId < 0 || $limit < 1 || $limit > 100) {
            throw new DomainError('pagination_invalid');
        }

        return $this->db->table(Tables::name('event_deliveries'))->whereIn('subscription_id', $this->db->table(Tables::name('event_subscriptions'))->where('scope_key', $context->scope)->where('actor_ref', $context->actor)->select('id'))->where('id', '>', $afterId)->orderBy('id')->limit($limit)->get()->map(fn ($row) => (array) $row)->all();
    }

    public function run(ExecutionContext $context, int $limit = 100): array
    {
        $context->validateTenant();
        $this->worker($context, $limit);
        $this->enqueue($context, $limit);
        $now = LocalTime::stamp($this->clock->now());
        $ids = $this->db->table(Tables::name('event_deliveries'))->whereIn('subscription_id', $this->db->table(Tables::name('event_subscriptions'))->where('scope_key', $context->scope)->where('actor_ref', $context->actor)->select('id'))
            ->whereIn('state', ['pending', 'retry', 'sending'])->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', $now))->orderBy('id')->limit($limit)->pluck('id');
        $results = ['sent' => 0, 'retry' => 0, 'dead' => 0, 'cancelled' => 0, 'deferred' => 0];
        foreach ($ids as $id) {
            $token = (string) Str::uuid();
            $claim = $this->db->transaction(function () use ($id, $token, $now) {
                $row = $this->db->table(Tables::name('event_deliveries'))->where('id', $id)->lockForUpdate()->first();
                if (! in_array($row->state, ['pending', 'retry', 'sending'], true) || ($row->lease_until !== null && $row->lease_until > $now) || ($row->next_attempt_at !== null && $row->next_attempt_at > $now)) {
                    return null;
                }$subscription = $this->db->table(Tables::name('event_subscriptions'))->where('id', $row->subscription_id)->first();
                if (! $subscription->active) {
                    $this->db->table(Tables::name('event_deliveries'))->where('id', $id)->update(['state' => 'cancelled']);

                    return null;
                }$this->db->table(Tables::name('event_deliveries'))->where('id', $id)->update(['state' => 'sending', 'attempts' => $row->attempts + 1, 'lease_token' => $token, 'lease_until' => LocalTime::stamp($this->clock->now()->modify('+30 seconds'))]);

                return ['row' => (array) $row, 'subscription' => (array) $subscription];
            }, 3);
            if ($claim === null) {
                continue;
            }$state = 'sent';
            $reason = null;
            $deferredAt = null;
            try {
                $event = $this->db->table(Tables::name('outbox'))->where('scope_key', $context->scope)->where('event_id', $claim['row']['event_id'])->first();
                if (! $event) {
                    throw new DomainError('not_found');
                }$this->access->source($context, (array) $event);
                $this->consumers->deliver($claim['subscription']['consumer_key'], $context, $this->access->envelope((array) $event));
            } catch (\Throwable $error) {
                if ($error instanceof DeferredDelivery) {
                    $deferredAt = $error->until;
                } $reason = $error instanceof DomainError && preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $error->getMessage()) ? $error->getMessage() : 'consumer_failed';
                $reason = $deferredAt !== null ? 'notification_quiet_hours' : $reason;
                $state = $deferredAt !== null ? 'retry' : (in_array($reason, ['not_found', 'access_denied', 'capability_denied', 'event_source_not_subscribable'], true) ? 'cancelled' : ($claim['row']['attempts'] + 1 >= 5 ? 'dead' : 'retry'));
            }
            $attempt = $claim['row']['attempts'] + 1;
            $delay = [5, 30, 120, 600, 3600][min($attempt - 1, 4)];
            $updated = $this->db->table(Tables::name('event_deliveries'))->where('id', $id)->where('state', 'sending')->where('lease_token', $token)->where('lease_until', '>', LocalTime::stamp($this->clock->now()))->update(['state' => $state, 'reason_code' => $reason, 'attempts' => $deferredAt !== null ? $claim['row']['attempts'] : $attempt, 'next_attempt_at' => $deferredAt !== null ? LocalTime::stamp($deferredAt) : ($state === 'retry' ? LocalTime::stamp($this->clock->now()->modify('+'.$delay.' seconds')) : null), 'lease_token' => null, 'lease_until' => null]);
            if ($updated) {
                $results[$deferredAt !== null ? 'deferred' : $state]++;
            }
        }

        return $results;
    }
}
