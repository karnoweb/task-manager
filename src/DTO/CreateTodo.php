<?php

namespace Karnoweb\TaskManager\DTO;

use Karnoweb\TaskManager\Context\ExecutionContext;

final readonly class CreateTodo
{
    public function __construct(public ExecutionContext $context, public string $title, public string $operationId) {}
}
