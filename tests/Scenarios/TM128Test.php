<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\OutboxDispatcher;
use Karnoweb\TaskManager\Tests\PackageTestCase;

final class TM128Test extends PackageTestCase
{
    public function test_scenario(): void
    {
        $s = $this->service();
        $c = $this->context();
        $t = $s->create($c, 'کار', 'create');
        self::assertSame(1, DB::table('tm_outbox')->whereNull('published_at')->count());
        $fresh = new OutboxDispatcher(DB::connection());
        self::assertSame(1, $fresh->dispatch('one', function ($event) use ($t) {
            self::assertSame($t['public_key'], json_decode($event['payload'], true)['task_id']);
        }));
        self::assertSame(0, DB::table('tm_outbox')->whereNull('published_at')->count());
    }
}
