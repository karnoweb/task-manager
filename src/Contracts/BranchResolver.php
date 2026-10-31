<?php

namespace Karnoweb\TaskManager\Contracts;

interface BranchResolver
{
    /** Validate membership for an explicit/default branch, or resolve a trusted current branch. */
    public function resolve(object $user, int|string|null $requested): object|int|string|null;
}
