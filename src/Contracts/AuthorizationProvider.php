<?php

namespace Karnoweb\TaskManager\Contracts;

use Illuminate\Database\Query\Builder;
use Karnoweb\TaskManager\Context\ExecutionContext;

interface AuthorizationProvider
{
    /** Throw when the current actor cannot perform this capability. */
    public function authorize(ExecutionContext $context, string $capability): void;

    /** Add host SQL restrictions. Core owner/scope predicates always remain. */
    public function constrain(Builder $query, ExecutionContext $context, string $resource, string $capability): void;
}
