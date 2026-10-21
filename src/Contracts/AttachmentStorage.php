<?php

namespace Karnoweb\TaskManager\Contracts;

use Karnoweb\TaskManager\Context\ExecutionContext;

interface AttachmentStorage
{
    /** Trusted read-only inspection must authorize this actor/scope to attach the opaque reference. */
    public function inspect(ExecutionContext $context, string $reference): array;

    /** Called only after fresh core ticket and parent authorization; return a readable stream or bytes. */
    public function read(ExecutionContext $context, string $reference): mixed;
}
