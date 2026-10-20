<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\SLA\CalendarRegistry;
use Karnoweb\TaskManager\TaskGraph\CalendarLag;
use Karnoweb\TaskManager\TaskGraph\TaskActualClock;
use PHPUnit\Framework\Attributes\DataProvider;

use Karnoweb\TaskManager\Tests\PhaseFourTestCase;

final class TM038Test extends PhaseFourTestCase
{
    public function test_scenario(): void
    {
        $a = $this->todo('a'); $other = $this->service()->create($this->manager('a', 'two'), 'other', 'other');
        $this->error('scope_mismatch', fn () => $this->dependencies()->addBatch($this->manager(), [['predecessor' => $a['public_key'], 'predecessor_version' => 1, 'successor' => $other['public_key'], 'successor_version' => 1, 'type' => 'FS', 'policy' => [], 'scope' => 'two']], 'scope'));
        $this->error('not_found', fn () => $this->dependencies()->add($this->manager(), $a['public_key'], 1, $other['public_key'], 1, 'FS', [], 'unknown-reference'));
        self::assertSame(0, DB::table('tm_task_dependencies')->count());
    }
    public static function kinds(): array { return [['FS'], ['SS'], ['FF'], ['SF']]; }
}
