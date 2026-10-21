<?php

namespace Karnoweb\TaskManager\Integration;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Workflow\Definition;

final class ConsumerRegistry
{
    private array $consumers = [];

    public function register(string $key, callable $consumer): void
    {
        Definition::key($key);
        if (isset($this->consumers[$key])) {
            throw new DomainError('consumer_already_registered');
        }$this->consumers[$key] = $consumer;
    }

    public function require(string $key): void
    {
        if (! isset($this->consumers[$key])) {
            throw new DomainError('consumer_unregistered');
        }
    }

    public function deliver(string $key, ExecutionContext $context, array $event): void
    {
        $this->require($key);
        ($this->consumers[$key])($context, $event);
    }
}
