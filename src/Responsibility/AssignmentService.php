<?php

namespace Karnoweb\TaskManager\Responsibility;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Workflow\InstanceService;
use Karnoweb\TaskManager\Workflow\PipelineService;

final class AssignmentService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization,
        private readonly AssignmentEngine $engine, private readonly InstanceService $instances, private readonly PipelineService $pipelines)
    {
    }

    private function authorize(ExecutionContext $context): void
    {
        $context->require('assignment.manage');
        $this->authorization->authorize($context, 'assignment.manage');
    }

    public function get(ExecutionContext $context, string $key): array
    {
        $instance = $this->instances->get($context, $key);
        $slots = $this->db->table('tm_assignment_slots')->where('instance_id', $instance['id'])->orderBy('slot_key')->get();

        return ['instance_key' => $key, 'owner_ref' => $instance['owner_ref'], 'primary_assignee' => $instance['primary_assignee'], 'version' => $instance['version'], 'lifecycle' => $instance['lifecycle'],
            'secondary' => $slots->filter(fn ($s) => $s->slot_key !== 'primary')->pluck('actor_ref')->values()->all(),
            'history' => $this->db->table('tm_instance_assignments')->where('scope_key', $context->scope)->where('instance_id', $instance['id'])->orderByDesc('id')->limit(100)->get()->map(fn ($r) => (array) $r)->all()];
    }

    public function assign(ExecutionContext $context, string $key, int $version, string $operation, ?string $primary, array $secondary = []): array
    {
        $this->authorize($context);

        return $this->command($context, $operation, ['action' => 'assign', 'key' => $key, 'version' => $version, 'primary' => $primary, 'secondary' => $secondary], function () use ($context, $key, $version, $operation, $primary, $secondary) {
            [$instance] = $this->instances->lockForCommand($context, $key, $version);
            if ($instance['lifecycle'] !== 'active') {
                throw new DomainError('instance_inactive');
            }
            $policy = $this->engine->policy($context, $instance);
            $this->engine->assign($context, $instance, $policy['definition'], $policy['id'], $primary, $secondary, 'manual');
            $this->bump($instance);
            $this->event($context, $instance, 'assignment_changed', $operation, ['before' => $instance['primary_assignee'], 'after' => $primary, 'secondary' => $secondary]);

            return $this->get($context, $key);
        });
    }

    public function configureInstance(ExecutionContext $context, string $key, int $version, string $operation, array $policy): array
    {
        $this->authorize($context);
        $policy = $this->engine->validate($policy);

        return $this->command($context, $operation, ['action' => 'instance_assignment_policy', 'key' => $key, 'version' => $version, 'policy' => $policy], function () use ($context, $key, $version, $operation, $policy) {
            [$instance] = $this->instances->lockForCommand($context, $key, $version);
            if ($instance['lifecycle'] !== 'active') {
                throw new DomainError('instance_inactive');
            }
            $id = $this->db->table('tm_assignment_policies')->insertGetId(['scope_key' => $context->scope, 'definition' => json_encode($policy, JSON_THROW_ON_ERROR)]);
            $primary = $this->engine->resolve($context, $instance, $policy);
            $this->engine->assign($context, $instance, $policy, $id, $primary, $policy['secondary'], 'policy_changed');
            $this->db->table('tm_workflow_instances')->where('id', $instance['id'])->update(['assignment_policy_id' => $id]);
            $this->bump($instance);
            $this->event($context, $instance, 'assignment_policy_changed', $operation, ['policy_id' => $id, 'primary' => $primary]);

            return $this->get($context, $key);
        });
    }

    public function configureStage(ExecutionContext $context, string $pipelineKey, string $stageKey, int $version, string $operation, array $policy): array
    {
        $this->authorize($context);
        $policy = $this->engine->validate($policy);

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'stage_assignment_policy', 'pipeline' => $pipelineKey, 'stage' => $stageKey, 'version' => $version, 'policy' => $policy], function () use ($context, $pipelineKey, $stageKey, $version, $operation, $policy) {
            $pipeline = $this->pipelines->get($context, $pipelineKey);
            $row = $this->db->table('tm_pipelines')->where('id', $pipeline['id'])->lockForUpdate()->first();
            if ($row->version !== $version) {
                throw new DomainError('version_conflict');
            }
            $stage = $this->db->table('tm_pipeline_stages')->where('pipeline_id', $pipeline['id'])->where('stage_key', $stageKey)->lockForUpdate()->first();
            if (! $stage) {
                throw new DomainError('not_found');
            }
            $revision = ($this->db->table('tm_assignment_policies')->where('stage_id', $stage->id)->max('revision') ?? 0) + 1;
            $id = $this->db->table('tm_assignment_policies')->insertGetId(['scope_key' => $context->scope, 'stage_id' => $stage->id, 'revision' => $revision, 'definition' => json_encode($policy, JSON_THROW_ON_ERROR)]);
            $this->db->table('tm_pipeline_stages')->where('id', $stage->id)->update(['assignment_policy_id' => $id]);
            $this->db->table('tm_pipelines')->where('id', $pipeline['id'])->increment('version');
            (new EventRecorder($this->db, $this->clock))->append($context, 'pipeline', $pipelineKey, 'assignment_policy_published', $operation, ['policy_id' => $id, 'stage' => $stageKey]);

            return ['id' => $id, 'pipeline_key' => $pipelineKey, 'revision' => $revision];
        }, fn ($r) => $this->pipelines->get($context, $r['pipeline_key']));
    }

    public function depart(ExecutionContext $context, string $key, int $version, string $operation, string $actor, string $policy): array
    {
        $this->authorize($context);
        if (! in_array($policy, ['reject', 'unassign', 'reassign_owner'], true)) {
            throw new DomainError('departure_policy_invalid');
        }

        return $this->command($context, $operation, ['action' => 'member_departure', 'key' => $key, 'version' => $version, 'actor' => $actor, 'policy' => $policy], function () use ($context, $key, $version, $operation, $actor, $policy) {
            [$instance] = $this->instances->lockForCommand($context, $key, $version);
            $current = $this->get($context, $key);
            if ($instance['lifecycle'] !== 'active') {
                throw new DomainError('instance_inactive');
            }
            $active = $instance['primary_assignee'] === $actor || in_array($actor, $current['secondary'], true);
            if ($active && $policy === 'reject') {
                throw new DomainError('member_has_active_work');
            }
            if (! $active) {
                return $current;
            }
            $definition = $this->engine->policy($context, $instance);
            $primary = $instance['primary_assignee'];
            if ($primary === $actor) {
                $primary = $policy === 'reassign_owner' ? $instance['owner_ref'] : null;
            }
            if ($primary === $actor) {
                throw new DomainError('assignment_unresolvable');
            }
            $secondary = array_values(array_diff($current['secondary'], [$actor, $primary]));
            $this->engine->assign($context, $instance, $definition['definition'], $definition['id'], $primary, $secondary, 'member_departed');
            $this->bump($instance);
            $this->event($context, $instance, 'assignment_member_departed', $operation, ['departed' => $actor, 'policy' => $policy, 'primary' => $primary]);

            return $this->get($context, $key);
        });
    }

    private function bump(array $instance): void
    {
        $this->db->table('tm_workflow_instances')->where('id', $instance['id'])->increment('version');
        if ($instance['task_id'] !== null) {
            $this->db->table('tm_tasks')->where('id', $instance['task_id'])->increment('version');
        }
    }

    private function command(ExecutionContext $context, string $operation, array $payload, callable $execute): array
    {
        return (new CommandExecutor($this->db))->execute($context, $operation, $payload, $execute, fn ($r) => $this->get($context, $r['instance_key']));
    }

    private function event(ExecutionContext $context, array $instance, string $action, string $operation, array $payload): void
    {
        (new EventRecorder($this->db, $this->clock))->append($context, 'instance', $instance['instance_key'], $action, $operation, $payload, $instance['task_id']);
    }
}
