<?php

namespace Karnoweb\TaskManager\Handoff;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Schema\Tables;
use Karnoweb\TaskManager\Workflow\CaseService;
use Karnoweb\TaskManager\Workflow\Definition;
use Karnoweb\TaskManager\Workflow\InstanceService;

/** Remote owner effects and local workflow commits are explicitly separate. */
final class ExternalSagaService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization,
        private readonly InstanceService $instances, private readonly CaseService $cases, private readonly SubjectRouteRegistry $routes)
    {
    }

    private function authorize(ExecutionContext $context): void
    {
        $context->require('saga.manage');
        $this->authorization->authorize($context, 'saga.manage');
    }

    private function outsideTransaction(): void
    {
        if ($this->db->transactionLevel() !== 0) {
            throw new DomainError('external_action_requires_committed_boundary');
        }
    }

    public function get(ExecutionContext $context, string $key): array
    {
        $context->validateTenant();
        $this->authorize($context);
        if (! Str::isUuid($key)) {
            throw new DomainError('not_found');
        }
        $row = $this->db->table(Tables::name('external_sagas'))->where('scope_key', $context->scope)->where('saga_key', $key)->first();
        if (! $row) {
            throw new DomainError('not_found');
        }
        $this->origin($context, (array) $row);
        $result = (array) $row;
        unset($result['prepared_payload'], $result['receipt']);

        return $result;
    }

    private function origin(ExecutionContext $context, array $saga): array
    {
        $key = $this->db->table(Tables::name('workflow_instances'))->where('scope_key', $context->scope)->where('id', $saga['origin_id'])->value('instance_key');

        return $this->instances->get($context, $key ?? '');
    }

    public function begin(ExecutionContext $context, string $originKey, int $version, string $route, string $transition, string $reason, string $operation): array
    {
        $context->validateTenant();
        $this->authorize($context);
        $this->outsideTransaction();
        Definition::key($transition);
        Definition::key($reason);
        $handler = $this->routes->remote($route);

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'saga_begin', 'origin' => $originKey, 'version' => $version, 'route' => $route, 'transition' => $transition, 'reason' => $reason], function () use ($context, $originKey, $version, $route, $transition, $reason, $operation, $handler) {
            [$origin] = $this->instances->lockForCommand($context, $originKey, $version);
            if ($origin['lifecycle'] !== 'active') {
                throw new DomainError('instance_inactive');
            }
            if ($this->db->table(Tables::name('active_external_sagas'))->where('origin_id', $origin['id'])->exists()) {
                throw new DomainError('external_action_pending');
            }
            $payload = $handler->prepare($context, $origin);
            if (array_diff(array_keys($payload), ['summary', 'customer_ref', 'source_public_key'])) {
                throw new DomainError('external_export_invalid');
            }
            foreach ($payload as $value) {
                if (! is_string($value) || mb_strlen($value) > 1000) {
                    throw new DomainError('external_export_invalid');
                }
            }
            $key = (string) Str::uuid();
            $id = $this->db->table(Tables::name('external_sagas'))->insertGetId(['saga_key' => $key, 'scope_key' => $context->scope, 'origin_id' => $origin['id'], 'origin_version' => $version,
                'route_ref' => $route, 'source_transition' => $transition, 'reason_code' => $reason, 'prepared_payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => $this->now()]);
            $this->db->table(Tables::name('active_external_sagas'))->insert(['origin_id' => $origin['id'], 'saga_id' => $id]);
            $this->event($context, $origin, 'external_spawn_pending', $operation, ['saga_key' => $key, 'route' => $route]);

            return $this->get($context, $key);
        }, fn ($result) => $this->get($context, $result['saga_key']));
    }

    public function execute(ExecutionContext $context, string $key): array
    {
        $context->validateTenant();
        $this->authorize($context);
        $this->outsideTransaction();
        $this->get($context, $key);
        $saga = (array) $this->db->table(Tables::name('external_sagas'))->where('scope_key', $context->scope)->where('saga_key', $key)->first();
        if (in_array($saga['state'], ['confirmed', 'compensated', 'needs_manual_repair'], true)) {
            return $this->get($context, $key);
        }
        $handler = $this->routes->remote($saga['route_ref']);
        if ($saga['state'] === 'compensating') {
            return $this->compensate($context, $saga);
        }
        $origin = $this->origin($context, $saga);
        try {
            $this->db->transaction(fn () => $this->instances->lockForCommand($context, $origin['instance_key'], $saga['origin_version']));
        } catch (DomainError $error) {
            if ($error->getMessage() !== 'version_conflict') {
                throw $error;
            }
            $this->markForReview($context, $saga, $origin, 'source_changed_before_retry');

            return $this->get($context, $key);
        }
        try {
            $receipt = $handler->create($context, json_decode($saga['prepared_payload'], true, flags: JSON_THROW_ON_ERROR), 'external-create:'.$key);
            if (array_diff(array_keys($receipt), ['scope_key', 'subject_type', 'subject_id', 'external_process_key']) || ($receipt['scope_key'] ?? null) !== $context->scope) {
                throw new DomainError('external_receipt_invalid');
            }
            foreach (['subject_type', 'subject_id', 'external_process_key'] as $field) {
                if (! is_string($receipt[$field] ?? null) || trim($receipt[$field]) === '' || strlen($receipt[$field]) > 120) {
                    throw new DomainError('external_receipt_invalid');
                }
            }
            if (strlen($receipt['subject_type']) > 100 || ! preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', $receipt['subject_type'])) {
                throw new DomainError('external_receipt_invalid');
            }
        } catch (\Throwable $error) {
            $invalid = $error instanceof DomainError && $error->getMessage() === 'external_receipt_invalid';
            if ($invalid) {
                $this->markForReview($context, $saga, $origin, 'external_receipt_invalid');
            } else {
                $this->db->table(Tables::name('external_sagas'))->where('id', $saga['id'])->where('state', 'pending_external')->update(['failure_code' => 'external_create_pending', 'attempts' => $this->db->raw('attempts + 1')]);
            }

            return $this->get($context, $key);
        }
        try {
            return (new CommandExecutor($this->db))->execute($context, 'saga-confirm:'.$key, ['action' => 'saga_confirm', 'key' => $key, 'receipt' => $receipt], function () use ($context, $saga, $origin, $receipt, $key) {
                [$source] = $this->instances->lockForCommand($context, $origin['instance_key'], $saga['origin_version']);
                $current = $this->db->table(Tables::name('external_sagas'))->where('id', $saga['id'])->lockForUpdate()->first();
                if ($current->state !== 'pending_external') {
                    throw new DomainError('saga_state_conflict');
                }
                $case = $source['case_id'] === null ? $this->cases->create($context, 'External conversion', 'saga-case:'.$key)
                    : ['id' => $source['case_id'], 'case_key' => $this->db->table(Tables::name('process_cases'))->where('id', $source['case_id'])->value('case_key')];
                $this->instances->transition($context, $source['instance_key'], $source['version'], 'saga-source:'.$key, $saga['source_transition'], ['reason_code' => $saga['reason_code']]);
                $this->db->table(Tables::name('workflow_instances'))->where('id', $source['id'])->update(['case_id' => $case['id']]);
                $this->db->table(Tables::name('case_subjects'))->insertOrIgnore(['case_id' => $case['id'], 'subject_type' => $source['subject_type'], 'subject_id' => $source['subject_id']]);
                $this->db->table(Tables::name('external_case_subjects'))->insert(['case_id' => $case['id'], 'saga_id' => $saga['id'], 'route_ref' => $saga['route_ref'], 'subject_type' => $receipt['subject_type'], 'subject_id' => $receipt['subject_id'], 'external_process_key' => $receipt['external_process_key']]);
                $this->db->table(Tables::name('external_sagas'))->where('id', $saga['id'])->update(['state' => 'confirmed', 'receipt' => json_encode($receipt, JSON_THROW_ON_ERROR), 'confirmed_at' => $this->now(), 'version' => $current->version + 1, 'failure_code' => null]);
                $this->db->table(Tables::name('active_external_sagas'))->where('saga_id', $saga['id'])->delete();
                $this->event($context, $source, 'external_spawn_confirmed', 'saga-confirm:'.$key, ['saga_key' => $key, 'route' => $saga['route_ref']]);

                return $this->get($context, $key);
            }, fn ($result) => $this->get($context, $result['saga_key']));
        } catch (DomainError $error) {
            // Another worker may have completed the identical receipt while this worker waited.
            $current = $this->db->table(Tables::name('external_sagas'))->where('id', $saga['id'])->first();
            if ($current->state === 'confirmed') {
                if (json_decode($current->receipt, true, flags: JSON_THROW_ON_ERROR) !== $receipt) {
                    throw new DomainError('external_receipt_conflict');
                }

                return $this->get($context, $key);
            }
            $this->db->transaction(function () use ($context, $saga, $origin, $receipt, $key) {
                $current = $this->db->table(Tables::name('external_sagas'))->where('id', $saga['id'])->lockForUpdate()->first();
                if ($current->state !== 'pending_external') {
                    return;
                }
                $this->db->table(Tables::name('external_sagas'))->where('id', $saga['id'])->update(['state' => 'compensating', 'receipt' => json_encode($receipt, JSON_THROW_ON_ERROR), 'failure_code' => 'local_confirmation_denied', 'version' => $current->version + 1]);
                $this->event($context, $origin, 'external_spawn_compensating', 'saga-compensate:'.$key, ['saga_key' => $key]);
            });
            $updated = (array) $this->db->table(Tables::name('external_sagas'))->where('id', $saga['id'])->first();

            return $this->compensate($context, $updated);
        }
    }

    private function compensate(ExecutionContext $context, array $saga): array
    {
        $this->outsideTransaction();
        try {
            $repaired = $this->routes->remote($saga['route_ref'])->compensate($context, json_decode($saga['receipt'], true, flags: JSON_THROW_ON_ERROR), 'external-compensate:'.$saga['saga_key']);
        } catch (\Throwable $error) {
            $repaired = false;
        }
        $this->db->transaction(function () use ($context, $saga, $repaired) {
            $row = $this->db->table(Tables::name('external_sagas'))->where('id', $saga['id'])->lockForUpdate()->first();
            if ($row->state !== 'compensating') {
                return;
            }
            $this->db->table(Tables::name('external_sagas'))->where('id', $saga['id'])->update(['state' => $repaired ? 'compensated' : 'needs_manual_repair', 'version' => $row->version + 1]);
            if ($repaired) {
                $this->db->table(Tables::name('active_external_sagas'))->where('saga_id', $saga['id'])->delete();
            }
            $this->event($context, $this->origin($context, $saga), $repaired ? 'external_spawn_compensated' : 'external_spawn_manual_repair', 'saga-repair:'.$saga['saga_key'], ['saga_key' => $saga['saga_key']]);
        });

        return $this->get($context, $saga['saga_key']);
    }

    public function repair(ExecutionContext $context, string $key, int $version): array
    {
        $context->validateTenant();
        $this->authorize($context);
        $context->require('saga.repair');
        $this->authorization->authorize($context, 'saga.repair');
        $this->outsideTransaction();
        $saga = $this->get($context, $key);
        if ($saga['state'] !== 'needs_manual_repair') {
            throw new DomainError('saga_state_conflict');
        }
        if ($saga['version'] !== $version) {
            throw new DomainError('version_conflict');
        }
        $stored = $this->db->table(Tables::name('external_sagas'))->where('id', $saga['id'])->first();
        $receipt = $stored->receipt === null ? $this->routes->remote($saga['route_ref'])->lookup($context, 'external-create:'.$key) : json_decode($stored->receipt, true, flags: JSON_THROW_ON_ERROR);
        if ($receipt !== null && (($receipt['scope_key'] ?? null) !== $context->scope || ! is_string($receipt['subject_id'] ?? null))) {
            throw new DomainError('external_receipt_invalid');
        }
        $this->db->transaction(function () use ($context, $saga, $version, $receipt, $key) {
            $source = $this->origin($context, $saga);
            $this->instances->lockForCommand($context, $source['instance_key'], $source['version']);
            $row = $this->db->table(Tables::name('external_sagas'))->where('id', $saga['id'])->lockForUpdate()->first();
            if ($row->state !== 'needs_manual_repair' || $row->version !== $version) {
                throw new DomainError('version_conflict');
            }
            $this->db->table(Tables::name('external_sagas'))->where('id', $row->id)->update(['state' => $receipt === null ? 'compensated' : 'compensating', 'receipt' => $receipt === null ? null : json_encode($receipt, JSON_THROW_ON_ERROR), 'version' => $version + 1]);
            if ($receipt === null) {
                $this->db->table(Tables::name('active_external_sagas'))->where('saga_id', $row->id)->delete();
            }
            $this->event($context, $source, 'external_repair_requested', 'saga-repair-request:'.$key.':'.$version, ['saga_key' => $key, 'reason_code' => 'explicit_compensation']);
        });
        if ($receipt === null) {
            return $this->get($context, $key);
        }

        return $this->compensate($context, (array) $this->db->table(Tables::name('external_sagas'))->where('id', $saga['id'])->first());
    }

    public function run(ExecutionContext $context, int $limit = 50): array
    {
        $context->validateTenant();
        $this->authorize($context);
        $this->outsideTransaction();
        if ($limit < 1 || $limit > 250) {
            throw new DomainError('batch_size_invalid');
        }
        $rows = $this->db->table(Tables::name('external_sagas'))->where('scope_key', $context->scope)->whereIn('state', ['pending_external', 'compensating'])
            ->whereIn('origin_id', $this->instances->authorizedQuery($context)->select('id'))->orderBy('id')->limit($limit)->get();
        $states = [];
        foreach ($rows as $row) {
            $result = $this->execute($context, $row->saga_key);
            $states[$result['state']] = ($states[$result['state']] ?? 0) + 1;
        }

        return $states;
    }

    private function markForReview(ExecutionContext $context, array $saga, array $origin, string $reason): void
    {
        $this->db->transaction(function () use ($context, $saga, $origin, $reason) {
            $current = $this->db->table(Tables::name('external_sagas'))->where('id', $saga['id'])->lockForUpdate()->first();
            if ($current->state !== 'pending_external') {
                return;
            }
            $this->db->table(Tables::name('external_sagas'))->where('id', $saga['id'])->update(['state' => 'needs_manual_repair', 'failure_code' => $reason, 'version' => $current->version + 1]);
            $this->event($context, $origin, 'external_spawn_manual_repair', 'saga-review:'.$saga['saga_key'], ['saga_key' => $saga['saga_key'], 'reason_code' => $reason]);
        });
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function event(ExecutionContext $context, array $origin, string $action, string $operation, array $payload): void
    {
        (new EventRecorder($this->db, $this->clock))->append($context, 'instance', $origin['instance_key'], $action, $operation, $payload, $origin['task_id']);
    }
}
