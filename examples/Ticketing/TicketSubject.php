<?php

namespace App\TaskManager;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\SubjectHandler;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Workflow\WorkflowService;
use Karnoweb\TicketChat\Models\Conversation;
use Karnoweb\TicketChat\Models\Department;

/** Copy into app/TaskManager: a host-owned adapter for actual Ticket Chat 13.1.1. */
final readonly class TicketSubject implements SubjectHandler
{
    public function __construct(private Connection $db, private WorkflowService $workflows, private TicketWrites $writes)
    {
    }

    public function idColumn(): string
    {
        return (new Conversation)->getTable().'.id';
    }

    public function scopeColumn(): string
    {
        return (new Conversation)->getTable().'.'.config('ticket-chat.general.branch_column', 'branch_id');
    }

    public function query(ExecutionContext $context, string $capability): Builder
    {
        if (! preg_match('/^[1-9][0-9]*$/D', $context->scope) || ! preg_match('/^[1-9][0-9]*$/D', $context->actor)) {
            throw new DomainError('not_found');
        }
        $table = (new Conversation)->getTable();
        $members = config('ticket-chat.general.table_prefix', 'tc_').'department_users';
        $departments = (new Department)->getTable();

        return $this->db->table($table)->where($this->scopeColumn(), $context->scope)->where($table.'.type', 'ticket')->whereNull($table.'.deleted_at')->where(function (Builder $q) use ($context, $capability, $table, $members, $departments) {
            $q->where($table.'.assigned_to', $context->actor);
            if ($capability === 'view') {
                $q->orWhere($table.'.created_by', $context->actor);
            }
            $q->orWhereExists(function (Builder $member) use ($context, $table, $members, $departments) {
                $member->selectRaw('1')->from($members)->join($departments, $departments.'.id', '=', $members.'.department_id')
                    ->whereColumn($members.'.department_id', $table.'.department_id')->where($members.'.user_id', $context->actor)->where($members.'.is_active', true)
                    ->where($departments.'.is_active', true)->whereNull($departments.'.deleted_at');
            });
        });
    }

    public function guard(ExecutionContext $context, string $subjectId, array $instance, string $action, array $payload): void
    {
        $row = $this->query($context, 'edit')->where($this->idColumn(), $subjectId)->first();
        if (! $row || (new Conversation)->getConnection()->getName() !== $this->db->getName()) {
            throw new DomainError('not_found');
        }
        if ($instance && (TicketWorkflow::STATUS[$instance['current_state_key']] ?? null) !== $row->status) {
            throw new DomainError('ticket_projection_conflict');
        }
        if (in_array($action, ['start', 'move_pipeline'], true)) {
            $state = $payload['state'];
        } elseif (in_array($action, ['leave_pipeline', 'reconcile'], true)) {
            return;
        } else {
            $definition = $this->workflows->revision($context, $instance['workflow_revision_id'])['definition'];
            $edge = $definition['transitions'][$action] ?? throw new DomainError('transition_invalid');
            $state = $edge['to'];
            if (($edge['command'] ?? null) === 'close_result') {
                $this->writes->recordResult($context, (int) $subjectId, $payload);
            }
        }
        $status = TicketWorkflow::STATUS[$state] ?? throw new DomainError('ticket_state_mapping_invalid');
        $this->writes->project($context, (int) $subjectId, $status);
    }
}
