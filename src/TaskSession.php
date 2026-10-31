<?php

namespace Karnoweb\TaskManager;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\DTO\TaskResult;

/** Immutable, request-local fluent entry point; never retained on the facade. */
final readonly class TaskSession
{
    public function __construct(private TaskService $service, private ExecutionContext $context)
    {
        $context->validateTenant();
    }

    public function create(string $title, string $operation): TaskResult
    {
        return TaskResult::fromArray($this->service->create($this->context, $title, $operation));
    }

    public function complete(string $id, int $version, string $operation): TaskResult
    {
        return TaskResult::fromArray($this->service->mutate($this->context, $id, $version, $operation, 'complete'));
    }

    public function get(string $id): TaskResult
    {
        return TaskResult::fromArray($this->service->get($this->context, $id));
    }

    public function update(string $id, int $version, string $operation, array $values): TaskResult
    {
        return TaskResult::fromArray($this->service->mutate($this->context, $id, $version, $operation, 'update', $values));
    }

    public function reopen(string $id, int $version, string $operation): TaskResult
    {
        return TaskResult::fromArray($this->service->mutate($this->context, $id, $version, $operation, 'reopen'));
    }

    public function archive(string $id, int $version, string $operation): TaskResult
    {
        return TaskResult::fromArray($this->service->mutate($this->context, $id, $version, $operation, 'archive'));
    }

    public function restore(string $id, int $version, string $operation): TaskResult
    {
        return TaskResult::fromArray($this->service->mutate($this->context, $id, $version, $operation, 'restore'));
    }

    /** @return list<TaskResult> */
    public function query(int $page = 1, ?int $limit = null): array
    {
        $rows = $limit === null ? $this->service->query($this->context, $page) : $this->service->query($this->context, $page, $limit);

        return array_map(TaskResult::fromArray(...), $rows);
    }
}
