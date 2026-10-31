<?php

namespace Karnoweb\TaskManager\TaskGraph;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\Contracts\StageLifecycle;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Scheduling\ReminderInvalidator;
use Karnoweb\TaskManager\Schema\Tables;
use Karnoweb\TaskManager\SLA\CalendarRegistry;

final class NativeTaskRules implements StageLifecycle
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly CalendarRegistry $calendars)
    {
        Tables::assertConnection($db);
    }

    public function activated(ExecutionContext $context, int $instanceId, string $operationId): void
    {
        $context->validateTenant();
    }

    public function entered(ExecutionContext $context, int $instanceId, int $visitId, object $stage, string $operationId): void
    {
        $context->validateTenant();
    }

    public function leaving(ExecutionContext $context, int $instanceId): void
    {
        $context->validateTenant();
    }

    public function activity(ExecutionContext $context, int $instanceId): void
    {
        $context->validateTenant();
    }

    public function beforeTransition(ExecutionContext $context, array $instance, array $edge, array $payload): void
    {
        $context->validateTenant();
        if ($instance['subject_type'] !== 'task' || $instance['slot_key'] !== 'main') {
            return;
        }
        $toRevision = $edge['target_revision_id'] ?? $instance['workflow_revision_id'];
        $to = $this->db->table(Tables::name('workflow_states'))->where('workflow_revision_id', $toRevision)->where('state_key', $edge['to'])->value('category');
        $from = $this->db->table(Tables::name('workflow_states'))->where('workflow_revision_id', $instance['workflow_revision_id'])->where('state_key', $instance['current_state_key'])->value('category');
        if ($to === null || $from === null) {
            throw new DomainError('workflow_category_unknown');
        }
        $starting = (($edge['starting_instance'] ?? false) && $to === 'in_progress') || ($from === 'todo' && in_array($to, ['in_progress', 'done', 'cancelled'], true)) || (in_array($from, ['done', 'cancelled'], true) && $to === 'in_progress');
        $finishing = in_array($to, ['done', 'cancelled'], true) && ! in_array($from, ['done', 'cancelled'], true);
        if (! $starting && ! $finishing) {
            return;
        }
        ScopeGraphLock::acquire($this->db, $context);
        if ($finishing) {
            ReminderInvalidator::cancelTask($this->db, $instance['task_id']);
            $children = $this->db->table(Tables::name('task_closure'))->where('scope_key', $context->scope)->where('ancestor_task_id', $instance['task_id'])->where('depth', '>', 0)->select('descendant_task_id');
            $childInstances = $this->db->table(Tables::name('workflow_instances'))->where('scope_key', $context->scope)->whereIn('task_id', $children)->select('id');
            if ($this->db->table(Tables::name('active_approval_steps'))->whereIn('instance_id', $childInstances)->exists()) {
                throw new DomainError('child_approval_pending');
            }
        }
        $links = $this->db->table(Tables::name('task_dependencies'))->where('scope_key', $context->scope)->where('successor_id', $instance['task_id'])->where('blocking', true)->whereNull('deleted_at')->orderBy('id')->limit(1001)->get();
        if ($links->count() > 1000) {
            throw new DomainError('dependency_batch_required');
        }
        foreach ($links as $link) {
            if (! (($starting && in_array($link->type, ['FS', 'SS'], true)) || ($finishing && in_array($link->type, ['FF', 'SF'], true)))) {
                continue;
            }
            $pred = $this->db->table(Tables::name('tasks'))->where('scope_key', $context->scope)->where('id', $link->predecessor_id)->whereNull('deleted_at')->first();
            $actual = $pred ? TaskActualClock::current($this->db, $context->scope, $pred->id) : [];
            $anchor = $actual[in_array($link->type, ['FS', 'FF'], true) ? 'finished_at' : 'started_at'] ?? null;
            $error = $anchor === null ? 'dependency_date_missing' : null;
            if ($anchor !== null) {
                $earliest = CalendarLag::shift(new \DateTimeImmutable($anchor, new \DateTimeZone('UTC')), $link->lag_minutes, $link->clock, $link->calendar_ref, $this->calendars);
                if ($this->clock->now() < $earliest) {
                    $error = 'dependency_blocked';
                }
            }
            if ($error === null) {
                continue;
            }
            if ($link->enforcement === 'strict') {
                throw new DomainError($error);
            }
            (new EventRecorder($this->db, $this->clock))->append($context, 'task', $instance['subject_id'], 'dependency_warning', 'dependency-warning:'.hash('sha256', $instance['instance_key'].':'.$instance['version'].':'.$link->id),
                ['type' => $link->type, 'reason_code' => $error], $instance['task_id']);
        }
    }
}
