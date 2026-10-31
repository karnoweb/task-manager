<?php

namespace Karnoweb\TaskManager\Tests\Scenarios\Complete;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Contracts\UserResolver;
use Karnoweb\TaskManager\Facades\TaskManager;
use Karnoweb\TaskManager\Tests\PackageTestCase;

final class Scenario01TodoTest extends PackageTestCase
{
    public function test_complete_scenario_01(): void
    {

        $this->app->instance(UserResolver::class, fn () => Auth::user());
        config(['task-manager.scope.default' => 'one']);
        Auth::setUser(new GenericUser(['id' => 'a']));
        $a = TaskManager::tasks()->inContext();
        $first = $a->create('A', 'create');
        self::assertEquals($first, $a->create('A', 'create'));
        $changed = $a->update($first->publicKey, 1, 'edit', ['title' => 'Updated']);
        self::assertSame(2, $changed->version);
        $done = $a->complete($first->publicKey, 2, 'done');
        self::assertSame('done', $done->state);
        $open = $a->reopen($first->publicKey, $done->version, 'reopen');
        self::assertSame('pending', $open->state);
        $archived = $a->archive($first->publicKey, $open->version, 'archive');
        self::assertCount(0, $a->query());
        $a->restore($first->publicKey, $archived->version, 'restore');
        self::assertCount(1, $a->query());
        Auth::setUser(new GenericUser(['id' => 'b']));
        $b = TaskManager::tasks()->inContext();
        self::assertCount(0, $b->query());
        $this->error('not_found', fn () => $b->get($first->publicKey));
        self::assertSame('Updated', $a->get($first->publicKey)->title);
        self::assertSame(1, DB::table('tm_tasks')->count());

    }
}
