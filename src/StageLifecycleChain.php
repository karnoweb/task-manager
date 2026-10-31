<?php

namespace Karnoweb\TaskManager;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\StageLifecycle;

final readonly class StageLifecycleChain implements StageLifecycle
{
    public function __construct(private array $hooks)
    {
    }

    public function activated(ExecutionContext $context, int $instanceId, string $operationId): void
    {
        $context->validateTenant();
        foreach ($this->hooks as $hook) {
            $hook->activated($context, $instanceId, $operationId);
        }
    }

    public function entered(ExecutionContext $context, int $instanceId, int $visitId, object $stage, string $operationId): void
    {
        $context->validateTenant();
        foreach ($this->hooks as $hook) {
            $hook->entered($context, $instanceId, $visitId, $stage, $operationId);
        }
    }

    public function leaving(ExecutionContext $context, int $instanceId): void
    {
        $context->validateTenant();
        foreach (array_reverse($this->hooks) as $hook) {
            $hook->leaving($context, $instanceId);
        }
    }

    public function activity(ExecutionContext $context, int $instanceId): void
    {
        $context->validateTenant();
        foreach ($this->hooks as $hook) {
            $hook->activity($context, $instanceId);
        }
    }

    public function beforeTransition(ExecutionContext $context, array $instance, array $edge, array $payload): void
    {
        $context->validateTenant();
        foreach ($this->hooks as $hook) {
            $hook->beforeTransition($context, $instance, $edge, $payload);
        }
    }
}
