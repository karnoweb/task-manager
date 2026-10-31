<?php

namespace Karnoweb\TaskManager\Contracts;

interface UserResolver
{
    /** Resolve the authenticated host object, never a browser-supplied actor ID. */
    public function resolve(): ?object;
}
