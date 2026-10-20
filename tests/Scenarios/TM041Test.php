<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;

use Karnoweb\TaskManager\Tests\CollaborationTestCase;

final class TM041Test extends CollaborationTestCase
{
    public function test_scenario(): void
    {
        $task = $this->todo('task'); $one = $this->checklists()->create($this->manager(), $task['public_key'], 'One', 'one'); $two = $this->checklists()->create($this->manager(), $task['public_key'], 'Two', 'two');
        $a = $this->itemIn($one, 'a'); $this->itemIn($one, 'b'); $c = $this->itemIn($two, 'c');
        foreach ([$a, $c] as $item) { $this->checklists()->changeItem($this->manager(), $item['public_key'], 1, ['done' => true], 'done-'.$item['id']); }
        self::assertSame(50.0, $this->checklists()->items($this->manager(), $one['public_key'])['percent']); self::assertSame(100.0, $this->checklists()->items($this->manager(), $two['public_key'])['percent']);
        self::assertSame(['total' => 3, 'done' => 2, 'percent' => 66.67], $this->checklists()->progress($this->manager(), $task['public_key']));
    }

}
