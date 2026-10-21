<?php

namespace Karnoweb\TaskManager\Contracts;

use Illuminate\Database\Query\Builder;
use Karnoweb\TaskManager\Context\ExecutionContext;

interface SubjectHandler
{
    /** Query must apply current host permissions; the engine adds its scope predicate. */
    public function query(ExecutionContext $context, string $capability): Builder;

    public function idColumn(): string;

    public function scopeColumn(): string;

    /** Same-connection host validation only; external effects belong in the outbox. */
    public function guard(ExecutionContext $context, string $subjectId, array $instance, string $action, array $payload): void;
}
