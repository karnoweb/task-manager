<?php

namespace Karnoweb\TaskManager\Workflow;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\Contracts\EntryRoutingProvider;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Schema\Tables;
use Karnoweb\TaskManager\TaskGraph\ScopeGraphLock;

final readonly class EntryRoutingService
{
    public function __construct(private Connection $db, private Clock $clock, private AuthorizationProvider $authorization, private PipelineService $pipelines, private InstanceService $instances, private EntryRoutingProvider $provider)
    {
        Tables::assertConnection($db);
    }

    private function authorize(ExecutionContext $context): void
    {
        $context->require('pipeline.manage');
        $this->authorization->authorize($context, 'pipeline.manage');
    }

    private function node(ExecutionContext $context, string $key): void
    {
        if (trim($key) === '' || strlen($key) > 120) {
            throw new DomainError('routing_key_invalid');
        }
        $this->provider->authorize($context, $key);
    }

    public function getDefault(ExecutionContext $context, string $routingKey): array
    {
        $context->validateTenant();
        $this->authorize($context);
        $this->node($context, $routingKey);

        return (array) ($this->db->table(Tables::name('entry_routes'))->where('scope_key', $context->scope)->where('routing_key', $routingKey)->first() ?? ['scope_key' => $context->scope, 'routing_key' => $routingKey, 'pipeline_id' => null, 'version' => 0]);
    }

    public function setDefault(ExecutionContext $context, string $routingKey, ?string $pipelineKey, int $version, string $operation): array
    {
        $context->validateTenant();
        $this->authorize($context);
        $this->node($context, $routingKey);

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'set_entry_route', 'node' => $routingKey, 'pipeline' => $pipelineKey, 'version' => $version], function () use ($context, $routingKey, $pipelineKey, $version, $operation) {
            ScopeGraphLock::acquire($this->db, $context);
            $row = $this->getDefault($context, $routingKey);
            if ($row['version'] !== $version) {
                throw new DomainError('version_conflict');
            }
            $pipeline = $pipelineKey === null ? null : $this->pipelines->get($context, $pipelineKey);
            if ($pipeline !== null && $pipeline['retired_at'] !== null) {
                throw new DomainError('pipeline_retired');
            }
            $values = ['pipeline_id' => $pipeline['id'] ?? null, 'version' => $version + 1];
            $this->db->table(Tables::name('entry_routes'))->updateOrInsert(['scope_key' => $context->scope, 'routing_key' => $routingKey], $values);
            (new EventRecorder($this->db, $this->clock))->append($context, 'entry_route', $routingKey, 'entry_route_changed', $operation, ['pipeline' => $values['pipeline_id'], 'version' => $version + 1]);

            return $this->getDefault($context, $routingKey);
        }, fn () => $this->getDefault($context, $routingKey));
    }

    public function resolve(ExecutionContext $context, string $routingKey, bool $parentFallback = true): array
    {
        $context->validateTenant();
        $seen = [];
        $key = $routingKey;
        while ($key !== null) {
            $this->node($context, $key);
            if (isset($seen[$key]) || count($seen) >= 32) {
                throw new DomainError('routing_hierarchy_cycle');
            }
            $seen[$key] = true;
            $id = $this->db->table(Tables::name('entry_routes'))->where('scope_key', $context->scope)->where('routing_key', $key)->value('pipeline_id');
            if ($id !== null) {
                $pipelineKey = $this->db->table(Tables::name('pipelines'))->where('scope_key', $context->scope)->where('id', $id)->whereNull('retired_at')->value('pipeline_key');
                if ($pipelineKey !== null) {
                    $pipeline = $this->pipelines->get($context, $pipelineKey);

                    return ['routing_key' => $key, 'pipeline_key' => $pipelineKey, 'revision_id' => $pipeline['workflow_revision_id']];
                }
            }
            $key = $parentFallback ? $this->provider->parent($context, $key) : null;
        }

        return ['routing_key' => null, 'pipeline_key' => null, 'revision_id' => null];
    }

    public function enter(ExecutionContext $context, string $type, string $subjectId, int $fallbackRevision, string $routingKey, string $operation, bool $parentFallback = true, string $slot = 'main'): array
    {
        $context->validateTenant();
        $this->node($context, $routingKey);

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'route_entry', 'type' => $type, 'subject' => $subjectId, 'revision' => $fallbackRevision, 'routing' => $routingKey, 'parents' => $parentFallback, 'slot' => $slot], function () use ($context, $type, $subjectId, $fallbackRevision, $routingKey, $operation, $parentFallback, $slot) {
            ScopeGraphLock::acquire($this->db, $context);
            $route = $this->resolve($context, $routingKey, $parentFallback);
            $instance = $this->instances->start($context, $type, $subjectId, $route['revision_id'] ?? $fallbackRevision, 'entry:'.hash('sha256', $operation), $slot, $route['pipeline_key']);
            (new EventRecorder($this->db, $this->clock))->append($context, 'instance', $instance['instance_key'], 'entry_route_selected', $operation, ['routing_key' => $route['routing_key'], 'pipeline' => $instance['pipeline_id']], $instance['task_id']);

            return $instance;
        }, fn ($row) => $this->instances->get($context, $row['instance_key']));
    }

    public function retirePipeline(ExecutionContext $context, string $key, int $version, string $operation, ?string $replacementKey = null): array
    {
        $context->validateTenant();
        $this->authorize($context);

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'retire_pipeline', 'key' => $key, 'version' => $version, 'replacement' => $replacementKey], function () use ($context, $key, $version, $operation, $replacementKey) {
            ScopeGraphLock::acquire($this->db, $context);
            $pipeline = $this->pipelines->get($context, $key);
            $locked = $this->db->table(Tables::name('pipelines'))->where('id', $pipeline['id'])->lockForUpdate()->first();
            if ($locked->version !== $version || $locked->retired_at !== null) {
                throw new DomainError('version_conflict');
            }
            $replacement = $replacementKey === null ? null : $this->pipelines->get($context, $replacementKey);
            if ($replacement !== null && ($replacement['id'] === $pipeline['id'] || $replacement['retired_at'] !== null)) {
                throw new DomainError('routing_replacement_invalid');
            }
            foreach ($this->db->table(Tables::name('entry_routes'))->where('scope_key', $context->scope)->where('pipeline_id', $pipeline['id'])->orderBy('routing_key')->cursor() as $route) {
                $this->node($context, $route->routing_key);
                $this->db->table(Tables::name('entry_routes'))->where('scope_key', $context->scope)->where('routing_key', $route->routing_key)->update(['pipeline_id' => $replacement['id'] ?? null, 'version' => $route->version + 1]);
            }
            $this->db->table(Tables::name('pipelines'))->where('id', $pipeline['id'])->update(['retired_at' => $this->clock->now(), 'version' => $version + 1]);
            (new EventRecorder($this->db, $this->clock))->append($context, 'pipeline', $key, 'pipeline_retired', $operation, ['replacement' => $replacement['id'] ?? null]);

            return $this->pipelines->get($context, $key);
        }, fn () => $this->pipelines->get($context, $key));
    }

    /** Resume until visible_remaining is zero; each committed detach has its own journal. */
    public function detachRetired(ExecutionContext $context, string $key, int $limit = 100): array
    {
        $context->validateTenant();
        $this->authorize($context);
        $pipeline = $this->pipelines->get($context, $key);
        if ($pipeline['retired_at'] === null || $limit < 1 || $limit > 100) {
            throw new DomainError('pipeline_retirement_invalid');
        }
        $query = $this->instances->authorizedQuery($context)->where('pipeline_id', $pipeline['id']);
        $rows = (clone $query)->orderBy('id')->limit($limit)->get();
        foreach ($rows as $row) {
            $this->instances->leavePipeline($context, $row->instance_key, $row->version, 'detach:'.hash('sha256', $key.':'.$row->instance_key.':'.$row->version));
        }

        return ['processed' => $rows->count(), 'visible_remaining' => $query->count()];
    }
}
