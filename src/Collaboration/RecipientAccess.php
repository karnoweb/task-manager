<?php

namespace Karnoweb\TaskManager\Collaboration;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\TaskService;

final class RecipientAccess
{
    public static function permitted(TaskService $tasks, ExecutionContext $context, int $taskId, string $actor): bool
    {
        if (trim($actor) === '' || strlen($actor) > 120) { return false; }
        try {
            $recipient = new ExecutionContext($context->scope, $actor, $context->correlationId, capabilities: ['task.view']);
            return $tasks->authorizedQuery($recipient)->where('id', $taskId)->whereNull('deleted_at')->exists();
        } catch (DomainError) { return false; }
    }
}
