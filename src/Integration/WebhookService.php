<?php

namespace Karnoweb\TaskManager\Integration;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\Contracts\SecretVault;
use Karnoweb\TaskManager\Contracts\WebhookTransport;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Scheduling\LocalTime;
use Karnoweb\TaskManager\Schema\Tables;

final class WebhookService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly EventAccess $access, private readonly WebhookUrlPolicy $urls, private readonly SecretVault $vault, private readonly WebhookTransport $transport)
    {
        Tables::assertConnection($db);
    }

    private function authorize(ExecutionContext $context): void
    {
        $context->require('webhook.manage');
        $this->authorization->authorize($context, 'webhook.manage');
    }

    private function secret(ExecutionContext $context, string $reference): string
    {
        if (trim($reference) === '' || strlen($reference) > 120) {
            throw new DomainError('webhook_secret_invalid');
        }$secret = $this->vault->resolve($context, $reference);
        if (strlen($secret) < 32 || strlen($secret) > 4096) {
            throw new DomainError('webhook_secret_invalid');
        }

        return $secret;
    }

    private function validate(ExecutionContext $context, string $url, string $secretRef, array $types): void
    {
        $this->urls->validate($url);
        $this->secret($context, $secretRef);
        if (! array_is_list($types) || ! $types || count($types) > 50 || count(array_unique($types, SORT_REGULAR)) !== count($types)) {
            throw new DomainError('webhook_events_invalid');
        }foreach ($types as $type) {
            if (! is_string($type) || ! preg_match('/^[a-z][a-z0-9_]{0,99}$/D', $type)) {
                throw new DomainError('webhook_events_invalid');
            }
        }
    }

    public function get(ExecutionContext $context, string $key): array
    {
        $context->validateTenant();
        $this->authorize($context);
        if (! Str::isUuid($key)) {
            throw new DomainError('not_found');
        }$row = $this->db->table(Tables::name('webhook_endpoints'))->where('scope_key', $context->scope)->where('owner_ref', $context->actor)->where('endpoint_key', $key)->first();
        if (! $row) {
            throw new DomainError('not_found');
        }

        return [...(array) $row, 'event_types' => json_decode($row->event_types, true, flags: JSON_THROW_ON_ERROR)];
    }

    public function create(ExecutionContext $context, string $url, string $secretRef, array $types, string $operation): array
    {
        $context->validateTenant();
        $this->authorize($context);
        $this->validate($context, $url, $secretRef, $types);

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'webhook_create', 'url' => $url, 'secret_ref' => $secretRef, 'types' => $types], function () use ($context, $url, $secretRef, $types, $operation) {
            if ($this->db->table(Tables::name('webhook_endpoints'))->where('scope_key', $context->scope)->where('owner_ref', $context->actor)->count() >= 100) {
                throw new DomainError('webhook_endpoint_limit');
            }$key = (string) Str::uuid();
            $this->db->table(Tables::name('webhook_endpoints'))->insert(['endpoint_key' => $key, 'scope_key' => $context->scope, 'owner_ref' => $context->actor, 'url' => $url, 'secret_ref' => $secretRef, 'event_types' => json_encode($types, JSON_THROW_ON_ERROR)]);
            (new EventRecorder($this->db, $this->clock))->append($context, 'webhook_endpoint', $key, 'webhook_registered', $operation, ['event_types' => $types]);

            return $this->get($context, $key);
        }, fn ($result) => $this->get($context, $result['endpoint_key']));
    }

    public function change(ExecutionContext $context, string $key, int $version, string $url, string $secretRef, array $types, bool $active, string $operation): array
    {
        $context->validateTenant();
        $this->authorize($context);
        $this->validate($context, $url, $secretRef, $types);

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'webhook_change', 'key' => $key, 'version' => $version, 'url' => $url, 'secret_ref' => $secretRef, 'types' => $types, 'active' => $active], function () use ($context, $key, $version, $url, $secretRef, $types, $active, $operation) {
            $row = $this->get($context, $key);
            $changed = $this->db->table(Tables::name('webhook_endpoints'))->where('id', $row['id'])->where('version', $version)->update(['url' => $url, 'secret_ref' => $secretRef, 'event_types' => json_encode($types, JSON_THROW_ON_ERROR), 'active' => $active, 'version' => $version + 1]);
            if ($changed !== 1) {
                throw new DomainError('version_conflict');
            }(new EventRecorder($this->db, $this->clock))->append($context, 'webhook_endpoint', $key, 'webhook_changed', $operation, ['version' => $version + 1]);

            return $this->get($context, $key);
        }, fn ($result) => $this->get($context, $result['endpoint_key']));
    }

    public function queue(ExecutionContext $context, string $eventId): int
    {
        $context->validateTenant();
        $this->authorize($context);
        if (! Str::isUuid($eventId)) {
            throw new DomainError('not_found');
        }$event = $this->db->table(Tables::name('outbox'))->where('scope_key', $context->scope)->where('event_id', $eventId)->first();
        if (! $event) {
            throw new DomainError('not_found');
        }$this->access->source($context, (array) $event);
        $rows = $this->db->table(Tables::name('webhook_endpoints'))->where('scope_key', $context->scope)->where('owner_ref', $context->actor)->where('active', true)->orderBy('id')->limit(101)->get();
        if ($rows->count() > 100) {
            throw new DomainError('webhook_endpoint_limit');
        }$count = 0;
        foreach ($rows as $row) {
            if (in_array($event->action, json_decode($row->event_types, true, flags: JSON_THROW_ON_ERROR), true)) {
                $count += $this->db->table(Tables::name('webhook_deliveries'))->insertOrIgnore(['endpoint_id' => $row->id, 'event_id' => $eventId, 'endpoint_version' => $row->version]);
            }
        }

        return $count;
    }

    public function deliveries(ExecutionContext $context, int $afterId = 0, int $limit = 100): array
    {
        $context->validateTenant();
        $this->authorize($context);
        if ($afterId < 0 || $limit < 1 || $limit > 100) {
            throw new DomainError('pagination_invalid');
        }

        return $this->db->table(Tables::name('webhook_deliveries'))->whereIn('endpoint_id', $this->db->table(Tables::name('webhook_endpoints'))->where('scope_key', $context->scope)->where('owner_ref', $context->actor)->select('id'))->where('id', '>', $afterId)->orderBy('id')->limit($limit)->get()->map(function ($row) {
            $data = (array) $row;
            unset($data['lease_token']);

            return $data;
        })->all();
    }

    public function run(ExecutionContext $context, int $limit = 100): array
    {
        $context->validateTenant();
        $this->authorize($context);
        if (! $context->serviceActor || $this->db->transactionLevel() !== 0 || $limit < 1 || $limit > 1000) {
            throw new DomainError('webhook_worker_context_invalid');
        }$now = LocalTime::stamp($this->clock->now());
        $ids = $this->db->table(Tables::name('webhook_deliveries'))->whereIn('endpoint_id', $this->db->table(Tables::name('webhook_endpoints'))->where('scope_key', $context->scope)->where('owner_ref', $context->actor)->select('id'))->whereIn('state', ['pending', 'retry', 'sending'])->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', $now))->orderBy('id')->limit($limit)->pluck('id');
        $result = ['delivered' => 0, 'retry' => 0, 'dead' => 0, 'cancelled' => 0];
        foreach ($ids as $id) {
            $token = (string) Str::uuid();
            $claim = $this->db->transaction(function () use ($id, $token, $now) {
                $row = $this->db->table(Tables::name('webhook_deliveries'))->where('id', $id)->lockForUpdate()->first();
                if (! in_array($row->state, ['pending', 'retry', 'sending'], true) || ($row->lease_until !== null && $row->lease_until > $now) || ($row->next_attempt_at !== null && $row->next_attempt_at > $now)) {
                    return null;
                }$endpoint = $this->db->table(Tables::name('webhook_endpoints'))->where('id', $row->endpoint_id)->first();
                if (! $endpoint->active || $endpoint->version !== $row->endpoint_version) {
                    $this->db->table(Tables::name('webhook_deliveries'))->where('id', $id)->update(['state' => 'cancelled', 'reason_code' => 'endpoint_changed', 'version' => $row->version + 1]);

                    return null;
                }$this->db->table(Tables::name('webhook_deliveries'))->where('id', $id)->update(['state' => 'sending', 'attempts' => $row->attempts + 1, 'lease_token' => $token, 'lease_until' => LocalTime::stamp($this->clock->now()->modify('+30 seconds')), 'version' => $row->version + 1]);

                return ['delivery' => (array) $row, 'endpoint' => (array) $endpoint];
            }, 3);
            if ($claim === null) {
                continue;
            }$state = 'delivered';
            $reason = null;
            $status = null;
            try {
                $endpoint = $claim['endpoint'];
                $event = $this->db->table(Tables::name('outbox'))->where('scope_key', $context->scope)->where('event_id', $claim['delivery']['event_id'])->first();
                if (! $event) {
                    throw new DomainError('not_found');
                }$this->access->source($context, (array) $event);
                $target = $this->urls->validate($endpoint['url']);
                $body = json_encode($this->access->envelope((array) $event), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                if (strlen($body) > 262144) {
                    throw new DomainError('webhook_payload_limit');
                }$timestamp = (string) $this->clock->now()->getTimestamp();
                $signature = WebhookSignature::sign($this->secret($context, $endpoint['secret_ref']), $timestamp, $event->event_id, $body);
                $this->access->source($context, (array) $event);
                $response = $this->transport->post($target, $body, ['Content-Type: application/json', 'X-TaskManager-Event: '.$event->event_id, 'X-TaskManager-Timestamp: '.$timestamp, 'X-TaskManager-Signature: '.$signature]);
                $status = $response['status'] ?? 0;
                if (! is_int($status) || $status < 0 || $status > 599) {
                    $status = null;
                    throw new DomainError('webhook_response_invalid');
                }if (($response['redirect'] ?? false) || ($status >= 300 && $status < 400)) {
                    throw new DomainError('webhook_redirect_blocked');
                }if (! is_int($status) || $status < 200 || $status >= 300) {
                    throw new DomainError('webhook_http_failed');
                }
            } catch (\Throwable $error) {
                $reason = $error instanceof DomainError && preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $error->getMessage()) ? $error->getMessage() : 'webhook_transport_failed';
                $state = in_array($reason, ['not_found', 'access_denied', 'capability_denied', 'event_source_not_subscribable'], true) ? 'cancelled' : ($claim['delivery']['attempts'] + 1 >= 5 ? 'dead' : 'retry');
            }
            $attempt = $claim['delivery']['attempts'] + 1;
            $delay = [5, 30, 120, 600, 3600][min($attempt - 1, 4)];
            $changed = $this->db->table(Tables::name('webhook_deliveries'))->where('id', $id)->where('state', 'sending')->where('lease_token', $token)->where('lease_until', '>', LocalTime::stamp($this->clock->now()))->update(['state' => $state, 'reason_code' => $reason, 'response_status' => $status, 'next_attempt_at' => $state === 'retry' ? LocalTime::stamp($this->clock->now()->modify('+'.$delay.' seconds')) : null, 'lease_token' => null, 'lease_until' => null]);
            if ($changed) {
                $result[$state]++;
            }
        }

        return $result;
    }

    public function retry(ExecutionContext $context, int $deliveryId, int $version, string $operation): array
    {
        $context->validateTenant();
        $this->authorize($context);

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'webhook_retry', 'id' => $deliveryId, 'version' => $version], function () use ($context, $deliveryId, $version, $operation) {
            $row = $this->db->table(Tables::name('webhook_deliveries'))->where('id', $deliveryId)->whereIn('endpoint_id', $this->db->table(Tables::name('webhook_endpoints'))->where('scope_key', $context->scope)->where('owner_ref', $context->actor)->select('id'))->lockForUpdate()->first();
            if (! $row) {
                throw new DomainError('not_found');
            }if ($row->version !== $version) {
                throw new DomainError('version_conflict');
            }if (! in_array($row->state, ['dead', 'cancelled'], true)) {
                throw new DomainError('webhook_retry_invalid');
            }$endpoint = $this->db->table(Tables::name('webhook_endpoints'))->where('id', $row->endpoint_id)->first();
            $event = $this->db->table(Tables::name('outbox'))->where('event_id', $row->event_id)->where('scope_key', $context->scope)->first();
            $this->access->source($context, (array) $event);
            $this->db->table(Tables::name('webhook_deliveries'))->where('id', $deliveryId)->update(['state' => 'pending', 'attempts' => 0, 'version' => $version + 1, 'endpoint_version' => $endpoint->version, 'next_attempt_at' => null, 'reason_code' => null]);
            (new EventRecorder($this->db, $this->clock))->append($context, 'webhook_endpoint', $endpoint->endpoint_key, 'webhook_retried', $operation, ['delivery_id' => $deliveryId]);

            return ['delivery_id' => $deliveryId, 'version' => $version + 1];
        }, function ($result) use ($context) {
            if (! $this->db->table(Tables::name('webhook_deliveries'))->where('id', $result['delivery_id'])->whereIn('endpoint_id', $this->db->table(Tables::name('webhook_endpoints'))->where('scope_key', $context->scope)->where('owner_ref', $context->actor)->select('id'))->exists()) {
                throw new DomainError('not_found');
            }
        });
    }

    public function verifyIncoming(ExecutionContext $context, string $key, string $timestamp, string $eventId, string $body, string $signature): bool
    {
        $context->validateTenant();
        $endpoint = $this->get($context, $key);
        WebhookSignature::verify($this->secret($context, $endpoint['secret_ref']), $timestamp, $eventId, $body, $signature, $this->clock->now());
        $data = json_decode($body, true);
        if (! is_array($data) || ($data['scope_key'] ?? null) !== $context->scope || ($data['event_id'] ?? null) !== $eventId || ($data['schema_version'] ?? null) !== 1) {
            throw new DomainError('webhook_scope_or_schema_invalid');
        }

        return (bool) $this->db->table(Tables::name('webhook_receipts'))->insertOrIgnore(['endpoint_id' => $endpoint['id'], 'event_id' => $eventId, 'received_at' => LocalTime::stamp($this->clock->now())]);
    }
}
