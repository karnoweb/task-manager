<?php

namespace Karnoweb\TaskManager\Handoff;

use Karnoweb\TaskManager\Contracts\LocalSubjectRouter;
use Karnoweb\TaskManager\Contracts\RemoteSubjectRouter;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Workflow\Definition;

final readonly class SubjectRouteRegistry
{
    public function __construct(private array $local = [], private array $remote = [])
    {
        foreach ($local as $key => $handler) { Definition::key($key); if (!$handler instanceof LocalSubjectRouter) { throw new DomainError('route_invalid'); } }
        foreach ($remote as $key => $handler) { Definition::key($key); if (!$handler instanceof RemoteSubjectRouter) { throw new DomainError('route_invalid'); } }
    }
    public function local(string $key): LocalSubjectRouter { return $this->local[$key] ?? throw new DomainError('route_unregistered'); }
    public function remote(string $key): RemoteSubjectRouter { return $this->remote[$key] ?? throw new DomainError('route_unregistered'); }
}
