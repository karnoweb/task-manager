<?php

namespace Karnoweb\TaskManager\TaskGraph;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Schema\Tables;

/** Internal transactional projection of the authoritative parent pointer. */
final class HierarchyIndex
{
    public static function root(Connection $db, ExecutionContext $context, int $id): void
    {
        $context->validateTenant();
        $db->table(Tables::name('task_closure'))->insert(['scope_key' => $context->scope, 'ancestor_task_id' => $id, 'descendant_task_id' => $id, 'depth' => 0]);
    }

    public static function move(Connection $db, ExecutionContext $context, int $id, ?int $parentId, int $maxDepth): void
    {
        $context->validateTenant();
        ScopeGraphLock::acquire($db, $context);
        $subtree = $db->table(Tables::name('task_closure'))->where('scope_key', $context->scope)->where('ancestor_task_id', $id)->orderBy('descendant_task_id')->limit(1001)->get(['descendant_task_id', 'depth']);
        if ($subtree->isEmpty()) {
            throw new DomainError('hierarchy_index_missing');
        }
        if ($subtree->count() > 1000) {
            throw new DomainError('subtree_batch_required');
        }
        $ids = $subtree->pluck('descendant_task_id')->all();
        if ($parentId !== null && in_array($parentId, $ids, true)) {
            throw new DomainError('task_hierarchy_cycle');
        }
        $ancestors = $parentId === null ? collect() : $db->table(Tables::name('task_closure'))->where('scope_key', $context->scope)->where('descendant_task_id', $parentId)->get(['ancestor_task_id', 'depth']);
        if ($parentId !== null && $ancestors->isEmpty()) {
            throw new DomainError('hierarchy_index_missing');
        }
        if (($parentId === null ? 0 : (int) $ancestors->max('depth') + 1) + (int) $subtree->max('depth') > $maxDepth) {
            throw new DomainError('task_depth_exceeded');
        }
        $db->table(Tables::name('task_closure'))->where('scope_key', $context->scope)->whereIn('descendant_task_id', $ids)->whereNotIn('ancestor_task_id', $ids)->delete();
        $paths = [];
        foreach ($ancestors as $ancestor) {
            foreach ($subtree as $descendant) {
                $paths[] = ['scope_key' => $context->scope, 'ancestor_task_id' => $ancestor->ancestor_task_id, 'descendant_task_id' => $descendant->descendant_task_id, 'depth' => $ancestor->depth + 1 + $descendant->depth];
                if (count($paths) === 500) {
                    $db->table(Tables::name('task_closure'))->insert($paths);
                    $paths = [];
                }
            }
        }
        if ($paths) {
            $db->table(Tables::name('task_closure'))->insert($paths);
        }
        ScopeGraphLock::changed($db, $context);
    }
}
