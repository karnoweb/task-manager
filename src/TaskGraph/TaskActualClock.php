<?php

namespace Karnoweb\TaskManager\TaskGraph;

use Illuminate\Database\Connection;

final class TaskActualClock
{
    public static function current(Connection $db, string $scope, int $taskId): array
    {
        $instance = $db->table('tm_workflow_instances')->where('scope_key', $scope)->where('task_id', $taskId)->where('slot_key', 'main')->orderByDesc('id')->first();
        if (! $instance) {
            return ['started_at' => null, 'finished_at' => null, 'category' => 'unknown', 'cycle_after_id' => 0];
        }
        $category = $db->table('tm_workflow_states')->where('workflow_revision_id', $instance->workflow_revision_id)->where('state_key', $instance->current_state_key)->value('category') ?? 'unknown';
        $base = $db->table('tm_instance_transitions')->where('scope_key', $scope)->where('instance_id', $instance->id);
        $reset = (clone $base)->where(fn ($q) => $q->where('to_category', 'todo')->orWhere(fn ($r) => $r->whereIn('from_category', ['done', 'cancelled'])->where('to_category', 'in_progress')))->orderByDesc('id')->first();
        $after = $reset->id ?? 0;
        $start = ($reset->to_category ?? null) === 'in_progress' ? $reset->occurred_at : (clone $base)->where('id', '>', $after)->where(fn ($q) => $q->where('from_category', 'todo')->orWhere('from_state', ''))->whereIn('to_category', ['in_progress', 'done', 'cancelled'])->orderBy('id')->value('occurred_at');
        $finish = in_array($category, ['done', 'cancelled'], true) ? (clone $base)->where('id', '>', $after)->whereNotIn('from_category', ['done', 'cancelled'])->whereIn('to_category', ['done', 'cancelled'])->orderByDesc('id')->value('occurred_at') : null;

        return ['started_at' => $start, 'finished_at' => $finish, 'category' => $category, 'cycle_after_id' => $after];
    }
}
