<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;

final class CollaborationCoreTest extends CollaborationTestCase
{
    public function test_independent_checklists_and_combined_task_progress_use_real_item_counts(): void
    {
        $task = $this->todo('task');
        $one = $this->checklists()->create($this->manager(), $task['public_key'], 'One', 'one');
        $two = $this->checklists()->create($this->manager(), $task['public_key'], 'Two', 'two');
        $a = $this->itemIn($one, 'a');
        $this->itemIn($one, 'b');
        $c = $this->itemIn($two, 'c');
        foreach ([$a, $c] as $item) {
            $this->checklists()->changeItem($this->manager(), $item['public_key'], 1, ['done' => true], 'done-'.$item['id']);
        }
        self::assertSame(50.0, $this->checklists()->items($this->manager(), $one['public_key'])['percent']);
        self::assertSame(100.0, $this->checklists()->items($this->manager(), $two['public_key'])['percent']);
        self::assertSame(['total' => 3, 'done' => 2, 'percent' => 66.67], $this->checklists()->progress($this->manager(), $task['public_key']));
    }

    public function test_item_assignment_due_filter_and_conversion_are_authorized_and_idempotent(): void
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

    public function test_comment_plain_text_safe_html_edit_history_window_and_moderation(): void
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

    public function test_mentions_and_watchers_filter_current_visibility_without_granting_permissions(): void
    {
        $task = $this->todo('task');
        $this->collaboration()->comment($this->manager(), $task['public_key'], 'secret', ['b'], null, 'before-share');
        self::assertSame(0, DB::table('tm_comment_mentions')->count());
        $task = $this->service()->share($this->manager(), $task['public_key'], 1, 'share', 'b');
        $comment = $this->collaboration()->comment($this->manager(), $task['public_key'], 'PRIVATE body', ['b', 'c'], null, 'mention');
        self::assertSame(1, DB::table('tm_comment_mentions')->count());
        $task = $this->collaboration()->watch($this->manager(), $task['public_key'], $task['version'], 'b', true, 'watch');
        self::assertSame(['b'], $this->collaboration()->notificationRecipients($this->manager(), $task['public_key'], $comment['public_key']));
        $this->error('watcher_ineligible', fn () => $this->collaboration()->watch($this->manager(), $task['public_key'], $task['version'], 'c', true, 'bad-watch'));
        $this->service()->share($this->manager(), $task['public_key'], $task['version'], 'revoke', 'b', revoke: true);
        self::assertSame([], $this->collaboration()->notificationRecipients($this->manager(), $task['public_key'], $comment['public_key']));
        $this->error('not_found', fn () => $this->resources()->get($this->manager('b'), 'comment', $comment['public_key']));
        self::assertStringNotContainsString('PRIVATE body', json_encode(DB::table('tm_outbox')->get()->all()));
    }

    public function test_watcher_toggle_changes_recipients_without_creating_access(): void
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

    public function test_reply_cannot_link_a_comment_from_another_task_and_rolls_back(): void
    {
        $a = $this->todo('a');
        $b = $this->todo('b');
        $comment = $this->collaboration()->comment($this->manager(), $a['public_key'], 'parent', [], null, 'parent');
        $this->error('not_found', fn () => $this->collaboration()->comment($this->manager(), $b['public_key'], 'reply', [], $comment['public_key'], 'wrong-reply'));
        self::assertSame(1, DB::table('tm_comments')->count());
        $reply = $this->collaboration()->comment($this->manager(), $a['public_key'], 'reply', [], $comment['public_key'], 'reply');
        self::assertSame($comment['id'], $reply['parent_comment_id']);
    }

