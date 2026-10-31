<?php

namespace Karnoweb\TaskManager\Workflow;

use Illuminate\Database\Query\Builder;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\SubjectHandler;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\TaskService;

final class TaskSubjectHandler implements SubjectHandler
{
    public function __construct(private readonly TaskService $tasks)
    {
    }

    public function query(ExecutionContext $context, string $capability): Builder
    {
        $context->validateTenant();

        return $this->tasks->authorizedQuery($context, $capability);
    }

    public function idColumn(): string
    {
        return 'public_key';
    }

    public function scopeColumn(): string
    {
        return 'scope_key';
    }

    public function guard(ExecutionContext $context, string $subjectId, array $instance, string $action, array $payload): void
    {
        $context->validateTenant();
        $task = $this->tasks->get($context, $subjectId);
        if ($task['deleted_at'] !== null || $task['archived_at'] !== null) {
            throw new DomainError('task_inactive');
        }
    }
}
