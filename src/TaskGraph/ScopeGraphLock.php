<?php

namespace Karnoweb\TaskManager\TaskGraph;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\Context\ExecutionContext;

final class ScopeGraphLock
{
    public static function acquire(Connection $db, ExecutionContext $context): int
    {
        if ($db->transactionLevel() < 1) {
            throw new \LogicException('Task graph mutations require a transaction.');
        }
        $db->table('tm_task_graph_locks')->insertOrIgnore(['scope_key' => $context->scope]);

        return (int) $db->table('tm_task_graph_locks')->where('scope_key', $context->scope)->lockForUpdate()->value('version');
    }

    public static function changed(Connection $db, ExecutionContext $context): void
    {
        $db->table('tm_task_graph_locks')->where('scope_key', $context->scope)->increment('version');
    }
}
