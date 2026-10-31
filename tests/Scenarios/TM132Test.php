<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\Calendar\Data\DateRange;
use Karnoweb\Calendar\Data\EventQuery;
use Karnoweb\TaskManager\Tests\PhaseSixTestCase;

final class TM132Test extends PhaseSixTestCase
{
    public function test_scenario(): void
    {
        $t = $this->service()->create($this->manager(), 'Due only', 'create');
        $t = $this->dates()->change($this->manager(), $t['public_key'], $t['version'], null, null, '2026-10-22 09:00:00', 'UTC', false, 'reject', 'due');
        $q = new EventQuery(DateRange::ofLocalDays('2026-10-22', '2026-10-23', 'UTC'));
        $page = $this->calendar()->fetch($q, $this->uiCalendar());
        self::assertCount(1, $page->events);
        self::assertSame('tm.due', $page->events[0]->reference->sourceKey);
        self::assertSame($t['public_key'], $page->events[0]->reference->eventId);
        $card = $this->board()->card($this->uiBoard(), 'tasks:'.$t['public_key']);
        self::assertSame($t['public_key'], $card->metadata['task_key']);
        self::assertSame($t['due_at'], $card->dueAt);
        self::assertSame(1, DB::table('tm_tasks')->count());
    }
}
