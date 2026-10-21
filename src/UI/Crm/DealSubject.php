<?php

namespace Karnoweb\TaskManager\UI\Crm;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\SubjectHandler;
use Karnoweb\TaskManager\Exceptions\DomainError;

/** Real CRM Deal storage; workflow state belongs to the engine after cutover. */
final readonly class DealSubject implements SubjectHandler
{
    public function __construct(private Connection $db, private HostAccess $access)
    {
    }

    public function idColumn(): string
    {
        return 'crm_deals.id';
    }

    public function scopeColumn(): string
    {
        return 'crm_deals.branch_id';
    }

    public function query(ExecutionContext $context, string $capability): Builder
    {
        $q = $this->db->table('crm_deals')->whereNull('crm_deals.deleted_at')->whereExists(function (Builder $q) {
            $q->selectRaw('1')->from('crm_leads')->whereColumn('crm_leads.id', 'crm_deals.lead_id')->whereNull('crm_leads.merged_into_lead_id')->whereNull('crm_leads.archived_at');
        });
        $this->access->constrain($q, $context, 'crm.deal', $capability);
        if ($capability === 'edit') {
            $q->whereNotExists(function (Builder $x) use ($context) {
                $x->selectRaw('1')->from('tm_host_imports')->join('tm_host_cutovers', 'tm_host_cutovers.id', '=', 'tm_host_imports.cutover_id')->where('tm_host_cutovers.scope_key', $context->scope)->where('tm_host_imports.legacy_type', 'crm.deals')->whereColumn('tm_host_imports.legacy_id', 'crm_deals.id')->where('tm_host_cutovers.mode', '!=', 'active');
            });
        }

        return $q;
    }

    public function guard(ExecutionContext $context, string $subjectId, array $instance, string $action, array $payload): void
    {
        if (! $this->query($context, 'edit')->where($this->scopeColumn(), $context->scope)->where($this->idColumn(), $subjectId)->exists()) {
            throw new DomainError('not_found');
        }
        // Domain rules such as lead conversion / contract creation stay in host route handlers.
    }
}
