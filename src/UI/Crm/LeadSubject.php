<?php

namespace Karnoweb\TaskManager\UI\Crm;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\SubjectHandler;
use Karnoweb\TaskManager\Exceptions\DomainError;

final readonly class LeadSubject implements SubjectHandler
{
    public function __construct(private Connection $db, private HostAccess $access)
    {
    }

    public function idColumn(): string
    {
        return 'crm_leads.id';
    }

    public function scopeColumn(): string
    {
        return 'crm_leads.branch_id';
    }

    public function query(ExecutionContext $c, string $capability): Builder
    {
        $q = $this->db->table('crm_leads')->whereNull('merged_into_lead_id')->whereNull('archived_at');
        $this->access->constrain($q, $c, 'crm.lead', $capability);

        return $q;
    }

    public function guard(ExecutionContext $c, string $id, array $instance, string $action, array $payload): void
    {
        if (! $this->query($c, 'edit')->where($this->scopeColumn(), $c->scope)->where($this->idColumn(), $id)->exists()) {
            throw new DomainError('not_found');
        }
    }
}
