<?php

namespace Karnoweb\TaskManager\Contracts;

interface DnsResolver
{
    /** All A/AAAA addresses; any private answer makes the endpoint unsafe. */
    public function addresses(string $host): array;
}
