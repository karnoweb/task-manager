<?php

namespace Karnoweb\TaskManager\Workflow;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\EntryRoutingProvider;
use Karnoweb\TaskManager\Exceptions\DomainError;

final class UnavailableEntryRoutingProvider implements EntryRoutingProvider
{
    public function authorize(ExecutionContext $context, string $routingKey): void
    {
        throw new DomainError('entry_routing_provider_required');
    }

    public function parent(ExecutionContext $context, string $routingKey): ?string
    {
        return null;
    }
}
