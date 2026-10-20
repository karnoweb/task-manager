<?php

namespace Karnoweb\TaskManager\DTO;

use Karnoweb\TaskManager\Context\ExecutionContext;

final readonly class MutateTask
{
    public function __construct(public ExecutionContext $context, public string $publicKey, public int $expectedVersion,
        public string $operationId, public string $action, public array $values = []) {}
}
