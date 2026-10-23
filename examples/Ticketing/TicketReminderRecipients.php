<?php

namespace App\TaskManager;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\ReminderRecipients;
use Karnoweb\TaskManager\Exceptions\DomainError;

final readonly class TicketReminderRecipients implements ReminderRecipients
{
    public function __construct(private Connection $db, private TicketSubject $tickets)
    {
    }

    public function suggest(ExecutionContext $context, ?string $type, ?string $id): string
    {
        if ($type !== 'support.ticket') {
            return $context->actor;
        }
        $ticket = $this->tickets->query($context, 'view')->where($this->tickets->idColumn(), $id)->first() ?? throw new DomainError('not_found');

        return (string) ($ticket->assigned_to ?? $context->actor);
    }

    public function authorize(ExecutionContext $context, string $recipient, ?string $type, ?string $id): void
    {
        if ($recipient === $context->actor) {
            return;
        }
        $context->require('reminder.assign');
        if ($type !== 'support.ticket' || ! preg_match('/^[1-9][0-9]*$/D', $recipient)) {
            throw new DomainError('reminder_recipient_denied');
        }
        $ticket = $this->tickets->query($context, 'edit')->where($this->tickets->idColumn(), $id)->first() ?? throw new DomainError('not_found');
        $member = $this->db->table(config('ticket-chat.general.table_prefix', 'tc_').'department_users')->where('department_id', $ticket->department_id)->where('user_id', $recipient)->where('is_active', true)->exists();
        if ((string) $ticket->created_by !== $recipient && (string) $ticket->assigned_to !== $recipient && ! $member) {
            throw new DomainError('reminder_recipient_denied');
        }
    }
}
