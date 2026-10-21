<?php

namespace Karnoweb\TaskManager\Responsibility;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\ResponsibilityResolver;

/** No host organization is inferred. Bind a host resolver to assign other actors. */
final class ContextResponsibilityResolver implements ResponsibilityResolver
{
    public function candidates(ExecutionContext $context, string $reference, string $purpose): array
    {
        return $reference === 'self' ? [$context->actor] : [];
    }

    public function eligible(ExecutionContext $context, string $actor, string $purpose): bool
    {
        return $actor === $context->actor;
    }
}
