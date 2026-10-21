<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Scheduling\LocalTime;
use Karnoweb\TaskManager\Tests\PhaseFiveTestCase;

final class TM053Test extends PhaseFiveTestCase
{
    public function test_scenario(): void
    {
        $this->error('local_time_nonexistent', fn () => LocalTime::resolve('2026-03-08 02:30:00', 'America/New_York'));
        $this->error('local_time_ambiguous', fn () => LocalTime::resolve('2026-11-01 01:30:00', 'America/New_York'));
        self::assertSame(3600, LocalTime::resolve('2026-11-01 01:30:00', 'America/New_York', 'latest')->getTimestamp() - LocalTime::resolve('2026-11-01 01:30:00', 'America/New_York', 'earliest')->getTimestamp());
        $this->error('timezone_invalid', fn () => LocalTime::resolve('2026-10-21 01:00:00', 'Unknown/Zone'));
    }
}
