<?php

namespace Karnoweb\TaskManager\Tests\Support;

use Illuminate\Database\Connection;
use Karnoweb\Crm\Services\DealService;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\LocalSubjectRouter;
use Karnoweb\TaskManager\Exceptions\DomainError;

/** Executable host route: real Ticket model and real CRM DealService, one connection. */
final class PhaseSixTicketRouter implements LocalSubjectRouter
{
    public bool $failAfterCreate = false;

    public function __construct(private Connection $db, private int $crmPipeline, private int $crmStage)
    {
    }

    public function connection(): Connection
    {
        return $this->db;
    }

    private function ticket(ExecutionContext $c, array $origin): PhaseSixTicket
    {
        if ($origin['subject_type'] !== 'support.ticket' || $this->db->transactionLevel() < 1) {
            throw new DomainError('destination_not_accepted');
        }
        $t = PhaseSixTicket::query()->where('branch_id', $c->scope)->where('owner_ref', $c->actor)->whereKey($origin['subject_id'])->lockForUpdate()->first();
        if (! $t) {
            throw new DomainError('not_found');
        }

        return $t;
    }

    public function spawn(ExecutionContext $c, array $origin, string $operation): array
    {
        $t = $this->ticket($c, $origin);
        $deal = app(DealService::class)->create(['lead_id' => $t->lead_id, 'pipeline_id' => $this->crmPipeline, 'pipeline_stage_id' => $this->crmStage, 'branch_id' => (int) $c->scope, 'assigned_to' => (int) $c->actor]);
        $t->forceFill(['referred_deal_id' => $deal->id])->save();
        if ($this->failAfterCreate) {
            throw new DomainError('host_crm_failure');
        }

        return ['subject_type' => 'crm.deal', 'subject_id' => (string) $deal->id];
    }

    public function move(ExecutionContext $c, array $origin, string $targetPipeline, string $operation): void
    {
        $t = $this->ticket($c, $origin);
        $t->forceFill(['department' => 'sales'])->save();
    }
}
