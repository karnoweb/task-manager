<?php

namespace Karnoweb\TaskManager\Contracts;

use Karnoweb\TaskManager\Context\ExecutionContext;

interface AuthorizationRevision
{
    public function revision(ExecutionContext $context): string;
}
