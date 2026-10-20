<?php

namespace Karnoweb\TaskManager;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\Exceptions\DomainError;

final class EventRecorder
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock = new SystemClock()) {}
    public function append(ExecutionContext $context, string $type, string $key, string $action, string $operation, array $safePayload, ?int $taskId = null): void
    {
        if ($this->db->transactionLevel() < 1) { throw new DomainError('event_requires_transaction'); }
        $event = ['event_id' => (string) Str::uuid(), 'scope_key' => $context->scope, 'actor_ref' => $context->actor, 'task_id' => $taskId,
            'aggregate_type' => $type, 'aggregate_key' => $key, 'action' => $action, 'operation_id' => $operation, 'correlation_id' => $context->correlationId,
            'payload' => json_encode(AuditRedactor::redact($safePayload), JSON_THROW_ON_ERROR), 'occurred_at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
        $this->db->table('tm_audit_events')->insert($event); $this->db->table('tm_outbox')->insert($event);
        if ($context->originEventId !== null) { $this->db->table('tm_event_lineage')->insert(['event_id' => $event['event_id'], 'root_event_id' => $context->originEventId, 'depth' => $context->automationDepth]); }
    }
}
