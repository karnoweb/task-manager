<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\SLA\CalendarRegistry;
use Karnoweb\TaskManager\TaskGraph\CalendarLag;
use Karnoweb\TaskManager\TaskGraph\TaskActualClock;
use PHPUnit\Framework\Attributes\DataProvider;

use Karnoweb\TaskManager\Tests\PhaseFourTestCase;

final class TM036Test extends PhaseFourTestCase
{
    public function test_scenario(): void
    {
        $a = $this->todo('a'); $b = $this->todo('b'); $c = $this->todo('c'); $outbox = DB::table('tm_outbox')->count();
        $items = [];
        foreach ([[$a, $b], [$b, $c], [$c, $a]] as [$pred, $succ]) { $items[] = ['predecessor' => $pred['public_key'], 'predecessor_version' => 1, 'successor' => $succ['public_key'], 'successor_version' => 1, 'type' => 'FS', 'policy' => []]; }
        $this->error('dependency_cycle', fn () => $this->dependencies()->addBatch($this->manager(), $items, 'cycle-batch'));
        self::assertSame(0, DB::table('tm_task_dependencies')->count()); self::assertSame($outbox, DB::table('tm_outbox')->count());
        self::assertSame(3, DB::table('tm_tasks')->where('version', 1)->count());
        $this->link($a, $b, operation: 'a-b'); $this->error('dependency_cycle', fn () => $this->link($b, $a, operation: 'b-a'));
    }
    public static function kinds(): array { return [['FS'], ['SS'], ['FF'], ['SF']]; }
}
