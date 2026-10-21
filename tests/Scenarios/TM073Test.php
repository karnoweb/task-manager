<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\PackageTestCase;

final class TM073Test extends PackageTestCase
{
    public function test_scenario(): void
    {
        $s = $this->service();
        $c = $this->context();
        $s->create($c, 'خصوصی', 'create');
        $other = $this->context('a', 'two');
        self::assertSame([], $s->query($other));
        self::assertSame([], $s->facets($other));
        self::assertSame(0, $s->count($other));
        self::assertSame([], $s->lists($other));
    }
}
