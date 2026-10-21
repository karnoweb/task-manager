<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Tests\CollaborationTestCase;

final class TM044Test extends CollaborationTestCase
{
    public function test_scenario(): void
    {
        $task = $this->todo('task');
        $base = $this->manager();
        $author = new ExecutionContext('one', 'a', 'comment', capabilities: array_values(array_diff($base->capabilities, ['comment.moderate'])));
        $comment = $this->collaboration()->comment($author, $task['public_key'], 'PRIVATE <script>alert(1)</script> &lt;img onerror=x&gt;', [], null, 'comment');
        self::assertStringNotContainsString('<script', $comment['body_html']);
        self::assertStringNotContainsString('<img', $comment['body_html']);
        $this->clock->advance('+10 minutes');
        $edited = $this->collaboration()->editComment($author, $comment['public_key'], 1, 'PRIVATE edited', 'edit');
        self::assertSame(2, $edited['version']);
        $this->clock->advance('+6 minutes');
        $this->error('capability_denied', fn () => $this->collaboration()->editComment($author, $comment['public_key'], 2, 'late', 'late'));
        $this->collaboration()->editComment($base, $comment['public_key'], 2, 'moderated', 'moderated');
        self::assertCount(3, $this->collaboration()->history($author, $comment['public_key']));
        self::assertStringNotContainsString('PRIVATE', json_encode(DB::table('tm_audit_events')->get()->all()));
    }
}
