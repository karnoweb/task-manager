<?php

namespace App\TaskManager;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TicketChat\Enums\MessageType;
use Karnoweb\TicketChat\Enums\TicketStatus;
use Karnoweb\TicketChat\Models\Conversation;
use Karnoweb\TicketChat\Models\Department;
use Karnoweb\TicketChat\Models\Message;

/** Host application service: domain writes stay here, outside Task Manager core. */
final readonly class TicketWrites
{
    public function __construct(private Connection $db)
    {
    }

    private function ticket(ExecutionContext $context, int $id): Conversation
    {
        $context->require('workflow.transition');
        if ($this->db->transactionLevel() < 1) {
            throw new DomainError('ticket_write_requires_engine_transaction');
        }

        return Conversation::query()->where(config('ticket-chat.general.branch_column', 'branch_id'), $context->scope)->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function recordResult(ExecutionContext $context, int $id, array $payload): void
    {
        $context->require('ticket.close_result');
        if (! is_string($payload['result'] ?? null) || trim($payload['result']) === '' || mb_strlen($payload['result']) > 10000 || ! in_array($payload['visibility'] ?? null, ['internal', 'public'], true)) {
            throw new DomainError('ticket_result_invalid');
        }
        $ticket = $this->ticket($context, $id);
        Message::withoutEvents(fn () => Message::query()->create(['conversation_id' => $ticket->id, 'user_id' => $context->actor, 'body' => $payload['result'],
            'type' => $payload['visibility'] === 'internal' ? MessageType::INTERNAL_NOTE : MessageType::TEXT, 'meta' => ['department_id' => $ticket->department_id]]));
        $ticket->activities()->create(['user_id' => $context->actor, 'action' => 'result_recorded', 'meta' => ['visibility' => $payload['visibility']]]);
        // Notification delivery remains an explicit host subscription to the engine outbox.
    }

    public function project(ExecutionContext $context, int $id, string $status): void
    {
        $ticket = $this->ticket($context, $id);
        $target = TicketStatus::from($status);
        if ($ticket->status === $target) {
            return;
        }
        $before = $ticket->status->value;
        Conversation::withoutEvents(fn () => $ticket->forceFill(['status' => $target, 'closed_at' => in_array($status, ['closed', 'archived'], true) ? now() : null,
            'closed_by' => in_array($status, ['closed', 'archived'], true) ? $context->actor : null, 'resolved_at' => $status === 'resolved' ? now() : $ticket->resolved_at])->save());
        $ticket->activities()->create(['user_id' => $context->actor, 'action' => 'status_changed', 'meta' => ['old_status' => $before, 'new_status' => $status]]);
    }

    public function moveDepartment(ExecutionContext $context, int $id, int $departmentId, ?string $assignee = 'preserve'): void
    {
        $context->require('handoff.manage');
        $ticket = $this->ticket($context, $id);
        $destination = Department::query()->whereKey($departmentId)->where('is_active', true)->where('meta->scope_key', $context->scope)->first();
        if (! $destination) {
            throw new DomainError('destination_not_accepted');
        }
        $assignee = $assignee === 'preserve' ? ($ticket->assigned_to === null ? null : (string) $ticket->assigned_to) : $assignee;
        if ($assignee !== null && ! $destination->users()->where('user_id', $assignee)->wherePivot('is_active', true)->exists()) {
            throw new DomainError('assignee_ineligible');
        }
        $before = $ticket->department_id;
        Conversation::withoutEvents(fn () => $ticket->forceFill(['department_id' => $departmentId, 'assigned_to' => $assignee])->save());
        $ticket->activities()->create(['user_id' => $context->actor, 'action' => 'transferred', 'meta' => ['from_department' => $before, 'to_department' => $departmentId, 'assignee' => $assignee]]);
    }
}
