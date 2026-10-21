<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Planning\BulkService;
use Karnoweb\TaskManager\Tests\PhaseFourTestCase;

final class TM075Test extends PhaseFourTestCase
{
    public function test_scenario(): void
    {
        $a = $this->todo('a');
        $b = $this->service()->create($this->manager('b'), 'Private', 'private');
        $bulk = $this->app->make(BulkService::class);
        $items = [['task_key' => $a['public_key'], 'version' => 1, 'action' => 'complete'], ['task_key' => $b['public_key'], 'version' => 1, 'action' => 'complete']];
        $before = DB::table('tm_outbox')->count();
        $this->error('not_found', fn () => $bulk->execute($this->manager(), $items, 'atomic', 'atomic'));
        self::assertSame('pending', $this->service()->get($this->manager(), $a['public_key'])['state']);
        self::assertSame($before, DB::table('tm_outbox')->count());
        $result = $bulk->execute($this->manager(), $items, 'partial', 'partial');
        self::assertSame('completed', $result['items'][0]['status']);
        self::assertSame('not_found', $result['items'][1]['reason_code']);
        self::assertArrayNotHasKey('task_key', $result['items'][1]);
        self::assertSame('done', $this->service()->get($this->manager(), $a['public_key'])['state']);
        self::assertSame($result, $bulk->execute($this->manager(), $items, 'partial', 'partial'));
        self::assertSame(2, $this->service()->get($this->manager(), $a['public_key'])['version']);
    }
}
