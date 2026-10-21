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

final class CaseService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly InstanceService $instances)
    {
    }

    public function create(ExecutionContext $context, string $title, string $operation): array
    {
        $context->require('case.manage');
        $this->authorization->authorize($context, 'case.manage');
        if (trim($title) === '' || mb_strlen($title) > 255) {
            throw new DomainError('title_invalid');
        }

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'case_create', 'title' => $title], function () use ($context, $title, $operation) {
            $key = (string) Str::uuid();
            $id = $this->db->table('tm_process_cases')->insertGetId(['case_key' => $key, 'scope_key' => $context->scope, 'owner_ref' => $context->actor, 'title' => $title]);
            (new EventRecorder($this->db, $this->clock))->append($context, 'case', $key, 'case_created', $operation, []);

            return ['id' => $id, 'case_key' => $key];
        }, function ($r) use ($context) {
            if (! $this->db->table('tm_process_cases')->where('scope_key', $context->scope)->where('id', $r['id'])->where('owner_ref', $context->actor)->exists()) {
                throw new DomainError('not_found');
            }
        });
    }

    public function timeline(ExecutionContext $context, string $caseKey, int $limit = 100): array
    {
        $context->require('workflow.view');
        $this->authorization->authorize($context, 'workflow.view');
        if ($limit < 1 || $limit > 100 || ! Str::isUuid($caseKey)) {
            throw new DomainError('query_invalid');
        }
        $case = $this->db->table('tm_process_cases')->where('scope_key', $context->scope)->where('case_key', $caseKey)->first();
        if (! $case) {
            return [];
        }
        $query = $this->instances->authorizedQuery($context)->where('case_id', $case->id);
        $cast = $this->db->getDriverName() === 'mysql' ? 'CHAR' : 'VARCHAR(120)';
        $query->selectRaw('CAST(instance_key AS '.$cast.')');

        return $this->db->table('tm_audit_events')->where('scope_key', $context->scope)->where('aggregate_type', 'instance')->whereIn('aggregate_key', $query)
            ->orderBy('occurred_at')->orderBy('id')->limit($limit)->get()->map(fn ($r) => (array) $r)->all();
    }
}
