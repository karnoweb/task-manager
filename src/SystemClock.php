<?php

namespace Karnoweb\TaskManager;

use Karnoweb\TaskManager\Contracts\Clock;

final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
