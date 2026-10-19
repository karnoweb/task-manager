<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\OutboxDispatcher;
use Karnoweb\TaskManager\Tests\PackageTestCase;

final class TM009Test extends PackageTestCase
{
    public function test_scenario(): void
    {
        $s = $this->service(); $c = $this->context();
        $t = $s->create($c, 'خصوصی', 'create'); $b = $this->context('b'); self::assertSame(0, $s->count($b)); self::assertSame([], $s->query($b)); $this->error('not_found', fn () => $s->get($b, $t['public_key'])); $this->error('not_found', fn () => $s->mutate($b, $t['public_key'], 1, 'edit', 'update', ['title' => 'غیرمجاز']));
    }
}

