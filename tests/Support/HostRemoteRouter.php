<?php

namespace Karnoweb\TaskManager\Tests\Support;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\RemoteSubjectRouter;

/** Separate physical SQLite database; no participation in the local transaction. */
final class HostRemoteRouter implements RemoteSubjectRouter
{
    public bool $failAfterCreate = false;

    public bool $canCompensate = true;

    public ?string $receiptScope = null;

    public bool $exportPrivate = false;

    public $onCreated = null;

    public function __construct(public Connection $remote, private Connection $local)
    {
    }

    public function prepare(ExecutionContext $context, array $origin): array
    {
        return ['summary' => 'Approved sales summary', 'source_public_key' => $origin['subject_id'], ...($this->exportPrivate ? ['private_text' => 'PRIVATE-HOST-TEXT'] : [])];
    }

    public function create(ExecutionContext $context, array $payload, string $operation): array
    {
        if ($this->local->transactionLevel() !== 0) {
            throw new \RuntimeException('Remote request inside local transaction');
        }
        $id = 'remote-'.substr(hash('sha256', $operation), 0, 20);
        $this->remote->table('remote_opportunities')->insertOrIgnore(['id' => $id, 'scope_key' => $context->scope, 'operation_id' => $operation, 'summary' => $payload['summary'], 'cancelled' => false]);
        if ($this->failAfterCreate) {
            $this->failAfterCreate = false;
            throw new \RuntimeException('Injected lost remote acknowledgement');
        }
        if ($this->onCreated !== null) {
            ($this->onCreated)();
        }

        return ['scope_key' => $this->receiptScope ?? $context->scope, 'subject_type' => 'crm.opportunity', 'subject_id' => $id, 'external_process_key' => 'process-'.$id];
    }

    public function compensate(ExecutionContext $context, array $receipt, string $operation): bool
    {
        if ($this->local->transactionLevel() !== 0) {
            throw new \RuntimeException('Compensation inside local transaction');
        }
        if (! $this->canCompensate) {
            return false;
        }
        $this->remote->table('remote_opportunities')->where('id', $receipt['subject_id'])->where('scope_key', $context->scope)->update(['cancelled' => true]);

        return true;
    }

    public function lookup(ExecutionContext $context, string $operation): ?array
    {
        $row = $this->remote->table('remote_opportunities')->where('scope_key', $context->scope)->where('operation_id', $operation)->first();

        return $row === null ? null : ['scope_key' => $context->scope, 'subject_type' => 'crm.opportunity', 'subject_id' => $row->id, 'external_process_key' => 'process-'.$row->id];
    }
}
