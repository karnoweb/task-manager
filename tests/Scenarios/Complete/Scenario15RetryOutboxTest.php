<?php

namespace Karnoweb\TaskManager\Tests\Scenarios\Complete;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\OutboxDispatcher;
use Karnoweb\TaskManager\Tests\PackageTestCase;

final class Scenario15RetryOutboxTest extends PackageTestCase
{
    public function test_complete_scenario_15(): void
    {

        $task = $this->service()->create($this->context(), 'event', 'create');
        self::assertSame($task, $this->service()->create($this->context(), 'event', 'create'));
        $this->error('idempotency_key_conflict', fn () => $this->service()->create($this->context(), 'changed', 'create'));
        $this->error('version_conflict', fn () => $this->service()->mutate($this->context(), $task['public_key'], 0, 'stale', 'update', ['title' => 'Invalid']));
        try {
            DB::transaction(function () {
                $this->service()->create($this->context(), 'Rolled back', 'rollback');
                throw new \RuntimeException('rollback');
            });
            self::fail('Rollback expected');
        } catch (\RuntimeException $e) {
            self::assertSame('rollback', $e->getMessage());
        }
        self::assertSame(1, DB::table('tm_tasks')->count());
        $dispatcher = new OutboxDispatcher(DB::connection());
        $eventId = null;
        try {
            $dispatcher->dispatch('one', function ($event) use (&$eventId) {
                $eventId = $event['event_id'];
                throw new \RuntimeException('crash');
            });
            self::fail('Crash expected');
        } catch (\RuntimeException $e) {
            self::assertSame('crash', $e->getMessage());
        }
        self::assertSame(1, DB::table('tm_outbox')->whereNull('published_at')->count());
        self::assertSame(1, $dispatcher->dispatch('one', function ($event) use ($eventId) {
            self::assertSame($eventId, $event['event_id']);
        }));
        self::assertSame(0, $dispatcher->dispatch('one', fn () => self::fail('Duplicate acknowledged event')));
        self::assertSame(2, DB::table('tm_outbox')->value('attempts'));
        self::assertSame(1, DB::table('tm_tasks')->count());

    }
}
