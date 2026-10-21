<?php

namespace Karnoweb\TaskManager\UI;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Exceptions\DomainError;

/** The resolver must obtain capabilities from the current host, never UI payloads. */
final readonly class ContextBridge
{
    public function __construct(private \Closure $resolver) {}

    public function resolve(object $surface, Surface $definition): ExecutionContext
    {
        $key = $surface->boardKey ?? $surface->calendarKey ?? null;
        if ($key !== $definition->key || !is_string($surface->actorId ?? null)) { throw new DomainError('not_found'); }
        $context = ($this->resolver)($surface);
        if (!$context instanceof ExecutionContext || $context->scope !== $surface->scopeKey || $context->actor !== $surface->actorId || $context->serviceActor) { throw new DomainError('context_invalid'); }
        return $context;
    }
}
