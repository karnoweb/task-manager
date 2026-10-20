<?php

namespace Karnoweb\TaskManager\DTO;

use Karnoweb\TaskManager\Context\ExecutionContext;

final readonly class TaskQuery
{
    public function __construct(public ExecutionContext $context, public int $page = 1, public int $limit = 30) {}
}
