<?php

namespace Karnoweb\TaskManager\Enterprise;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationRevision;

/** Hosts with external dynamic ACLs bind their durable authorization revision here. */
final class PackageAuthorizationRevision implements AuthorizationRevision
{
    public function revision(ExecutionContext $context): string
    {
        $context->validateTenant();

        return 'package';
    }
}
