<?php

namespace Karnoweb\TaskManager\Context;

use Illuminate\Container\Container;
use Karnoweb\TaskManager\Exceptions\DomainError;

final class TenantBoundary
{
    public static function scope(string $branch): string
    {
        if (trim($branch) === '' || strlen($branch) > 113) {
            throw new DomainError('context_branch_identifier_invalid');
        }

        return 'branch:'.$branch;
    }

    public static function validate(ExecutionContext $context): void
    {
        $container = Container::getInstance();
        $enabled = $container->bound('config') ? $container->make('config')->get('task-manager.branch.enabled', false) : false;
        if (! is_bool($enabled)) {
            throw new DomainError('context_branch_configuration_invalid');
        }
        if ($enabled) {
            $container->make(ContextFactory::class)->validateExplicit($context);
        }
    }

    public static function validateActor(ExecutionContext $context, string $actor): void
    {
        self::validate(new ExecutionContext($context->scope, $actor, $context->correlationId, capabilities: []));
    }
}
