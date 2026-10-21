<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Enterprise\CustomFieldService;
use Karnoweb\TaskManager\Enterprise\QueryService;
use Karnoweb\TaskManager\Tests\PhaseFiveTestCase;

final class TM134Test extends PhaseFiveTestCase
{
    public function test_scenario(): void
    {
        $a = $this->service()->create($this->manager(), 'كتاب ۱۲ نيم‌فاصله', 'a');
        $b = $this->service()->create($this->manager(), 'کتاب 12 نیم فاصله', 'b');
        $hidden = $this->service()->create($this->manager('b'), 'کتاب 12 نیم فاصله', 'hidden');
        $page = $this->queries()->tasks($this->manager(), ['q' => 'کتاب 12 نیم فاصله'], 'id', 'asc', 1);
        self::assertSame(2, $page['total']);
        self::assertCount(1, $page['items']);
        self::assertNotNull($page['cursor']);
        self::assertSame([$b['public_key']], array_column($this->queries()->tasks($this->manager(), ['q' => 'كتاب ۱۲ نيم‌فاصله'], 'id', 'asc', 1, $page['cursor'])['items'], 'public_key'));
        $this->service()->share($this->manager(), $a['public_key'], 1, 'share', 'b');
        $this->error('cursor_stale', fn () => $this->queries()->tasks($this->manager(), ['q' => 'کتاب 12 نیم فاصله'], 'id', 'asc', 1, $page['cursor']));
        self::assertSame(2, $this->queries()->tasks($this->manager('b'), ['q' => 'كتاب 12 نیم فاصله'])['total']);
        self::assertSame('كتاب ۱۲ نيم‌فاصله', $this->service()->get($this->manager(), $a['public_key'])['title']);
    }

    private function fields(): CustomFieldService
    {
        return $this->app->make(CustomFieldService::class);
    }

    private function queries(): QueryService
    {
        return $this->app->make(QueryService::class);
    }
}
