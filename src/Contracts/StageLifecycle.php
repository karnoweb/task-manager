<?php

namespace Karnoweb\TaskManager\Contracts;

use Karnoweb\TaskManager\Context\ExecutionContext;

interface StageLifecycle
{
    public function activated(ExecutionContext $context, int $instanceId, string $operationId): void;

    public function entered(ExecutionContext $context, int $instanceId, int $visitId, object $stage, string $operationId): void;

    public function leaving(ExecutionContext $context, int $instanceId): void;

    public function activity(ExecutionContext $context, int $instanceId): void;

    public function beforeTransition(ExecutionContext $context, array $instance, array $edge, array $payload): void;
}
