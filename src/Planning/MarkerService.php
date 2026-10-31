<?php

namespace Karnoweb\TaskManager\Planning;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Schema\Tables;
use Karnoweb\TaskManager\TaskGraph\ScopeGraphLock;
use Karnoweb\TaskManager\TaskService;
use Karnoweb\TaskManager\Workflow\ProjectService;
use Karnoweb\TaskManager\Workflow\StateIndex;

final class MarkerService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly ProjectService $projects)
    {
        Tables::assertConnection($db);
    }

    private function authorize(ExecutionContext $context, string $kind): void
    {
        $capability = match ($kind) {
            'milestone' => 'milestone.manage', 'release' => 'release.manage', default => throw new DomainError('marker_kind_invalid')
        };
        $context->require($capability);
        $this->authorization->authorize($context, $capability);
    }

    public function create(ExecutionContext $context, string $projectKey, string $kind, string $name, ?\DateTimeImmutable $due, string $operation): array
    {
        $context->validateTenant();
        $this->authorize($context, $kind);
        if (trim($name) === '' || mb_strlen($name) > 255) {
            throw new DomainError('marker_invalid');
        }
        $date = $due?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'marker_create', 'project' => $projectKey, 'kind' => $kind, 'name' => $name, 'due' => $date], function () use ($context, $projectKey, $kind, $name, $date, $operation) {
            ScopeGraphLock::acquire($this->db, $context);
            $project = $this->projects->get($context, $projectKey, 'edit');
            if ($project['status'] !== 'active') {
                throw new DomainError('project_archived');
            }
            $key = (string) Str::uuid();
            $this->db->table(Tables::name('project_markers'))->insert(['marker_key' => $key, 'scope_key' => $context->scope, 'project_id' => $project['id'], 'kind' => $kind, 'name' => $name, 'due_at' => $date]);
            (new EventRecorder($this->db, $this->clock))->append($context, $kind, $key, 'marker_created', $operation, ['kind' => $kind]);

            return $this->get($context, $key);
        }, fn ($result) => $this->get($context, $result['marker_key']));
    }

    public function get(ExecutionContext $context, string $key, bool $write = false): array
    {
        $context->validateTenant();
        if (! Str::isUuid($key)) {
            throw new DomainError('not_found');
        } $row = $this->db->table(Tables::name('project_markers'))->where('scope_key', $context->scope)->where('marker_key', $key)->first();
        if (! $row) {
            throw new DomainError('not_found');
        }
        if ($write) {
            $this->authorize($context, $row->kind);
        }
        $projectKey = $this->db->table(Tables::name('projects'))->where('id', $row->project_id)->value('project_key');
        $this->projects->get($context, $projectKey, $write ? 'edit' : 'view');

        return (array) $row;
    }

    public function link(ExecutionContext $context, string $key, int $version, string $taskKey, int $taskVersion, bool $enabled, string $operation): array
    {
        $context->validateTenant();

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'marker_link', 'marker' => $key, 'version' => $version, 'task' => $taskKey, 'task_version' => $taskVersion, 'enabled' => $enabled], function () use ($context, $key, $version, $taskKey, $taskVersion, $enabled, $operation) {
            ScopeGraphLock::acquire($this->db, $context);
            $marker = $this->get($context, $key, true);
            if ($marker['version'] !== $version) {
                throw new DomainError('version_conflict');
            }
            $task = $this->tasks->authorizedQuery($context, 'edit')->where('public_key', $taskKey)->whereNull('deleted_at')->lockForUpdate()->first();
            if (! $task) {
                throw new DomainError('not_found');
            }
            if ($task->version !== $taskVersion) {
                throw new DomainError('version_conflict');
            } if ($task->project_id !== $marker['project_id']) {
                throw new DomainError('marker_project_mismatch');
            }
            $this->db->table(Tables::name('marker_tasks'))->where('marker_id', $marker['id'])->where('task_id', $task->id)->delete();
            if ($enabled) {
                $this->db->table(Tables::name('marker_tasks'))->insert(['marker_id' => $marker['id'], 'task_id' => $task->id]);
            }
            $this->db->table(Tables::name('project_markers'))->where('id', $marker['id'])->increment('version');
            $this->db->table(Tables::name('tasks'))->where('id', $task->id)->increment('version');
            (new EventRecorder($this->db, $this->clock))->append($context, 'task', $taskKey, 'marker_link_changed', $operation, ['marker_key' => $key, 'enabled' => $enabled], $task->id);

            return $this->get($context, $key);
        }, fn ($result) => $this->get($context, $result['marker_key'], true));
    }

    public function progress(ExecutionContext $context, string $key): array
    {
        $context->validateTenant();
        $marker = $this->get($context, $key);
        $query = $this->tasks->authorizedQuery($context)->where('project_id', $marker['project_id'])->whereNull('deleted_at')->whereIn('id', $this->db->table(Tables::name('marker_tasks'))->where('marker_id', $marker['id'])->select('task_id'));
        $total = (clone $query)->count();
        $done = (clone $query)->whereIn('id', StateIndex::currentTaskIds($this->db, $context->scope, ['done']))->count();

        return ['marker_key' => $key, 'kind' => $marker['kind'], 'due_at' => $marker['due_at'], 'total' => $total, 'done' => $done, 'percent' => $total === 0 ? 0.0 : round(100 * $done / $total, 2)];
    }

    public function change(ExecutionContext $context, string $key, int $version, string $name, ?\DateTimeImmutable $due, string $operation): array
    {
        $context->validateTenant();
        if (trim($name) === '' || mb_strlen($name) > 255) {
            throw new DomainError('marker_invalid');
        } $date = $due?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'marker_change', 'key' => $key, 'version' => $version, 'name' => $name, 'due' => $date], function () use ($context, $key, $version, $name, $date, $operation) {
            ScopeGraphLock::acquire($this->db, $context);
            $marker = $this->get($context, $key, true);
            if ($marker['version'] !== $version) {
                throw new DomainError('version_conflict');
            }
            $this->db->table(Tables::name('project_markers'))->where('id', $marker['id'])->update(['name' => $name, 'due_at' => $date, 'version' => $version + 1]);
            (new EventRecorder($this->db, $this->clock))->append($context, $marker['kind'], $key, 'marker_changed', $operation, ['version' => $version + 1]);

            return $this->get($context, $key);
        }, fn ($result) => $this->get($context, $result['marker_key'], true));
    }
}
