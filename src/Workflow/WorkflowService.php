<?php

namespace Karnoweb\TaskManager\Workflow;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Schema\Tables;

final class WorkflowService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization)
    {
        Tables::assertConnection($db);
    }

    public function publish(ExecutionContext $context, string $key, int $expectedRevision, string $operation, array $definition): array
    {
        $context->validateTenant();
        $context->require('workflow.manage');
        $this->authorization->authorize($context, 'workflow.manage');
        Definition::key($key);
        Definition::validate($definition);
        if ($key === 'personal') {
            throw new DomainError('blueprint_reserved');
        }

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'publish_workflow', 'key' => $key, 'expected' => $expectedRevision, 'definition' => $definition], function () use ($context, $key, $expectedRevision, $operation, $definition) {
            $this->db->table(Tables::name('workflow_heads'))->insertOrIgnore(['scope_key' => $context->scope, 'key' => $key]);
            $head = $this->db->table(Tables::name('workflow_heads'))->where('scope_key', $context->scope)->where('key', $key)->lockForUpdate()->first();
            if ($head->revision !== $expectedRevision) {
                throw new DomainError('version_conflict');
            }
            $number = $head->revision + 1;
            $id = $this->db->table(Tables::name('workflow_definitions'))->insertGetId(['scope_key' => $context->scope, 'key' => $key, 'revision' => $number, 'definition' => json_encode($definition, JSON_THROW_ON_ERROR)]);
            StateIndex::publish($this->db, $id, $definition);
            $this->db->table(Tables::name('workflow_heads'))->where('scope_key', $context->scope)->where('key', $key)->update(['revision' => $number]);
            (new EventRecorder($this->db, $this->clock))->append($context, 'workflow', (string) $id, 'workflow_published', $operation, ['revision' => $number, 'key' => $key]);

            return $this->revision($context, $id);
        }, fn ($result) => $this->revision($context, $result['id']));
    }

    /** Latest published revision of a workflow key in the caller's scope, or null when never published. */
    public function current(ExecutionContext $context, string $key): ?array
    {
        $context->validateTenant();
        $context->require('workflow.view');
        $this->authorization->authorize($context, 'workflow.view');
        Definition::key($key);
        $query = $this->db->table(Tables::name('workflow_definitions'))->where('scope_key', $context->scope)->where('key', $key)->orderByDesc('revision');
        $this->authorization->constrain($query, $context, 'workflow', 'view');
        $row = $query->first();

        return $row ? [...(array) $row, 'definition' => Definition::decode($row->definition)] : null;
    }

    public function revision(ExecutionContext $context, int $id): array
    {
        $context->validateTenant();
        $context->require('workflow.view');
        $this->authorization->authorize($context, 'workflow.view');
        $query = $this->db->table(Tables::name('workflow_definitions'))->where('scope_key', $context->scope)->where('id', $id);
        $this->authorization->constrain($query, $context, 'workflow', 'view');
        $row = $query->first();
        if (! $row) {
            throw new DomainError('not_found');
        }

        return [...(array) $row, 'definition' => Definition::decode($row->definition)];
    }
}
