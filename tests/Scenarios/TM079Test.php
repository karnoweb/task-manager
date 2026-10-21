<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\OutboxDispatcher;
use Karnoweb\TaskManager\Tests\PackageTestCase;

final class TM079Test extends PackageTestCase
{
    public function test_scenario(): void
    {
        $s = $this->service();
        $c = $this->context();
        $s->create($c, 'کار', 'create');
        $d = new OutboxDispatcher(DB::connection());
        try {
            $d->dispatch('one', fn () => throw new \RuntimeException('crash'));
        } catch (\RuntimeException $e) {
            self::assertSame('crash', $e->getMessage());
        } self::assertSame(1, DB::table('tm_outbox')->whereNull('published_at')->count());
        self::assertSame(1, $d->dispatch('one', fn ($event) => self::assertNotEmpty($event['event_id'])));
        self::assertSame(1, DB::table('tm_tasks')->count());
    }
}
