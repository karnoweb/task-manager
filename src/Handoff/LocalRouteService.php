<?php

namespace Karnoweb\TaskManager\Handoff;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Workflow\CaseService;
use Karnoweb\TaskManager\Workflow\Definition;
use Karnoweb\TaskManager\Workflow\InstanceService;

/** Host-owned same-connection move/spawn with actual atomic commit. */
final class LocalRouteService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization,
        private readonly InstanceService $instances, private readonly CaseService $cases, private readonly SubjectRouteRegistry $routes)
    {
    }

    private function authorize(ExecutionContext $context): void
    {
        $context->require('handoff.manage');
        $this->authorization->authorize($context, 'handoff.manage');
    }

    public function move(ExecutionContext $context, string $route, array $preview, string $reason, string $operation): array
    {
        $this->authorize($context);
        Definition::key($reason);
        $handler = $this->routes->local($route);
        if ($handler->connection() !== $this->db) {
            throw new DomainError('same_connection_required');
        }

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'owned_move', 'route' => $route, 'preview' => $preview, 'reason' => $reason], function () use ($context, $handler, $route, $preview, $reason, $operation) {
            [$origin] = $this->instances->lockForCommand($context, $preview['instance_key'], $preview['instance_version']);
            if ($origin['lifecycle'] !== 'active') {
                throw new DomainError('instance_inactive');
            }
            $handler->move($context, $origin, $preview['pipeline_key'], $this->child($operation, 'owner'));
            $result = $this->instances->moveToPipeline($context, $preview, $this->child($operation, 'process'));
            (new EventRecorder($this->db, $this->clock))->append($context, 'instance', $origin['instance_key'], 'subject_routed', $operation,
                ['route' => $route, 'reason_code' => $reason, 'pipeline_key' => $preview['pipeline_key']], $origin['task_id']);

            return $result;
        }, fn ($result) => $this->instances->get($context, $result['instance_key']));
    }

    public function spawn(ExecutionContext $context, string $route, string $originKey, int $version, string $pipelineKey, int $revisionId,
        string $slot, string $sourceTransition, string $reason, string $operation): array
    {
        $this->authorize($context);
        Definition::key($reason);
        $handler = $this->routes->local($route);
        if ($handler->connection() !== $this->db) {
            throw new DomainError('same_connection_required');
        }

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'owned_spawn', 'route' => $route, 'origin' => $originKey, 'version' => $version,
            'pipeline' => $pipelineKey, 'revision' => $revisionId, 'slot' => $slot, 'transition' => $sourceTransition, 'reason' => $reason], function () use ($context, $handler, $route, $originKey, $version, $pipelineKey, $revisionId, $slot, $sourceTransition, $reason, $operation) {
                [$origin] = $this->instances->lockForCommand($context, $originKey, $version);
                if ($origin['lifecycle'] !== 'active') {
                    throw new DomainError('instance_inactive');
                }
                $target = $handler->spawn($context, $origin, $this->child($operation, 'owner'));
                if (array_diff(array_keys($target), ['subject_type', 'subject_id']) || ! is_string($target['subject_type'] ?? null) || ! is_string($target['subject_id'] ?? null)
                    || ($target['subject_type'] === $origin['subject_type'] && $target['subject_id'] === $origin['subject_id'])) {
                    throw new DomainError('spawn_identity_invalid');
                }
                $case = $origin['case_id'] === null ? $this->cases->create($context, 'Subject conversion', $this->child($operation, 'case'))
                    : ['id' => $origin['case_id'], 'case_key' => $this->db->table('tm_process_cases')->where('id', $origin['case_id'])->value('case_key')];
                $destination = $this->instances->start($context, $target['subject_type'], $target['subject_id'], $revisionId, $this->child($operation, 'destination'), $slot, $pipelineKey, caseKey: $case['case_key']);
                $source = $this->instances->transition($context, $originKey, $version, $this->child($operation, 'source'), $sourceTransition, ['reason_code' => $reason]);
                $this->db->table('tm_workflow_instances')->where('id', $origin['id'])->update(['case_id' => $case['id']]);
                $this->db->table('tm_case_subjects')->insertOrIgnore(['case_id' => $case['id'], 'subject_type' => $origin['subject_type'], 'subject_id' => $origin['subject_id']]);
                $id = $this->db->table('tm_subject_conversions')->insertGetId(['scope_key' => $context->scope, 'origin_id' => $origin['id'], 'destination_id' => $destination['id'], 'route_ref' => $route,
                    'reason_code' => $reason, 'operation_id' => $operation, 'created_at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
                (new EventRecorder($this->db, $this->clock))->append($context, 'instance', $originKey, 'subject_spawned', $operation,
                    ['conversion_id' => $id, 'route' => $route, 'destination_key' => $destination['instance_key'], 'reason_code' => $reason], $origin['task_id']);

                return ['conversion_id' => $id, 'origin_key' => $source['instance_key'], 'destination_key' => $destination['instance_key'], 'case_key' => $case['case_key']];
            }, function ($result) use ($context) {
                $this->instances->get($context, $result['origin_key']);
                $this->instances->get($context, $result['destination_key']);
            });
    }

    private function child(string $operation, string $kind): string
    {
        return 'route-'.$kind.':'.hash('sha256', $operation);
    }
}
