<?php

namespace Karnoweb\TaskManager\Contracts;

use Karnoweb\TaskManager\Context\ExecutionContext;

interface SecretVault
{
    public function resolve(ExecutionContext $context, string $reference): string;
}
