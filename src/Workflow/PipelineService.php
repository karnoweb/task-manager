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
use Karnoweb\TaskManager\Schema\Tables;

final class PipelineService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly WorkflowService $workflows)
    {
        Tables::assertConnection($db);
    }

    public function create(ExecutionContext $context, string $name, int $revisionId, array $stages, string $operation, string $transitionMode = 'custom'): array
    {
        $context->validateTenant();
        $context->require('pipeline.manage');
        $this->authorization->authorize($context, 'pipeline.manage');
        if (trim($name) === '' || strlen($name) > 120 || ! $stages || ! in_array($transitionMode, ['custom', 'sequential', 'free'], true)) {
            throw new DomainError('pipeline_invalid');
        }

        if ($transitionMode === 'sequential' && count(array_unique(array_column($stages, 'state'))) !== count($stages)) {
            throw new DomainError('sequential_stage_ambiguous');
        }
        $definition = $this->workflows->revision($context, $revisionId)['definition'];
        foreach ($stages as $key => $stage) {
            Definition::key($key);
            if (! is_array($stage) || ! is_string($stage['state'] ?? null) || ! isset($definition['states'][$stage['state']])
                || (isset($stage['capacity']) && (! is_int($stage['capacity']) || $stage['capacity'] < 1))
                || (isset($stage['label']) && (! is_string($stage['label']) || trim($stage['label']) === '' || strlen($stage['label']) > 120))) {
                throw new DomainError('stage_mapping_invalid');
            }
        }

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'create_pipeline', 'name' => $name, 'revision' => $revisionId, 'stages' => $stages, ...($transitionMode === 'custom' ? [] : ['transition_mode' => $transitionMode])], function () use ($context, $name, $revisionId, $stages, $operation, $transitionMode) {
            $key = (string) Str::uuid();
            $id = $this->db->table(Tables::name('pipelines'))->insertGetId(['pipeline_key' => $key, 'scope_key' => $context->scope, 'name' => $name,
                'owner_ref' => $context->actor, 'workflow_revision_id' => $revisionId, 'transition_mode' => $transitionMode]);
            $position = 0;
            foreach ($stages as $stageKey => $stage) {
                $this->db->table(Tables::name('pipeline_stages'))->insert(['pipeline_id' => $id, 'stage_key' => $stageKey, 'state_key' => $stage['state'], 'capacity' => $stage['capacity'] ?? null,
                    'label' => $stage['label'] ?? null, 'position' => $position++, 'is_active' => true]);
            }
            (new EventRecorder($this->db, $this->clock))->append($context, 'pipeline', $key, 'pipeline_created', $operation, ['revision' => $revisionId]);

            return $this->get($context, $key);
        }, fn ($result) => $this->get($context, $result['pipeline_key']));
    }

    public function get(ExecutionContext $context, string $key): array
    {
        $context->validateTenant();
        if (! Str::isUuid($key)) {
            throw new DomainError('not_found');
        }
        $context->require('workflow.view');
        $this->authorization->authorize($context, 'workflow.view');
        $query = $this->db->table(Tables::name('pipelines'))->where('scope_key', $context->scope)->where('pipeline_key', $key);
        $this->authorization->constrain($query, $context, 'pipeline', 'view');
        $row = $query->first();
        if (! $row) {
            throw new DomainError('not_found');
        }

        return [...(array) $row, 'stages' => $this->db->table(Tables::name('pipeline_stages'))->where('pipeline_id', $row->id)->orderBy('position')->orderBy('id')->get()->map(fn ($s) => (array) $s)->all()];
    }

    /** Visible pipelines of the caller's scope, oldest first, each with its stages. @return list<array<string, mixed>> */
    public function list(ExecutionContext $context): array
    {
        $context->validateTenant();
        $context->require('workflow.view');
        $this->authorization->authorize($context, 'workflow.view');
        $query = $this->db->table(Tables::name('pipelines'))->where('scope_key', $context->scope)->orderBy('id');
        $this->authorization->constrain($query, $context, 'pipeline', 'view');

        return $query->pluck('pipeline_key')->map(fn ($key) => $this->get($context, $key))->all();
    }

    /** Stage administration never edits the workflow revision: a stage is a placement of an existing state. */
    public function addStage(ExecutionContext $context, string $pipelineKey, int $version, string $stageKey, string $state, string $operation, ?string $label = null, ?int $capacity = null): array
    {
        Definition::key($stageKey);
        $this->assertStageLabel($label);
        if ($capacity !== null && $capacity < 1) {
            throw new DomainError('stage_mapping_invalid');
        }

        return $this->administer($context, $pipelineKey, $version, $operation, ['action' => 'add_stage', 'stage' => $stageKey, 'state' => $state, 'label' => $label, 'capacity' => $capacity], function (array $pipeline) use ($stageKey, $state, $label, $capacity) {
            $definition = $this->workflows->revision($pipeline['context'], $pipeline['workflow_revision_id'])['definition'];
            if (! isset($definition['states'][$state])) {
                throw new DomainError('stage_mapping_invalid');
            }
            if ($pipeline['transition_mode'] === 'sequential' && collect($pipeline['stages'])->contains('state_key', $state)) {
                throw new DomainError('sequential_stage_ambiguous');
            }
            if (collect($pipeline['stages'])->contains('stage_key', $stageKey)) {
                throw new DomainError('stage_exists');
            }
            $this->db->table(Tables::name('pipeline_stages'))->insert(['pipeline_id' => $pipeline['id'], 'stage_key' => $stageKey, 'state_key' => $state, 'capacity' => $capacity,
                'label' => $label, 'position' => (int) collect($pipeline['stages'])->max('position') + 1, 'is_active' => true]);
        });
    }

    public function renameStage(ExecutionContext $context, string $pipelineKey, int $version, string $stageKey, string $label, string $operation): array
    {
        $this->assertStageLabel($label);

        return $this->administer($context, $pipelineKey, $version, $operation, ['action' => 'rename_stage', 'stage' => $stageKey, 'label' => $label], function (array $pipeline) use ($stageKey, $label) {
            $this->stage($pipeline, $stageKey);
            $this->db->table(Tables::name('pipeline_stages'))->where('pipeline_id', $pipeline['id'])->where('stage_key', $stageKey)->update(['label' => $label]);
        });
    }

    /** The list must contain every stage exactly once, active or not. */
    public function reorderStages(ExecutionContext $context, string $pipelineKey, int $version, array $stageKeys, string $operation): array
    {
        return $this->administer($context, $pipelineKey, $version, $operation, ['action' => 'reorder_stages', 'stages' => $stageKeys], function (array $pipeline) use ($stageKeys) {
            $actual = array_column($pipeline['stages'], 'stage_key');
            $given = array_values($stageKeys);
            $sorted = $given;
            sort($sorted);
            $expected = $actual;
            sort($expected);
            if (! array_is_list($stageKeys) || $sorted !== $expected) {
                throw new DomainError('stage_order_invalid');
            }
            foreach ($given as $position => $stageKey) {
                $this->db->table(Tables::name('pipeline_stages'))->where('pipeline_id', $pipeline['id'])->where('stage_key', $stageKey)->update(['position' => $position]);
            }
        });
    }

    /** Deactivation hides a stage from new placements. A stage holding instances, or the last active stage of a state, cannot be hidden. */
    public function setStageActive(ExecutionContext $context, string $pipelineKey, int $version, string $stageKey, bool $active, string $operation): array
    {
        return $this->administer($context, $pipelineKey, $version, $operation, ['action' => 'set_stage_active', 'stage' => $stageKey, 'active' => $active], function (array $pipeline) use ($stageKey, $active) {
            $stage = $this->stage($pipeline, $stageKey);
            if (! $active) {
                $this->assertRemovable($pipeline, $stage);
                if ($this->db->table(Tables::name('workflow_instances'))->where('pipeline_id', $pipeline['id'])->where('active_stage_key', $stageKey)->exists()) {
                    throw new DomainError('stage_in_use');
                }
            }
            $this->db->table(Tables::name('pipeline_stages'))->where('id', $stage['id'])->update(['is_active' => $active]);
        });
    }

    /** A stage with any recorded visit is part of process history and is never deleted. */
    public function deleteStage(ExecutionContext $context, string $pipelineKey, int $version, string $stageKey, string $operation): array
    {
        return $this->administer($context, $pipelineKey, $version, $operation, ['action' => 'delete_stage', 'stage' => $stageKey], function (array $pipeline) use ($stageKey) {
            $stage = $this->stage($pipeline, $stageKey);
            $this->assertRemovable($pipeline, $stage);
            if ($this->db->table(Tables::name('stage_visits'))->where('pipeline_id', $pipeline['id'])->where('stage_key', $stageKey)->exists()
                || $this->db->table(Tables::name('workflow_instances'))->where('pipeline_id', $pipeline['id'])->where('active_stage_key', $stageKey)->exists()) {
                throw new DomainError('stage_in_use');
            }
            try {
                $this->db->table(Tables::name('pipeline_stages'))->where('id', $stage['id'])->delete();
            } catch (\Illuminate\Database\QueryException) {
                throw new DomainError('stage_in_use');
            }
        });
    }

    private function assertStageLabel(?string $label): void
    {
        if ($label !== null && (trim($label) === '' || mb_strlen($label) > 120)) {
            throw new DomainError('stage_label_invalid');
        }
    }

    private function stage(array $pipeline, string $stageKey): array
    {
        foreach ($pipeline['stages'] as $stage) {
            if ($stage['stage_key'] === $stageKey) {
                return $stage;
            }
        }

        throw new DomainError('stage_not_found');
    }

    private function assertRemovable(array $pipeline, array $stage): void
    {
        $others = collect($pipeline['stages'])->where('state_key', $stage['state_key'])->where('is_active', true)->where('stage_key', '!=', $stage['stage_key']);
        if ($stage['is_active'] && $others->isEmpty()) {
            throw new DomainError('state_requires_stage');
        }
    }

    /** Shared locked, versioned, idempotent envelope for every stage administration command. */
    private function administer(ExecutionContext $context, string $pipelineKey, int $version, string $operation, array $payload, callable $change): array
    {
        $context->validateTenant();
        $context->require('pipeline.manage');
        $this->authorization->authorize($context, 'pipeline.manage');
        $this->get($context, $pipelineKey);

        return (new CommandExecutor($this->db))->execute($context, $operation, ['pipeline' => $pipelineKey, 'version' => $version, ...$payload], function () use ($context, $pipelineKey, $version, $operation, $payload, $change) {
            $row = $this->db->table(Tables::name('pipelines'))->where('scope_key', $context->scope)->where('pipeline_key', $pipelineKey)->lockForUpdate()->first();
            if (! $row || $row->version !== $version) {
                throw new DomainError($row ? 'version_conflict' : 'not_found');
            }
            if ($row->retired_at !== null) {
                throw new DomainError('pipeline_retired');
            }
            $pipeline = $this->get($context, $pipelineKey);
            $change([...$pipeline, 'context' => $context]);
            $this->db->table(Tables::name('pipelines'))->where('id', $row->id)->update(['version' => $version + 1]);
            (new EventRecorder($this->db, $this->clock))->append($context, 'pipeline', $pipelineKey, 'pipeline_stage_administered', $operation, ['action' => $payload['action'], 'stage' => $payload['stage'] ?? null]);

            return $this->get($context, $pipelineKey);
        }, fn ($result) => $this->get($context, $result['pipeline_key']));
    }
}
