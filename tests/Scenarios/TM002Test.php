<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\PackageTestCase;

final class TM002Test extends PackageTestCase
{
    public function test_scenario(): void
    {
        $s = $this->service();
        $c = $this->context();
        foreach (['', ' ', str_repeat('آ', 256)] as $title) {
            $this->error('title_invalid', fn () => $s->create($c, $title, 'bad'));
        } self::assertSame(0, DB::table('tm_tasks')->count());
        self::assertSame(0, DB::table('tm_outbox')->count());
    }
}
