<?php

namespace Karnoweb\TaskManager\Contracts;

use Karnoweb\TaskManager\Context\ExecutionContext;

/** Host-owned hierarchy and current authorization, never inferred from department tables. */
interface EntryRoutingProvider
{
    public function authorize(ExecutionContext $context, string $routingKey): void;

    public function parent(ExecutionContext $context, string $routingKey): ?string;
}
