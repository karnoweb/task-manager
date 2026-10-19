<?php

namespace Karnoweb\TaskManager\Context;

use Karnoweb\TaskManager\Exceptions\DomainError;

final readonly class ExecutionContext
{
    public array $capabilities;

    public function __construct(public string $scope, public string $actor, public string $correlationId, public bool $serviceActor = false, ?array $capabilities = null)
    {
        foreach ([$scope, $actor, $correlationId] as $value) {
            if (trim($value) === '' || strlen($value) > 120) {
                throw new DomainError('context_invalid');
            }
        }
        if ($serviceActor && $capabilities === null) { throw new DomainError('service_capabilities_required'); }
        $this->capabilities = $capabilities ?? ['task.create', 'task.view', 'task.edit', 'task.share', 'list.create', 'list.view', 'activity.view'];
    }

    public function require(string $capability): void
    {
        if (!in_array($capability, $this->capabilities, true)) { throw new DomainError('capability_denied'); }
    }
}
