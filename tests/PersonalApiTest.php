<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\DTO\MutateTask;
use Karnoweb\TaskManager\DTO\TaskQuery;

final class PersonalApiTest extends PackageTestCase
{
    public function test_immutable_sessions_do_not_leak_context_and_query_has_constant_query_count(): void
    {
        $s = $this->service();
        $a = $s->inContext($this->context());
        $b = $s->inContext($this->context('b'));
        $first = $a->create('A', 'a');
        $b->create('B', 'b');
        $changed = $s->change(new MutateTask($this->context(), $first->publicKey, 1, 'edit', 'update', ['priority' => 'high']));
        self::assertSame(2, $changed->version);
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $rows = $s->findTasks(new TaskQuery($this->context()));
        self::assertCount(1, DB::connection()->getQueryLog());
        self::assertCount(1, $rows);
        self::assertSame('A', $rows[0]->title);
        self::assertSame('done', $a->complete($first->publicKey, 2, 'done')->state);
        self::assertSame('A', $a->get($first->publicKey)->title);
    }

    public function test_list_optimistic_lifecycle_and_inbox_protection(): void
    {
        $s = $this->service();
        $c = $this->context();
        $task = $s->create($c, 'task', 'task');
        $list = $s->createList($c, 'Work', 'list');
        $updated = $s->changeList($c, $list['list_id'], 1, 'rename', ['name' => 'Office', 'rank' => '2.5']);
        self::assertSame('Office', $updated['name']);
        self::assertSame(2, $updated['version']);
        $this->error('version_conflict', fn () => $s->changeList($c, $list['list_id'], 1, 'stale', ['archived' => true]));
        $s->changeList($c, $list['list_id'], 2, 'archive', ['archived' => true]);
        self::assertCount(1, $s->lists($c));
        $this->error('list_archived', fn () => $s->mutate($c, $task['public_key'], 1, 'move', 'move_list', ['list_id' => $list['list_id']]));
        $s->changeList($c, $list['list_id'], 3, 'restore', ['archived' => false]);
        $s->mutate($c, $task['public_key'], 1, 'move-again', 'move_list', ['list_id' => $list['list_id']]);
        $this->error('list_not_empty', fn () => $s->changeList($c, $list['list_id'], 4, 'bad-archive', ['archived' => true]));
        $this->error('inbox_immutable', fn () => $s->changeList($c, $task['list_id'], 1, 'inbox', ['name' => 'Changed']));
        self::assertCount(2, $s->lists($c));
    }
}
