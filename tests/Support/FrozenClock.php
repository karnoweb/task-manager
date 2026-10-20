<?php

namespace Karnoweb\TaskManager\Tests\Support;

use Karnoweb\TaskManager\Contracts\Clock;

final class FrozenClock implements Clock
{
    public function __construct(public \DateTimeImmutable $time = new \DateTimeImmutable('2026-10-20T00:00:00Z')) {}
    public function now(): \DateTimeImmutable { return $this->time; }
    public function advance(string $interval): void { $this->time = $this->time->modify($interval); }
}
