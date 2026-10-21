<?php

namespace Karnoweb\TaskManager\Contracts;

use Karnoweb\TaskManager\Context\ExecutionContext;

/** The host owns campaigns and supplies current authorized closure evidence. */
interface GroupSourceProvider
{
    public function authorize(ExecutionContext $context, string $sourceId, string $action): void;

    /** Return immutable source revision and actual cutoff_at (DateTimeImmutable). */
    public function closure(ExecutionContext $context, string $sourceId): array;
}
