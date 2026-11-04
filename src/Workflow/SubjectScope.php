<?php

namespace Karnoweb\TaskManager\Workflow;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\ScopedSubject;
use Karnoweb\TaskManager\Contracts\SubjectHandler;

final class SubjectScope
{
    public static function value(SubjectHandler $handler, ExecutionContext $context): string
    {
        return $handler instanceof ScopedSubject ? $handler->scopeValue($context) : $context->scope;
    }
}
