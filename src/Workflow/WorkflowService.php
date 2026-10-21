<?php

namespace Karnoweb\TaskManager\Workflow;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;

final class WorkflowService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization)
    {
    }

    public function publish(ExecutionContext $context, string $key, int $expectedRevision, string $operation, array $definition): array
    {
        $context->require('workflow.manage');
        $this->authorization->authorize($context, 'workflow.manage');
        Definition::key($key);
        Definition::validate($definition);
        if ($key === 'personal') {
            throw new DomainError('blueprint_reserved');
        }

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'publish_workflow', 'key' => $key, 'expected' => $expectedRevision, 'definition' => $definition], function () use ($context, $key, $expectedRevision, $operation, $definition) {
            $this->db->table('tm_workflow_heads')->insertOrIgnore(['scope_key' => $context->scope, 'key' => $key]);
            $head = $this->db->table('tm_workflow_heads')->where('scope_key', $context->scope)->where('key', $key)->lockForUpdate()->first();
            if ($head->revision !== $expectedRevision) {
                throw new DomainError('version_conflict');
            }
            $number = $head->revision + 1;
            $id = $this->db->table('tm_workflow_definitions')->insertGetId(['scope_key' => $context->scope, 'key' => $key, 'revision' => $number, 'definition' => json_encode($definition, JSON_THROW_ON_ERROR)]);
            StateIndex::publish($this->db, $id, $definition);
            $this->db->table('tm_workflow_heads')->where('scope_key', $context->scope)->where('key', $key)->update(['revision' => $number]);
            (new EventRecorder($this->db, $this->clock))->append($context, 'workflow', (string) $id, 'workflow_published', $operation, ['revision' => $number, 'key' => $key]);

            return $this->revision($context, $id);
        }, fn ($result) => $this->revision($context, $result['id']));
    }

    public function revision(ExecutionContext $context, int $id): array
    {
        $context->require('workflow.view');
        $this->authorization->authorize($context, 'workflow.view');
        $query = $this->db->table('tm_workflow_definitions')->where('scope_key', $context->scope)->where('id', $id);
        $this->authorization->constrain($query, $context, 'workflow', 'view');
        $row = $query->first();
        if (! $row) {
            throw new DomainError('not_found');
        }

        return [...(array) $row, 'definition' => Definition::decode($row->definition)];
    }
}
