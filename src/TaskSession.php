<?php

namespace Karnoweb\TaskManager;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\DTO\TaskResult;

/** Immutable, request-local fluent entry point; never retained on the facade. */
final readonly class TaskSession
{
    public function __construct(private TaskService $service, private ExecutionContext $context) {}
    public function create(string $title, string $operation): TaskResult { return TaskResult::fromArray($this->service->create($this->context, $title, $operation)); }
    public function complete(string $id, int $version, string $operation): TaskResult { return TaskResult::fromArray($this->service->mutate($this->context, $id, $version, $operation, 'complete')); }
    public function get(string $id): TaskResult { return TaskResult::fromArray($this->service->get($this->context, $id)); }
}
