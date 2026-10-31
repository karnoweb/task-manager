<?php

namespace Karnoweb\TaskManager\Contracts;

interface ScopeResolver
{
    /** When branches are enabled, return the canonical branch:<identifier> scope. */
    public function resolve(object $user, ?string $branch): int|string|null;
}
