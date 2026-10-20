<?php

namespace Karnoweb\TaskManager\Planning;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\TaskService;
use Karnoweb\TaskManager\Workflow\ProjectService;
use Karnoweb\TaskManager\Workflow\StateIndex;

final class ReportingService
{
    public function __construct(private readonly Connection $db, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly ProjectService $projects) {}
    private function authorize(ExecutionContext $context): void { $context->require('report.view'); $this->authorization->authorize($context, 'report.view'); }
    public function totals(ExecutionContext $context, ?string $projectKey = null): array
    {
        $this->authorize($context); $query = $this->tasks->authorizedQuery($context)->whereNull('deleted_at');
        if ($projectKey !== null) { $project = $this->projects->get($context, $projectKey); $query->where('project_id', $project['id']); }
        $allIds = (clone $query)->select('id'); $leaves = (clone $query)->where('type_key', '!=', 'epic')->whereNotIn('id', $this->db->table('tm_tasks')->where('scope_key', $context->scope)->whereNull('deleted_at')->whereNotNull('parent_task_id')->select('parent_task_id'));
        $points = (int) (clone $leaves)->sum('story_points_minor'); $doneLeaves = (clone $leaves)->whereIn('id', StateIndex::currentTaskIds($this->db, $context->scope, ['done']));
        $spent = (int) $this->db->table('tm_task_time_entries')->where('scope_key', $context->scope)->whereNull('deleted_at')->whereIn('task_id', $allIds)->sum('duration_seconds');
        return ['total_tasks' => (clone $query)->count(), 'done_tasks' => (clone $query)->whereIn('id', StateIndex::currentTaskIds($this->db, $context->scope, ['done']))->count(),
            'leaf_tasks' => (clone $leaves)->count(), 'points' => PointValue::format($points), 'done_points' => PointValue::format((int) (clone $doneLeaves)->sum('story_points_minor')),
            'unknown_points' => (clone $leaves)->whereNull('story_points_minor')->count(), 'spent_seconds' => $spent, 'visibility' => 'currently_permitted_tasks'];
    }
    public function subtreeTime(ExecutionContext $context, string $taskKey): array
    {
        $this->authorize($context); $task = $this->tasks->get($context, $taskKey);
        $ids = $this->tasks->authorizedQuery($context)->whereIn('id', $this->db->table('tm_task_closure')->where('scope_key', $context->scope)->where('ancestor_task_id', $task['id'])->select('descendant_task_id'))->select('id');
        return ['spent_seconds' => (int) $this->db->table('tm_task_time_entries')->where('scope_key', $context->scope)->whereNull('deleted_at')->whereIn('task_id', $ids)->sum('duration_seconds'), 'source' => 'distinct_raw_time_entries'];
    }
    public function flow(ExecutionContext $context, string $taskKey, ?\DateTimeImmutable $asOf = null): array
    {
        $this->authorize($context); $task = $this->tasks->get($context, $taskKey);
        $query = $this->db->table('tm_instance_transitions')->where('scope_key', $context->scope)->whereIn('instance_id', $this->db->table('tm_workflow_instances')->where('scope_key', $context->scope)->where('task_id', $task['id'])->where('slot_key', 'main')->select('id'));
        if ($asOf !== null) { $query->where('occurred_at', '<=', $asOf->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u')); }
        $rows = $query->orderBy('occurred_at')->orderBy('id')->limit(5001)->get(); if ($rows->count() > 5000) { throw new DomainError('flow_history_batch_required'); }
        $created = new \DateTimeImmutable($task['created_at'], new \DateTimeZone('UTC')); $start = null; $cycles = []; $unknown = false; $firstDone = null; $firstCycle = null;
        foreach ($rows as $row) {
            $at = new \DateTimeImmutable($row->occurred_at, new \DateTimeZone('UTC'));
            if ($row->to_category === null || ($row->from_category === null && $row->from_state !== '')) { $unknown = true; $start = null; continue; }
            if ($row->to_category === 'todo' || (in_array($row->from_category, ['done', 'cancelled'], true) && $row->to_category === 'in_progress')) { $start = $row->to_category === 'in_progress' ? $at : null; }
            if (($row->from_category === 'todo' || $row->from_state === '') && in_array($row->to_category, ['in_progress', 'done', 'cancelled'], true)) { $start = $row->from_state === '' ? $at : ($start ?? $at); }
            if (in_array($row->to_category, ['done', 'cancelled'], true) && !in_array($row->from_category, ['done', 'cancelled'], true)) {
                $duration = $start === null || $at < $start ? null : $at->getTimestamp() - $start->getTimestamp();
                $cycles[] = ['started_at' => $start?->format(DATE_ATOM), 'finished_at' => $at->format(DATE_ATOM), 'outcome' => $row->to_category, 'cycle_seconds' => $duration];
                if ($row->to_category === 'done' && $firstDone === null) { $firstDone = $at; $firstCycle = $duration; } $start = null;
            }
        }
        $lead = $firstDone === null || $firstDone < $created || $unknown ? null : $firstDone->getTimestamp() - $created->getTimestamp();
        return ['state' => $unknown ? 'insufficient_data' : 'calculated', 'lead_seconds' => $lead, 'cycle_seconds' => $unknown ? null : $firstCycle, 'completed_cycles' => $cycles, 'as_of' => $asOf?->format(DATE_ATOM), 'source' => 'immutable_transition_categories'];
    }
}
