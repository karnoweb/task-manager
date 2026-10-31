<?php

namespace Karnoweb\TaskManager\Integration;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\SecretVault;
use Karnoweb\TaskManager\Exceptions\DomainError;

final class UnavailableSecretVault implements SecretVault
{
    public function resolve(ExecutionContext $context, string $reference): string
    {
        $context->validateTenant();
        throw new DomainError('webhook_secret_unavailable');
    }
}
