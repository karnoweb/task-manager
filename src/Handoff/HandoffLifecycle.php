<?php

namespace Karnoweb\TaskManager\Handoff;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\StageLifecycle;
use Karnoweb\TaskManager\Exceptions\DomainError;

final class HandoffLifecycle implements StageLifecycle
{
    public function activated(ExecutionContext $context, int $instanceId, string $operationId): void
    {
        $context->validateTenant();
    }

    public function entered(ExecutionContext $context, int $instanceId, int $visitId, object $stage, string $operationId): void
    {
        $context->validateTenant();
    }

    public function leaving(ExecutionContext $context, int $instanceId): void
    {
        $context->validateTenant();
    }

    public function activity(ExecutionContext $context, int $instanceId): void
    {
        $context->validateTenant();
    }

    public function beforeTransition(ExecutionContext $context, array $instance, array $edge, array $payload): void
    {
        $context->validateTenant();
        if ($instance['lifecycle'] === 'waiting' && (($edge['force_exit'] ?? false) || ! ($edge['allow_while_waiting'] ?? false))) {
            throw new DomainError('instance_on_hold');
        }
    }
}
