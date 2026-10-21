<?php

namespace Karnoweb\TaskManager\Workflow;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;

final class PipelineService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly WorkflowService $workflows)
    {
    }

    public function create(ExecutionContext $context, string $name, int $revisionId, array $stages, string $operation): array
    {
        $context->require('pipeline.manage');
        $this->authorization->authorize($context, 'pipeline.manage');
        if (trim($name) === '' || strlen($name) > 120 || ! $stages) {
            throw new DomainError('pipeline_invalid');
        }
        $definition = $this->workflows->revision($context, $revisionId)['definition'];
        foreach ($stages as $key => $stage) {
            Definition::key($key);
            if (! is_array($stage) || ! is_string($stage['state'] ?? null) || ! isset($definition['states'][$stage['state']])
                || (isset($stage['capacity']) && (! is_int($stage['capacity']) || $stage['capacity'] < 1))) {
                throw new DomainError('stage_mapping_invalid');
            }
        }

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'create_pipeline', 'name' => $name, 'revision' => $revisionId, 'stages' => $stages], function () use ($context, $name, $revisionId, $stages, $operation) {
            $key = (string) Str::uuid();
            $id = $this->db->table('tm_pipelines')->insertGetId(['pipeline_key' => $key, 'scope_key' => $context->scope, 'name' => $name,
                'owner_ref' => $context->actor, 'workflow_revision_id' => $revisionId]);
            foreach ($stages as $stageKey => $stage) {
                $this->db->table('tm_pipeline_stages')->insert(['pipeline_id' => $id, 'stage_key' => $stageKey, 'state_key' => $stage['state'], 'capacity' => $stage['capacity'] ?? null]);
            }
            (new EventRecorder($this->db, $this->clock))->append($context, 'pipeline', $key, 'pipeline_created', $operation, ['revision' => $revisionId]);

            return $this->get($context, $key);
        }, fn ($result) => $this->get($context, $result['pipeline_key']));
    }

    public function get(ExecutionContext $context, string $key): array
    {
        if (! Str::isUuid($key)) {
            throw new DomainError('not_found');
        }
        $context->require('workflow.view');
        $this->authorization->authorize($context, 'workflow.view');
        $query = $this->db->table('tm_pipelines')->where('scope_key', $context->scope)->where('pipeline_key', $key);
        $this->authorization->constrain($query, $context, 'pipeline', 'view');
        $row = $query->first();
        if (! $row) {
            throw new DomainError('not_found');
        }

        return [...(array) $row, 'stages' => $this->db->table('tm_pipeline_stages')->where('pipeline_id', $row->id)->orderBy('id')->get()->map(fn ($s) => (array) $s)->all()];
    }
}
