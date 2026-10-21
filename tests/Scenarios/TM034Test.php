<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\TaskGraph\TaskActualClock;
use Karnoweb\TaskManager\Tests\PhaseFourTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class TM034Test extends PhaseFourTestCase
{
    #[DataProvider('kinds')]
    public function test_scenario(string $type): void
    {
        [$revision, $project, $pred] = $this->projectTask();
        $succ = $this->inProject($project, 'successor');
        $this->link($pred, $succ, $type, ['lag_minutes' => 5]);
        $finishing = in_array($type, ['FF', 'SF'], true);
        if ($finishing) {
            $this->step($succ, 'review', 'start-successor');
        }
        $edge = $finishing ? 'approve' : 'review';
        $this->error('dependency_date_missing', fn () => $this->step($succ, $edge, 'missing-anchor'));
        $this->clock->advance('+10 minutes');
        $this->step($pred, 'review', 'start-predecessor');
        if (in_array($type, ['FS', 'FF'], true)) {
            $this->step($pred, 'approve', 'finish-predecessor');
        }
        $this->error('dependency_blocked', fn () => $this->step($succ, $edge, 'before-lag'));
        $this->clock->advance('+5 minutes');
        $changed = $this->step($succ, $edge, 'allowed');
        self::assertSame($finishing ? 'won' : 'review', $changed['current_state_key']);
        $actual = TaskActualClock::current(DB::connection(), 'one', $pred['id']);
        self::assertSame('2026-10-20 00:10:00', $actual['started_at']);
        self::assertSame(in_array($type, ['FS', 'FF'], true) ? '2026-10-20 00:10:00' : null, $actual['finished_at']);
    }

    public static function kinds(): array
    {
        return [['FS'], ['SS'], ['FF'], ['SF']];
    }
}
