<?php

namespace Karnoweb\TaskManager\Tests\Support;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\LocalSubjectRouter;
use Karnoweb\TaskManager\Exceptions\DomainError;

/** Fixture for a real host-owned same-connection domain service. */
final class HostLocalRouter implements LocalSubjectRouter
{
    public bool $failAfterWrite = false;
    public function __construct(private Connection $db) {}
    public function connection(): Connection { return $this->db; }
    public function move(ExecutionContext $context, array $origin, string $targetPipeline, string $operation): void
    {
        $this->assertOrigin($context, $origin);
        $this->db->table('host_subjects')->where('id', $origin['subject_id'])->where('scope_key', $context->scope)->update(['department' => 'sales']);
        $this->db->table('host_route_operations')->insert(['operation_id' => $operation, 'kind' => 'move']);
        if ($this->failAfterWrite) { throw new DomainError('host_route_failed'); }
    }
    public function spawn(ExecutionContext $context, array $origin, string $operation): array
    {
        $this->assertOrigin($context, $origin); $id = 'opportunity-'.substr(hash('sha256', $operation), 0, 20);
        $this->db->table('host_subjects')->insert(['id' => $id, 'scope_key' => $context->scope, 'type' => 'crm.opportunity', 'title' => 'Selected sales summary', 'department' => 'sales']);
        foreach (array_unique([$context->actor, 'b']) as $actor) { $this->db->table('host_permissions')->insert(['subject_id' => $id, 'actor' => $actor, 'can_edit' => true]); }
        $this->db->table('host_route_operations')->insert(['operation_id' => $operation, 'kind' => 'spawn']);
        if ($this->failAfterWrite) { throw new DomainError('host_route_failed'); }
        return ['subject_type' => 'crm.opportunity', 'subject_id' => $id];
    }
    private function assertOrigin(ExecutionContext $context, array $origin): void
    {
        if ($origin['subject_type'] !== 'support.ticket' || !$this->db->table('host_subjects')->where('id', $origin['subject_id'])->where('scope_key', $context->scope)->where('type', 'support.ticket')->exists()) { throw new DomainError('destination_not_accepted'); }
        if ($this->db->transactionLevel() < 1) { throw new DomainError('owner_transaction_required'); }
    }
}
