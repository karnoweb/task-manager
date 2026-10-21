<?php

namespace Karnoweb\TaskManager\Planning;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\TaskGraph\ScopeGraphLock;
use Karnoweb\TaskManager\TaskService;

final class BulkService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks)
    {
    }

    public function execute(ExecutionContext $context, array $items, string $mode, string $operation): array
    {
        $context->require('bulk.manage');
        $this->authorization->authorize($context, 'bulk.manage');
        if (! in_array($mode, ['atomic', 'partial'], true) || ! array_is_list($items) || count($items) < 1 || count($items) > 100) {
            throw new DomainError('bulk_invalid');
        }
        $seen = [];
        foreach ($items as $item) {
            if (! is_array($item) || array_diff(array_keys($item), ['task_key', 'version', 'action', 'values']) || ! is_string($item['task_key'] ?? null) || ! is_int($item['version'] ?? null) || ! in_array($item['action'] ?? null, ['update', 'complete', 'reopen', 'archive', 'restore', 'delete', 'undelete'], true) || ! is_array($item['values'] ?? []) || isset($seen[$item['task_key']])) {
                throw new DomainError('bulk_invalid');
            }
            $seen[$item['task_key']] = true;
        }

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'tasks_bulk', 'items' => $items, 'mode' => $mode], function () use ($context, $items, $mode, $operation) {
            ScopeGraphLock::acquire($this->db, $context);
            $results = [];
            foreach ($items as $index => $item) {
                try {
                    $result = $this->db->transaction(fn () => $this->tasks->mutate($context, $item['task_key'], $item['version'], 'bulk:'.hash('sha256', $operation.':'.$index), $item['action'], $item['values'] ?? []));
                    $results[] = ['index' => $index, 'status' => 'completed', 'task_key' => $result['public_key'], 'version' => $result['version']];
                } catch (DomainError $error) {
                    if ($mode === 'atomic') {
                        throw $error;
                    }
                    $reason = preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $error->getMessage()) ? $error->getMessage() : 'host_guard_denied';
                    $results[] = ['index' => $index, 'status' => 'rejected', 'reason_code' => $reason];
                }
            }
            (new EventRecorder($this->db, $this->clock))->append($context, 'bulk_command', $operation, 'bulk_completed', $operation, ['mode' => $mode, 'completed' => count(array_filter($results, fn ($row) => $row['status'] === 'completed')), 'total' => count($items)]);

            return ['mode' => $mode, 'items' => $results];
        }, function ($result) use ($context) {
            foreach ($result['items'] as $item) {
                if ($item['status'] === 'completed' && ! $this->tasks->authorizedQuery($context, 'edit')->where('public_key', $item['task_key'])->exists()) {
                    throw new DomainError('not_found');
                }
            }
        });
    }
}
