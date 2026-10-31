<?php

namespace Karnoweb\TaskManager;

use Illuminate\Contracts\Container\Container;
use Karnoweb\TaskManager\Contracts\SubjectHandler;
use Karnoweb\TaskManager\Exceptions\DomainError;

/**
 * Package-friendly extension point for host subjects.
 *
 * Several packages can contribute stable aliases without replacing each other's registry binding.
 * Handlers are created lazily so a package may register during its own service-provider register phase.
 * A host that binds its own SubjectRegistry must merge {@see self::handlers()} itself.
 */
final class SubjectRegistrations
{
    /** @var array<string, \Closure(Container): SubjectHandler> */
    private array $factories = [];

    /** @param \Closure(Container): SubjectHandler $factory */
    public function add(string $alias, \Closure $factory): void
    {
        if ($alias === 'task' || isset($this->factories[$alias]) || ! preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', $alias)) {
            throw new DomainError('subject_registration_invalid');
        }
        $this->factories[$alias] = $factory;
    }

    /** @return list<string> */
    public function aliases(): array
    {
        return array_keys($this->factories);
    }

    /** @return array<string, SubjectHandler> */
    public function handlers(Container $app): array
    {
        $handlers = [];
        foreach ($this->factories as $alias => $factory) {
            $handlers[$alias] = $factory($app);
        }

        return $handlers;
    }
}
