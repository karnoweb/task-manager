<?php

namespace Karnoweb\TaskManager\Group;

use Karnoweb\TaskManager\Contracts\GroupSourceProvider;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Workflow\Definition;

final readonly class GroupSourceRegistry
{
    public function __construct(private array $providers = [])
    {
        foreach ($providers as $key => $provider) {
            Definition::key($key);
            if (! $provider instanceof GroupSourceProvider) {
                throw new DomainError('group_source_invalid');
            }
        }
    }

    public function provider(string $key): GroupSourceProvider
    {
        return $this->providers[$key] ?? throw new DomainError('group_source_unregistered');
    }
}
