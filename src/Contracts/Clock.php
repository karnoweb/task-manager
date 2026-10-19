<?php

namespace Karnoweb\TaskManager\Contracts;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
