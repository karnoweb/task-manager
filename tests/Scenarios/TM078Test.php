<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\OutboxDispatcher;
use Karnoweb\TaskManager\Tests\PackageTestCase;

final class TM078Test extends PackageTestCase
{
    public function test_scenario(): void
    {
        $s = $this->service();
        $c = $this->context();
        DB::beginTransaction();
        $s->create($c, 'کار', 'create');
        $d = new OutboxDispatcher(DB::connection());
        $this->error('dispatch_before_commit', fn () => $d->dispatch('one', fn () => self::fail('Early event')));
        DB::commit();
        $seen = [];
        self::assertSame(1, $d->dispatch('one', function ($event) use (&$seen) {
            $seen[] = $event['event_id'];
        }));
        self::assertCount(1, $seen);
    }
}
