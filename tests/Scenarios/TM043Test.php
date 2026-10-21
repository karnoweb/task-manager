<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\CollaborationTestCase;

final class TM043Test extends CollaborationTestCase
{
    public function test_scenario(): void
    {
        $task = $this->todo('task');
        $list = $this->checklists()->create($this->manager(), $task['public_key'], 'List', 'list');
        $due = new \DateTimeImmutable('2026-10-21T08:00:00Z');
        $item = $this->checklists()->addItem($this->manager(), $list['public_key'], 1, 'Convert me', 'a', $due, 'item');
        self::assertSame(1, $this->checklists()->items($this->manager(), $list['public_key'], 'a', $due)['total']);
        self::assertSame(0, $this->checklists()->items($this->manager(), $list['public_key'], 'b', $due)['total']);
        $this->error('assignee_ineligible', fn () => $this->checklists()->changeItem($this->manager(), $item['public_key'], 1, ['assignee_ref' => 'b'], 'bad-assignee'));
        $converted = $this->checklists()->convert($this->manager(), $item['public_key'], 1, 'convert');
        $again = $this->checklists()->convert($this->manager(), $item['public_key'], 1, 'convert');
        self::assertSame($converted['public_key'], $again['public_key']);
        self::assertSame($task['id'], $converted['parent_task_id']);
        self::assertSame(2, DB::table('tm_tasks')->count());
        self::assertSame($converted['id'], $this->checklists()->item($this->manager(), $item['public_key'])['converted_task_id']);
    }
}
