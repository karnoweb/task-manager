<?php

namespace Karnoweb\TaskManager\Collaboration;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AttachmentStorage;
use Karnoweb\TaskManager\Exceptions\DomainError;

final class UnavailableAttachmentStorage implements AttachmentStorage
{
    public function inspect(ExecutionContext $context, string $reference): array
    {
        $context->validateTenant();
        throw new DomainError('attachment_storage_unregistered');
    }

    public function read(ExecutionContext $context, string $reference): mixed
    {
        $context->validateTenant();
        throw new DomainError('attachment_storage_unregistered');
    }
}
