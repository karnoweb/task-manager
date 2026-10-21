<?php

namespace Karnoweb\TaskManager;

use Karnoweb\TaskManager\Contracts\SubjectHandler;
use Karnoweb\TaskManager\Exceptions\DomainError;

final readonly class SubjectRegistry
{
    /** @param array<string, SubjectHandler> $handlers Stable aliases, never persisted PHP class names. */
    public function __construct(private array $handlers = [])
    {
        foreach ($handlers as $alias => $handler) {
            if (! is_string($alias) || ! preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', $alias) || ! $handler instanceof SubjectHandler) {
                throw new DomainError('subject_registration_invalid');
            }
        }
    }

    public function handler(string $alias): SubjectHandler
    {
        return $this->handlers[$alias] ?? throw new DomainError('subject_type_unregistered');
    }

    public function all(): array
    {
        return $this->handlers;
    }
}