    public function test_actual_file_download_tickets_are_actor_task_version_expiry_and_permission_bound(): void
    {
        $task = $this->todo('task');
        $other = $this->todo('other');
        $this->storage->put('opaque-file', 'one', 'a', 'actual file bytes');
        $file = $this->attachments()->attach($this->manager(), $task['public_key'], 'opaque-file', 'attach');
        $ticket = $this->attachments()->issueTicket($this->manager(), $task['public_key'], $file['public_key']);
        $stream = $this->attachments()->download($this->manager(), $task['public_key'], $file['public_key'], $ticket['token']);
        self::assertSame('actual file bytes', stream_get_contents($stream));
        fclose($stream);
        $this->error('not_found', fn () => $this->attachments()->download($this->manager(), $other['public_key'], $file['public_key'], $ticket['token']));
        $task = $this->service()->share($this->manager(), $task['public_key'], 1, 'share', 'b');
        $bTicket = $this->attachments()->issueTicket($this->manager('b'), $task['public_key'], $file['public_key']);
        $this->error('not_found', fn () => $this->attachments()->download($this->manager(), $task['public_key'], $file['public_key'], $bTicket['token']));
        $this->service()->share($this->manager(), $task['public_key'], $task['version'], 'revoke', 'b', revoke: true);
        $this->error('not_found', fn () => $this->attachments()->download($this->manager('b'), $task['public_key'], $file['public_key'], $bTicket['token']));
        $this->clock->advance('+121 seconds');
        $this->error('not_found', fn () => $this->attachments()->download($this->manager(), $task['public_key'], $file['public_key'], $ticket['token']));
        self::assertStringNotContainsString($ticket['token'], json_encode(DB::table('tm_attachment_tickets')->get()->all()));
        self::assertStringNotContainsString($ticket['token'], json_encode(DB::table('tm_command_journal')->get()->all()));
    }

    public function test_legacy_unverified_attachment_metadata_never_issues_a_file_download(): void
    {
        $task = $this->todo('task');
        $this->storage->put('real-file', 'one', 'a', 'secret');
        $file = $this->resources()->create($this->manager(), $task['public_key'], 'attachment', ['file_ref' => 'real-file', 'media_type' => 'text/plain', 'size' => 6], 'metadata');
        $this->error('not_found', fn () => $this->attachments()->issueTicket($this->manager(), $task['public_key'], $file['public_key']));
        self::assertSame(0, DB::table('tm_attachment_tickets')->count());
    }

    public function test_comment_soft_delete_restore_window_and_parent_revocation_preserve_history(): void
    {
        $task = $this->todo('task');
        $comment = $this->collaboration()->comment($this->manager(), $task['public_key'], 'retained', [], null, 'comment');
        $deleted = $this->collaboration()->editComment($this->manager(), $comment['public_key'], 1, null, 'delete');
        self::assertSame('', $deleted['body']);
        $restored = $this->collaboration()->restore($this->manager(), 'comment', $comment['public_key'], 2, 'restore');
        self::assertSame('retained', $restored['body']);
        $this->collaboration()->editComment($this->manager(), $comment['public_key'], 3, null, 'delete-again');
        $this->clock->advance('+31 days');
        $this->error('resource_restore_unavailable', fn () => $this->collaboration()->restore($this->manager(), 'comment', $comment['public_key'], 4, 'late-restore'));
        self::assertCount(4, $this->collaboration()->history($this->manager(), $comment['public_key']));
        $this->service()->mutate($this->manager(), $task['public_key'], 1, 'delete-task', 'delete');
        $this->error('not_found', fn () => $this->resources()->get($this->manager(), 'comment', $comment['public_key']));
    }

    public function test_checklist_comment_reads_and_item_writes_do_not_accept_an_unrelated_actor(): void
    {
        $task = $this->todo('task');
        $list = $this->checklists()->create($this->manager(), $task['public_key'], 'list', 'list');
        $item = $this->itemIn($list, 'item');
        $comment = $this->collaboration()->comment($this->manager(), $task['public_key'], 'private', [], null, 'comment');
        foreach ([fn () => $this->checklists()->get($this->manager('b'), $list['public_key']), fn () => $this->checklists()->item($this->manager('b'), $item['public_key']), fn () => $this->checklists()->changeItem($this->manager('b'), $item['public_key'], 1, ['done' => true], 'idor'), fn () => $this->resources()->get($this->manager('b'), 'comment', $comment['public_key'])] as $read) {
            $this->error('not_found', $read);
        }
    }
}
