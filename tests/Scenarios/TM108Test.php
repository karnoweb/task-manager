<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;

use Karnoweb\TaskManager\Tests\CollaborationTestCase;

final class TM108Test extends CollaborationTestCase
{
    public function test_scenario(): void
    {
        $task = $this->todo('task'); $this->collaboration()->comment($this->manager(), $task['public_key'], 'secret', ['b'], null, 'before-share'); self::assertSame(0, DB::table('tm_comment_mentions')->count());
        $task = $this->service()->share($this->manager(), $task['public_key'], 1, 'share', 'b');
        $comment = $this->collaboration()->comment($this->manager(), $task['public_key'], 'PRIVATE body', ['b', 'c'], null, 'mention'); self::assertSame(1, DB::table('tm_comment_mentions')->count());
        $task = $this->collaboration()->watch($this->manager(), $task['public_key'], $task['version'], 'b', true, 'watch'); self::assertSame(['b'], $this->collaboration()->notificationRecipients($this->manager(), $task['public_key'], $comment['public_key']));
        $this->error('watcher_ineligible', fn () => $this->collaboration()->watch($this->manager(), $task['public_key'], $task['version'], 'c', true, 'bad-watch'));
        $this->service()->share($this->manager(), $task['public_key'], $task['version'], 'revoke', 'b', revoke: true);
        self::assertSame([], $this->collaboration()->notificationRecipients($this->manager(), $task['public_key'], $comment['public_key']));
        $this->error('not_found', fn () => $this->resources()->get($this->manager('b'), 'comment', $comment['public_key']));
        self::assertStringNotContainsString('PRIVATE body', json_encode(DB::table('tm_outbox')->get()->all()));
    }

}
