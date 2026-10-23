<?php

namespace App\TaskManager;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TicketChat\Models\Message;

/** Host message endpoint policy: unknown private-note provenance stays hidden. */
final readonly class TicketMessageAccess
{
    public function __construct(private Connection $db, private TicketSubject $tickets)
    {
    }

    public function query(ExecutionContext $context, string $ticketId): Builder
    {
        $ticket = $this->tickets->query($context, 'view')->where($this->tickets->idColumn(), $ticketId)->first() ?? throw new DomainError('not_found');
        $prefix = config('ticket-chat.general.table_prefix', 'tc_');
        $departments = $this->db->table($prefix.'department_users')->join($prefix.'departments', $prefix.'departments.id', '=', $prefix.'department_users.department_id')
            ->where('user_id', $context->actor)->where($prefix.'department_users.is_active', true)->where($prefix.'departments.is_active', true)->whereNull($prefix.'departments.deleted_at')
            ->where($prefix.'departments.meta->scope_key', $context->scope)->pluck('department_id')->all();

        return $this->db->table((new Message)->getTable())->where('conversation_id', $ticket->id)->whereNull('deleted_at')->where(function (Builder $q) use ($departments) {
            $q->where('type', '!=', 'internal_note');
            foreach ($departments as $id) {
                $q->orWhere('meta->department_id', (int) $id);
            }
        });
    }
}
