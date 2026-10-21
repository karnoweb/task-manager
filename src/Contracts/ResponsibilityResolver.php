<?php

namespace Karnoweb\TaskManager\Contracts;

use Karnoweb\TaskManager\Context\ExecutionContext;

interface ResponsibilityResolver
{
    /** @return list<string> Current eligible host actor references, scoped by context. */
    public function candidates(ExecutionContext $context, string $reference, string $purpose): array;

    public function eligible(ExecutionContext $context, string $actor, string $purpose): bool;
}
