<?php

namespace Karnoweb\TaskManager\Contracts;

use Karnoweb\TaskManager\Context\ExecutionContext;

/** Host rows may store a different scope value than the engine scope, such as a numeric branch id. */
interface ScopedSubject extends SubjectHandler
{
    public function scopeValue(ExecutionContext $context): string;
}
