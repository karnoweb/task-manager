<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;

use Karnoweb\TaskManager\Tests\CollaborationTestCase;

final class TM074Test extends CollaborationTestCase
{
    public function test_scenario(): void
    {
        $task = $this->todo('task'); $list = $this->checklists()->create($this->manager(), $task['public_key'], 'list', 'list'); $item = $this->itemIn($list, 'item'); $comment = $this->collaboration()->comment($this->manager(), $task['public_key'], 'private', [], null, 'comment');
        foreach ([fn () => $this->checklists()->get($this->manager('b'), $list['public_key']), fn () => $this->checklists()->item($this->manager('b'), $item['public_key']), fn () => $this->checklists()->changeItem($this->manager('b'), $item['public_key'], 1, ['done' => true], 'idor'), fn () => $this->resources()->get($this->manager('b'), 'comment', $comment['public_key'])] as $read) { $this->error('not_found', $read); }
    }

}
