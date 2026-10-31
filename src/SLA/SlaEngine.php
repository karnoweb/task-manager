<?php

namespace Karnoweb\TaskManager\SLA;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\Contracts\StageLifecycle;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Schema\Tables;

final class SlaEngine implements StageLifecycle
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly CalendarRegistry $calendars)
    {
        Tables::assertConnection($db);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }

    public function activated(ExecutionContext $context, int $instanceId, string $operationId): void
    {
        $context->validateTenant();
    }

    public function date(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    public function format(\DateTimeImmutable $value): string
    {
        return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function policy(object $visit): array
    {
        $row = $this->db->table(Tables::name('stage_policies'))->where('id', $visit->policy_id)->where('scope_key', $visit->scope_key)->first();
        if (! $row) {
            throw new DomainError('sla_policy_missing');
        }

        return json_decode($row->sla, true, flags: JSON_THROW_ON_ERROR);
    }

    public function add(\DateTimeImmutable $start, int $seconds, array $policy): \DateTimeImmutable
    {
        return $policy['clock_kind'] === 'business' ? $this->calendars->get($policy['calendar_ref'])->addSeconds($start, $seconds) : $start->modify('+'.$seconds.' seconds');
    }

    public function seconds(\DateTimeImmutable $start, \DateTimeImmutable $end, array $policy): int
    {
        if ($end < $start) {
            throw new DomainError('clock_moved_backwards');
        }

        return $policy['clock_kind'] === 'business' ? $this->calendars->get($policy['calendar_ref'])->secondsBetween($start, $end) : $end->getTimestamp() - $start->getTimestamp();
    }

    public function worked(object $visit, array $policy, ?\DateTimeImmutable $until = null, bool $sinceAnchor = false): int
    {
        $until ??= $this->now();
        $start = $this->date($sinceAnchor ? ($visit->anchor_at ?? $visit->entered_at) : $visit->entered_at);
        $seconds = $this->seconds($start, $until, $policy);
        foreach ($this->db->table(Tables::name('sla_pauses'))->where('visit_id', $visit->id)->get() as $pause) {
            $from = $this->date($pause->started_at);
            $to = $pause->ended_at === null ? $until : min($until, $this->date($pause->ended_at));
            if ($from < $until && $to > $start) {
                $seconds -= $this->seconds(max($start, $from), $to, $policy);
            }
        }

        return max(0, $seconds);
    }

    public function entered(ExecutionContext $context, int $instanceId, int $visitId, object $stage, string $operationId): void
    {
        $context->validateTenant();
        if ($stage->policy_id === null) {
            return;
        }
        $row = $this->db->table(Tables::name('stage_policies'))->where('id', $stage->policy_id)->where('scope_key', $context->scope)->first();
        if (! $row) {
            throw new DomainError('sla_policy_missing');
        } $policy = json_decode($row->sla, true, flags: JSON_THROW_ON_ERROR);
        $consumed = $policy['reentry'] === 'cumulative' ? (int) $this->db->table(Tables::name('stage_visits'))->where('instance_id', $instanceId)->where('pipeline_id', $stage->pipeline_id)->where('stage_key', $stage->stage_key)->whereNotNull('exited_at')->sum($policy['clock_kind'] === 'business' ? 'business_seconds' : 'elapsed_seconds') : 0;
        $remaining = max(0, $policy['duration_seconds'] - $consumed);
        $now = $this->now();
        $this->db->table(Tables::name('stage_visits'))->where('id', $visitId)->update(['policy_id' => $row->id, 'deadline_at' => $this->format($this->add($now, $remaining, $policy)),
            'warning_at' => $policy['warning_before_seconds'] > 0 ? $this->format($this->add($now, max(0, $remaining - $policy['warning_before_seconds']), $policy)) : null,
            'stale_at' => $policy['stale_after_seconds'] !== null ? $this->format($now->modify('+'.$policy['stale_after_seconds'].' seconds')) : null,
            'last_activity_at' => $this->format($now), 'anchor_at' => $this->format($now), 'remaining_seconds' => $remaining, 'budget_seconds' => $remaining]);
    }

    public function leaving(ExecutionContext $context, int $instanceId): void
    {
        $context->validateTenant();
        $visit = $this->db->table(Tables::name('stage_visits'))->where('scope_key', $context->scope)->where('instance_id', $instanceId)->whereNull('exited_at')->first();
        if (! $visit || $visit->policy_id === null) {
            return;
        }
        $policy = $this->policy($visit);
        $now = $this->now();
        $elapsed = $this->worked($visit, ['clock_kind' => 'elapsed'], $now);
        $business = $policy['clock_kind'] === 'business' ? $this->worked($visit, $policy, $now) : 0;
        $changes = ['elapsed_seconds' => $elapsed, 'business_seconds' => $business, 'sla_state' => 'resolved', 'sla_version' => $visit->sla_version + 1];
        if ($visit->paused_at !== null) {
            $pauseSeconds = $now->getTimestamp() - $this->date($visit->paused_at)->getTimestamp();
            if ($pauseSeconds < 0) {
                throw new DomainError('clock_moved_backwards');
            }
            $changes['paused_seconds'] = $visit->paused_seconds + $pauseSeconds;
            $changes['paused_at'] = null;
            $this->db->table(Tables::name('sla_pauses'))->where('visit_id', $visit->id)->whereNull('ended_at')->update(['ended_at' => $this->format($now)]);
        }
        $this->db->table(Tables::name('stage_visits'))->where('id', $visit->id)->update($changes);
    }

    public function activity(ExecutionContext $context, int $instanceId): void
    {
        $context->validateTenant();
        $visit = $this->db->table(Tables::name('stage_visits'))->where('scope_key', $context->scope)->where('instance_id', $instanceId)->whereNull('exited_at')->first();
        if (! $visit || $visit->policy_id === null || $visit->paused_at !== null) {
            return;
        }
        $policy = $this->policy($visit);
        $this->db->table(Tables::name('stage_visits'))->where('id', $visit->id)->update(['last_activity_at' => $this->format($this->now()),
            'stale_at' => $policy['stale_after_seconds'] !== null ? $this->format($this->now()->modify('+'.$policy['stale_after_seconds'].' seconds')) : null,
            'stale_detected_at' => null, 'dead_candidate_at' => null, 'stale_generation' => $visit->stale_generation + 1,
            'sla_version' => $visit->sla_version + 1,
            'sla_state' => $visit->breached_at !== null ? 'breached' : ($visit->warned_at !== null ? 'warning' : 'healthy')]);
    }

    public function beforeTransition(ExecutionContext $context, array $instance, array $edge, array $payload): void
    {
        $context->validateTenant();
    }
}
