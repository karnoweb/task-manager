<?php

namespace Karnoweb\TaskManager\Context;

use Illuminate\Support\Str;
use Karnoweb\TaskManager\Exceptions\DomainError;

final readonly class ExecutionContext
{
    public array $capabilities;

    public function __construct(public string $scope, public string $actor, public string $correlationId, public bool $serviceActor = false, ?array $capabilities = null, public ?string $originEventId = null, public int $automationDepth = 0)
    {
        foreach ([$scope, $actor, $correlationId] as $value) {
            if (trim($value) === '' || strlen($value) > 120) {
                throw new DomainError('context_invalid');
            }
        }
        if ($serviceActor && $capabilities === null) {
            throw new DomainError('service_capabilities_required');
        }
        if ($automationDepth < 0 || $automationDepth > 20 || ($originEventId !== null && ! Str::isUuid($originEventId))) {
            throw new DomainError('context_lineage_invalid');
        }
        $this->capabilities = $capabilities ?? ['task.create', 'task.view', 'task.edit', 'task.share', 'list.create', 'list.view', 'activity.view'];
    }

    public function require(string $capability): void
    {
        $this->validateTenant();
        if (! in_array($capability, $this->capabilities, true)) {
            throw new DomainError('capability_denied');
        }
    }

    public function validateTenant(): void
    {
        TenantBoundary::validate($this);
    }
}
