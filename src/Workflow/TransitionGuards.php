<?php

namespace Karnoweb\TaskManager\Workflow;

use Karnoweb\TaskManager\Exceptions\DomainError;

/** Immutable host registration; callbacks receive the current context, never a cached actor. */
final readonly class TransitionGuards
{
    /** @param array<string, callable> $guards */
    public function __construct(private array $guards = [])
    {
        foreach ($guards as $name => $guard) {
            Definition::key($name);
            if (! is_callable($guard)) {
                throw new DomainError('guard_registration_invalid');
            }
        }
    }

    public function get(string $name): ?callable
    {
        return $this->guards[$name] ?? null;
    }
}
