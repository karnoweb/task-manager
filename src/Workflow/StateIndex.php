<?php

namespace Karnoweb\TaskManager\Workflow;

use Illuminate\Database\Connection;

final class StateIndex
{
    public static function taskCategoryQuery(Connection $db, string $scope): \Illuminate\Database\Query\Builder
    {
        return $db->table('tm_workflow_instances as state_instance')->join('tm_workflow_states as state_meta', fn ($join) => $join->on('state_meta.workflow_revision_id', '=', 'state_instance.workflow_revision_id')->on('state_meta.state_key', '=', 'state_instance.current_state_key'))
            ->where('state_instance.scope_key', $scope)->where('state_instance.slot_key', 'main')->whereColumn('state_instance.task_id', 'tm_tasks.id')->orderByDesc('state_instance.id')->limit(1)->select('state_meta.category');
    }
    public static function currentTaskIds(Connection $db, string $scope, array $categories): \Illuminate\Database\Query\Builder
    {
        return $db->table('tm_workflow_instances as state_instance')->join('tm_workflow_states as state_meta', fn ($join) => $join->on('state_meta.workflow_revision_id', '=', 'state_instance.workflow_revision_id')->on('state_meta.state_key', '=', 'state_instance.current_state_key'))
            ->where('state_instance.scope_key', $scope)->where('state_instance.slot_key', 'main')->whereNotNull('state_instance.task_id')->whereIn('state_meta.category', $categories)
            ->whereNotExists(fn ($newer) => $newer->selectRaw('1')->from('tm_workflow_instances as newer_instance')->whereColumn('newer_instance.task_id', 'state_instance.task_id')->whereColumn('newer_instance.scope_key', 'state_instance.scope_key')->where('newer_instance.slot_key', 'main')->whereColumn('newer_instance.id', '>', 'state_instance.id'))->select('state_instance.task_id');
    }
    public static function category(array $definition, string $key): string
    {
        $state = $definition['states'][$key] ?? null;
        if (!is_array($state)) { return 'unknown'; }
        $terminal = $state['terminal'] ?? 'none';
        return $state['category'] ?? ($terminal === 'success' ? 'done' : ($terminal !== 'none' ? 'cancelled' : ($key === $definition['initial'] ? 'todo' : 'in_progress')));
    }
    public static function publish(Connection $db, int $revisionId, array $definition): void
    {
        $rows = [];
        foreach ($definition['states'] as $key => $state) { $rows[] = ['workflow_revision_id' => $revisionId, 'state_key' => $key, 'terminal' => $state['terminal'] ?? 'none', 'category' => self::category($definition, $key)]; }
        foreach (array_chunk($rows, 500) as $chunk) { $db->table('tm_workflow_states')->insertOrIgnore($chunk); }
    }
    public static function transition(Connection $db, int $fromRevision, string $from, int $toRevision, string $to): array
    {
        $source = $db->table('tm_workflow_states')->where('workflow_revision_id', $fromRevision)->where('state_key', $from)->value('category');
        $target = $db->table('tm_workflow_states')->where('workflow_revision_id', $toRevision)->where('state_key', $to)->value('category');
        return ['from_revision_id' => $fromRevision, 'to_revision_id' => $toRevision, 'from_category' => $source, 'to_category' => $target];
    }
}
