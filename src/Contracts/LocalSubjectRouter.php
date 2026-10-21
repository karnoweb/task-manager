<?php

namespace Karnoweb\TaskManager\Contracts;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\Context\ExecutionContext;

/** Host domain service; every write must use this exact transaction connection. */
interface LocalSubjectRouter
{
    public function connection(): Connection;

    public function move(ExecutionContext $context, array $origin, string $targetPipeline, string $operation): void;

    /** Return only a locally queryable subject alias and public ID; never private content. */
    public function spawn(ExecutionContext $context, array $origin, string $operation): array;
}
