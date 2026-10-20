<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\SLA\CalendarRegistry;
use Karnoweb\TaskManager\TaskGraph\CalendarLag;
use Karnoweb\TaskManager\TaskGraph\TaskActualClock;
use PHPUnit\Framework\Attributes\DataProvider;

final class DependencyCoreTest extends PhaseFourTestCase
{
    public function test_initial_work_category_enforces_dependencies_and_records_real_start(): void
    {
        $a = $this->todo('a'); $b = $this->todo('b'); $this->link($a, $b);
        foreach ([$a, $b] as $task) { $task = $this->service()->get($this->manager(), $task['public_key']); $this->service()->mutate($this->manager(), $task['public_key'], $task['version'], 'close-'.$task['id'], 'complete'); }
        $definition = $this->definition(); $definition['states']['new']['category'] = 'in_progress';
        $revision = $this->workflows()->publish($this->manager(), 'working', 0, 'working', $definition);
        $this->instances()->start($this->manager(), 'task', $a['public_key'], $revision['id'], 'restart-a');
        self::assertSame('2026-10-20 00:00:00', TaskActualClock::current(DB::connection(), 'one', $a['id'])['started_at']);
        $this->error('dependency_date_missing', fn () => $this->instances()->start($this->manager(), 'task', $b['public_key'], $revision['id'], 'restart-b'));
        self::assertSame(0, DB::table('tm_workflow_instances')->where('task_id', $b['id'])->where('workflow_revision_id', $revision['id'])->count());
    }
    public static function kinds(): array { return [['FS'], ['SS'], ['FF'], ['SF']]; }
    #[DataProvider('kinds')]
    public function test_all_four_actual_dependency_kinds_enforce_real_anchor_and_lag(string $type): void
    {
        [$revision, $project, $pred] = $this->projectTask(); $succ = $this->inProject($project, 'successor');
        $this->link($pred, $succ, $type, ['lag_minutes' => 5]);
        $finishing = in_array($type, ['FF', 'SF'], true);
        if ($finishing) { $this->step($succ, 'review', 'start-successor'); }
        $edge = $finishing ? 'approve' : 'review';
        $this->error('dependency_date_missing', fn () => $this->step($succ, $edge, 'missing-anchor'));
        $this->clock->advance('+10 minutes'); $this->step($pred, 'review', 'start-predecessor');
        if (in_array($type, ['FS', 'FF'], true)) { $this->step($pred, 'approve', 'finish-predecessor'); }
        $this->error('dependency_blocked', fn () => $this->step($succ, $edge, 'before-lag'));
        $this->clock->advance('+5 minutes'); $changed = $this->step($succ, $edge, 'allowed');
        self::assertSame($finishing ? 'won' : 'review', $changed['current_state_key']);
        $actual = TaskActualClock::current(DB::connection(), 'one', $pred['id']);
        self::assertSame('2026-10-20 00:10:00', $actual['started_at']);
        self::assertSame(in_array($type, ['FS', 'FF'], true) ? '2026-10-20 00:10:00' : null, $actual['finished_at']);
    }
    public function test_personal_complete_and_reopen_use_the_current_actual_cycle_not_planned_dates(): void
    {
        $a = $this->todo('a'); $b = $this->todo('b'); $this->link($a, $b);
        $this->error('dependency_date_missing', fn () => $this->service()->mutate($this->manager(), $b['public_key'], 2, 'early', 'complete'));
        $a = $this->service()->mutate($this->manager(), $a['public_key'], 2, 'done-a', 'complete');
        $this->service()->mutate($this->manager(), $a['public_key'], $a['version'], 'reopen-a', 'reopen');
        self::assertNull(TaskActualClock::current(DB::connection(), 'one', $a['id'])['finished_at']);
        $this->error('dependency_date_missing', fn () => $this->service()->mutate($this->manager(), $b['public_key'], 2, 'old-cycle', 'complete'));
        $a = $this->service()->get($this->manager(), $a['public_key']); $this->service()->mutate($this->manager(), $a['public_key'], $a['version'], 'done-again', 'complete');
        self::assertSame('done', $this->service()->mutate($this->manager(), $b['public_key'], 2, 'done-b', 'complete')['state']);
    }
    public function test_cycle_in_a_three_edge_batch_rolls_back_all_links_versions_and_outbox(): void
    {
        $a = $this->todo('a'); $b = $this->todo('b'); $c = $this->todo('c'); $outbox = DB::table('tm_outbox')->count();
        $items = [];
        foreach ([[$a, $b], [$b, $c], [$c, $a]] as [$pred, $succ]) { $items[] = ['predecessor' => $pred['public_key'], 'predecessor_version' => 1, 'successor' => $succ['public_key'], 'successor_version' => 1, 'type' => 'FS', 'policy' => []]; }
        $this->error('dependency_cycle', fn () => $this->dependencies()->addBatch($this->manager(), $items, 'cycle-batch'));
        self::assertSame(0, DB::table('tm_task_dependencies')->count()); self::assertSame($outbox, DB::table('tm_outbox')->count());
        self::assertSame(3, DB::table('tm_tasks')->where('version', 1)->count());
        $this->link($a, $b, operation: 'a-b'); $this->error('dependency_cycle', fn () => $this->link($b, $a, operation: 'b-a'));
    }
    public function test_related_links_do_not_block_and_current_permissions_filter_both_sides(): void
    {
        $a = $this->todo('a'); $b = $this->todo('b'); $link = $this->link($a, $b, 'relates_to');
        self::assertSame('done', $this->service()->mutate($this->manager(), $b['public_key'], 2, 'complete-b', 'complete')['state']);
        $b = $this->service()->get($this->manager(), $b['public_key']); $this->service()->share($this->manager(), $b['public_key'], $b['version'], 'share-b', 'b');
        self::assertSame(0, $this->dependencies()->links($this->manager('b'), $b['public_key'])['total']);
        $this->error('not_found', fn () => $this->dependencies()->get($this->manager('b'), $link['public_key']));
        self::assertSame(1, $this->dependencies()->links($this->manager(), $b['public_key'])['total']);
    }
    public function test_cross_scope_reference_is_explicitly_rejected_and_foreign_key_is_not_disclosed(): void
    {
        $a = $this->todo('a'); $other = $this->service()->create($this->manager('a', 'two'), 'other', 'other');
        $this->error('scope_mismatch', fn () => $this->dependencies()->addBatch($this->manager(), [['predecessor' => $a['public_key'], 'predecessor_version' => 1, 'successor' => $other['public_key'], 'successor_version' => 1, 'type' => 'FS', 'policy' => [], 'scope' => 'two']], 'scope'));
        $this->error('not_found', fn () => $this->dependencies()->add($this->manager(), $a['public_key'], 1, $other['public_key'], 1, 'FS', [], 'unknown-reference'));
        self::assertSame(0, DB::table('tm_task_dependencies')->count());
    }
    public function test_business_lag_skips_friday_and_negative_business_lag_is_computed_without_guessing(): void
    {
        $this->clock->time = new \DateTimeImmutable('2026-10-22T12:30:00Z');
        $a = $this->todo('a'); $b = $this->todo('b'); $this->link($a, $b, 'FS', ['lag_minutes' => 120, 'clock' => 'business', 'calendar' => 'iran-v1']);
        $this->service()->mutate($this->manager(), $a['public_key'], 2, 'finish-a', 'complete');
        $this->clock->time = new \DateTimeImmutable('2026-10-24T06:29:59Z');
        $this->error('dependency_blocked', fn () => $this->service()->mutate($this->manager(), $b['public_key'], 2, 'early-weekend', 'complete'));
        $this->clock->advance('+1 second'); self::assertSame('done', $this->service()->mutate($this->manager(), $b['public_key'], 2, 'after-weekend', 'complete')['state']);
        $back = CalendarLag::shift($this->clock->now(), -120, 'business', 'iran-v1', $this->app->make(CalendarRegistry::class));
        self::assertSame('2026-10-22T12:30:00+00:00', $back->format(DATE_ATOM));
    }
    public function test_warning_policy_records_a_durable_redacted_warning_without_forcing_business_failure(): void
    {
        $a = $this->todo('a'); $b = $this->todo('b'); $this->link($a, $b, 'FS', ['enforcement' => 'warning']);
        $done = $this->service()->mutate($this->manager(), $b['public_key'], 2, 'warning-complete', 'complete');
        self::assertSame('done', $done['state']); self::assertSame(1, DB::table('tm_outbox')->where('action', 'dependency_warning')->count());
        self::assertStringNotContainsString($a['public_key'], DB::table('tm_outbox')->where('action', 'dependency_warning')->value('payload'));
    }
}
