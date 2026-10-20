<?php

namespace Karnoweb\TaskManager\Contracts;

use Karnoweb\TaskManager\Context\ExecutionContext;

interface ExportStorage
{
    /** Writes bounded chunks into a private artifact; returns an opaque reference. */
    public function store(ExecutionContext $context, iterable $chunks): string;
    public function read(ExecutionContext $context, string $reference): mixed;
    public function delete(ExecutionContext $context, string $reference): void;
}
