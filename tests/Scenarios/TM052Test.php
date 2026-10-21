<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\PhaseFiveTestCase;

final class TM052Test extends PhaseFiveTestCase
{
    public function test_scenario(): void
    {
        $task = $this->todo('span');
        $this->dates()->change($this->manager(), $task['public_key'], 1, '2026-10-20 09:00:00', '2026-10-22 09:00:00', null, 'Asia/Tehran', false, 'reject', 'span');
        self::assertSame([], $this->dates()->calendar($this->manager(), new \DateTimeImmutable('2026-10-22T05:30:00Z'), new \DateTimeImmutable('2026-10-23T05:30:00Z'))['events']);
        $due = $this->todo('due');
        $this->dates()->change($this->manager(), $due['public_key'], 1, null, null, '2026-10-22 12:00:00', 'Asia/Tehran', false, 'reject', 'due');
        $events = $this->dates()->calendar($this->manager(), new \DateTimeImmutable('2026-10-22T06:00:00Z'), new \DateTimeImmutable('2026-10-23T06:00:00Z'))['events'];
        self::assertSame(['task_due'], array_column($events, 'kind'));
        foreach (['America/New_York', 'Asia/Tehran'] as $zone) {
            $a = $this->todo($zone);
            $a = $this->dates()->change($this->manager(), $a['public_key'], 1, '2026-11-01', '2026-11-03', null, $zone, true, 'earliest', 'all-day-'.$zone);
            self::assertSame('2026-11-03', $a['local_end_date']);
            self::assertSame(2, (new \DateTimeImmutable($a['local_start_date']))->diff(new \DateTimeImmutable($a['local_end_date']))->days);
        }
        self::assertSame(0, $this->dates()->calendar($this->manager('b'), new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-12-01'))['total_tasks']);
    }
}
