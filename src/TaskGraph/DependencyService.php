<?php

namespace Karnoweb\TaskManager\TaskGraph;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\SLA\CalendarRegistry;
use Karnoweb\TaskManager\TaskService;

final class DependencyService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly CalendarRegistry $calendars) {}
    public function add(ExecutionContext $context, string $predecessor, int $predecessorVersion, string $successor, int $successorVersion, string $type, array $policy, string $operation): array
    {
        return $this->addBatch($context, [['predecessor' => $predecessor, 'predecessor_version' => $predecessorVersion, 'successor' => $successor, 'successor_version' => $successorVersion, 'type' => $type, 'policy' => $policy]], $operation)[0];
    }
    public function addBatch(ExecutionContext $context, array $items, string $operation): array
    {
        $context->require('dependency.manage'); $this->authorization->authorize($context, 'dependency.manage');
        if (!array_is_list($items) || !$items || count($items) > 100) { throw new DomainError('batch_size_invalid'); }
        foreach ($items as &$item) {
            if (!is_array($item) || array_diff(array_keys($item), ['predecessor', 'successor', 'predecessor_version', 'successor_version', 'type', 'policy', 'scope'])
                || !is_string($item['predecessor'] ?? null) || !is_string($item['successor'] ?? null)
                || !is_int($item['predecessor_version'] ?? null) || !is_int($item['successor_version'] ?? null) || !is_string($item['type'] ?? null) || !is_array($item['policy'] ?? null)) { throw new DomainError('dependency_invalid'); }
            if (isset($item['scope']) && $item['scope'] !== $context->scope) { throw new DomainError('scope_mismatch'); }
            unset($item['scope']); $item['policy'] = $this->policy($item['type'], $item['policy']);
        }
        unset($item); usort($items, fn ($a, $b) => [$a['predecessor'], $a['successor'], $a['type']] <=> [$b['predecessor'], $b['successor'], $b['type']]);
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'dependencies_add', 'items' => $items], function () use ($context, $items, $operation) {
            ScopeGraphLock::acquire($this->db, $context); $references = [];
            foreach ($items as $item) {
                foreach (['predecessor', 'successor'] as $side) {
                    if (isset($references[$item[$side]]) && $references[$item[$side]] !== $item[$side.'_version']) { throw new DomainError('version_conflict'); }
                    $references[$item[$side]] = $item[$side.'_version'];
                }
            }
            ksort($references); $tasks = [];
            foreach ($references as $key => $version) {
                $task = $this->tasks->authorizedQuery($context, 'edit')->where('public_key', $key)->lockForUpdate()->first();
                if (!$task) { throw new DomainError('not_found'); }
                if ($task->version !== $version) { throw new DomainError('version_conflict'); }
                if ($task->deleted_at !== null || $task->archived_at !== null) { throw new DomainError('task_inactive'); }
                $tasks[$key] = $task;
            }
            $results = [];
            foreach ($items as $item) {
                $predecessor = $tasks[$item['predecessor']]; $successor = $tasks[$item['successor']];
                if ($predecessor->id === $successor->id) { throw new DomainError('dependency_cycle'); }
                $blocking = in_array($item['type'], ['FS', 'SS', 'FF', 'SF'], true);
                if ($blocking && $this->reaches($context, $successor->id, $predecessor->id)) { throw new DomainError('dependency_cycle'); }
                if ($this->db->table('tm_active_dependencies')->where('predecessor_id', $predecessor->id)->where('successor_id', $successor->id)->where('type', $item['type'])->exists()) { throw new DomainError('dependency_exists'); }
                $key = (string) Str::uuid(); $policy = $item['policy'];
                $id = $this->db->table('tm_task_dependencies')->insertGetId(['public_key' => $key, 'scope_key' => $context->scope, 'predecessor_id' => $predecessor->id, 'successor_id' => $successor->id,
                    'type' => $item['type'], 'blocking' => $blocking, 'lag_minutes' => $policy['lag_minutes'], 'clock' => $policy['clock'], 'calendar_ref' => $policy['calendar'], 'enforcement' => $policy['enforcement'], 'created_at' => $this->now()]);
                $this->db->table('tm_active_dependencies')->insert(['predecessor_id' => $predecessor->id, 'successor_id' => $successor->id, 'type' => $item['type'], 'dependency_id' => $id]);
                (new EventRecorder($this->db, $this->clock))->append($context, 'dependency', $key, 'dependency_added', $operation, ['type' => $item['type'], 'lag_minutes' => $policy['lag_minutes']], $successor->id);
                $results[] = $this->get($context, $key);
            }
            $this->db->table('tm_tasks')->whereIn('id', array_map(fn ($task) => $task->id, $tasks))->increment('version'); ScopeGraphLock::changed($this->db, $context); return $results;
        }, function ($results) use ($context) { foreach ($results as $result) { $this->get($context, $result['public_key'], 'edit'); } });
    }
    public function get(ExecutionContext $context, string $key, string $capability = 'view'): array
    {
        if (!Str::isUuid($key) || !in_array($capability, ['view', 'edit'], true)) { throw new DomainError('not_found'); }
        $row = $this->visible($context, $capability)->where('public_key', $key)->first(); if (!$row) { throw new DomainError('not_found'); }
        return (array) $row;
    }
    public function links(ExecutionContext $context, string $taskKey, int $limit = 100, int $afterId = 0): array
    {
        $task = $this->tasks->get($context, $taskKey);
        if ($limit < 1 || $limit > 250 || $afterId < 0) { throw new DomainError('pagination_invalid'); }
        $query = $this->visible($context)->whereNull('deleted_at')->where(fn ($q) => $q->where('predecessor_id', $task['id'])->orWhere('successor_id', $task['id']));
        $total = (clone $query)->count(); $rows = $query->where('id', '>', $afterId)->orderBy('id')->limit($limit)->get()->map(fn ($row) => (array) $row)->all();
        return ['total' => $total, 'items' => $rows, 'next_after_id' => $rows ? end($rows)['id'] : null];
    }
    public function remove(ExecutionContext $context, string $key, int $version, string $operation): array
    {
        $context->require('dependency.manage'); $this->authorization->authorize($context, 'dependency.manage');
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'dependency_remove', 'key' => $key, 'version' => $version], function () use ($context, $key, $version, $operation) {
            ScopeGraphLock::acquire($this->db, $context); $row = $this->get($context, $key, 'edit');
            if ($row['version'] !== $version) { throw new DomainError('version_conflict'); }
            if ($row['deleted_at'] !== null) { throw new DomainError('dependency_inactive'); }
            $this->db->table('tm_task_dependencies')->where('id', $row['id'])->update(['deleted_at' => $this->now(), 'version' => $version + 1]);
            $this->db->table('tm_active_dependencies')->where('dependency_id', $row['id'])->delete();
            $this->db->table('tm_tasks')->whereIn('id', [$row['predecessor_id'], $row['successor_id']])->increment('version'); ScopeGraphLock::changed($this->db, $context);
            (new EventRecorder($this->db, $this->clock))->append($context, 'dependency', $key, 'dependency_removed', $operation, ['version' => $version + 1], $row['successor_id']);
            return $this->get($context, $key);
        }, fn ($result) => $this->get($context, $result['public_key'], 'edit'));
    }
    private function visible(ExecutionContext $context, string $capability = 'view'): \Illuminate\Database\Query\Builder
    {
        $tasks = $this->tasks->authorizedQuery($context, $capability)->whereNull('deleted_at')->select('id');
        return $this->db->table('tm_task_dependencies')->where('scope_key', $context->scope)->whereIn('predecessor_id', clone $tasks)->whereIn('successor_id', clone $tasks);
    }
    private function reaches(ExecutionContext $context, int $start, int $target): bool
    {
        $frontier = [$start]; $seen = [$start => true];
        while ($frontier) {
            $next = $this->db->table('tm_task_dependencies')->where('scope_key', $context->scope)->where('blocking', true)->whereNull('deleted_at')->whereIn('predecessor_id', $frontier)->distinct()->pluck('successor_id')->all(); $frontier = [];
            foreach ($next as $id) { if ($id === $target) { return true; } if (!isset($seen[$id])) { $seen[$id] = true; $frontier[] = $id; } }
            if (count($seen) > 10000) { throw new DomainError('dependency_graph_limit'); }
        }
        return false;
    }
    private function policy(string $type, array $policy): array
    {
        if (!in_array($type, ['FS', 'SS', 'FF', 'SF', 'relates_to', 'duplicates', 'clones'], true) || array_diff(array_keys($policy), ['lag_minutes', 'clock', 'calendar', 'enforcement'])) { throw new DomainError('dependency_invalid'); }
        $result = ['lag_minutes' => $policy['lag_minutes'] ?? 0, 'clock' => $policy['clock'] ?? 'elapsed', 'calendar' => $policy['calendar'] ?? null, 'enforcement' => $policy['enforcement'] ?? 'strict'];
        if (!is_int($result['lag_minutes']) || abs($result['lag_minutes']) > 525600 || !in_array($result['clock'], ['elapsed', 'business'], true) || !in_array($result['enforcement'], ['strict', 'warning'], true)) { throw new DomainError('dependency_policy_invalid'); }
        if ($result['clock'] === 'business') { if (!is_string($result['calendar']) || strlen($result['calendar']) > 120) { throw new DomainError('calendar_unregistered'); } $this->calendars->get($result['calendar']); }
        elseif ($result['calendar'] !== null) { throw new DomainError('dependency_policy_invalid'); }
        return $result;
    }
    private function now(): string { return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'); }
}
