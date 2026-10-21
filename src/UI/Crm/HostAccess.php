<?php

namespace Karnoweb\TaskManager\UI\Crm;

use Illuminate\Database\Query\Builder;
use Karnoweb\TaskManager\Context\ExecutionContext;

/** Required host binding: tenant and current ACL restrictions for real CRM tables. */
interface HostAccess
{
    public function constrain(Builder $query, ExecutionContext $context, string $type, string $capability): void;

    public function scopeFor(object $model): string;
}
