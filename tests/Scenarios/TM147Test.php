<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\CollaborationTestCase;

final class TM147Test extends CollaborationTestCase
{
    public function test_scenario(): void
    {
        $task = $this->todo('task');
        $task = $this->service()->share($this->manager(), $task['public_key'], 1, 'share', 'b');
        $task = $this->collaboration()->watch($this->manager(), $task['public_key'], $task['version'], 'b', true, 'watch');
        self::assertSame(['b'], $this->collaboration()->notificationRecipients($this->manager(), $task['public_key']));
        $task = $this->collaboration()->watch($this->manager(), $task['public_key'], $task['version'], 'b', false, 'unwatch');
        self::assertSame([], $this->collaboration()->notificationRecipients($this->manager(), $task['public_key']));
        $this->service()->share($this->manager(), $task['public_key'], $task['version'], 'revoke', 'b', revoke: true);
        $this->error('not_found', fn () => $this->service()->get($this->manager('b'), $task['public_key']));
        self::assertSame(0, DB::table('tm_task_watchers')->count());
    }
}
