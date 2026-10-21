<?php

namespace Karnoweb\TaskManager;

use Illuminate\Database\Query\Builder;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;

final class ContextAuthorization implements AuthorizationProvider
{
    public function authorize(ExecutionContext $context, string $capability): void
    {
        $context->require($capability);
    }

    public function constrain(Builder $query, ExecutionContext $context, string $resource, string $capability): void
    {
    }
}
