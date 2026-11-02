<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Workflow\SubjectTaskService;

final class SubjectTaskLinkTest extends WorkflowTestCase
{
    private function subjects(): SubjectTaskService
    {
        return $this->app->make(SubjectTaskService::class);
    }

    private function refused(string $code, callable $call): void
    {
        try {
            $call();
        } catch (DomainError $e) {
            self::assertSame($code, $e->getMessage());

            return;
        }
        self::fail("Expected DomainError {$code}.");
    }

    public function test_link_associates_an_existing_task_and_is_idempotent(): void
    {
        $this->host('lead-1');
        $task = $this->service()->create($this->manager(), 'Standalone', 'task-1');

        $first = $this->subjects()->link($this->manager(), 'crm.lead', 'lead-1', $task['public_key'], 'link-1');
        self::assertSame(['task_key' => $task['public_key'], 'subject_type' => 'crm.lead', 'subject_id' => 'lead-1'], $first);
        self::assertSame($first, $this->subjects()->link($this->manager(), 'crm.lead', 'lead-1', $task['public_key'], 'link-1'));
        self::assertSame(1, DB::table('tm_host_task_links')->count());
        self::assertSame(1, DB::table('tm_audit_events')->where('action', 'host_subject_linked')->count());

        // A different operation for the same pair never duplicates the association or its event.
        $this->subjects()->link($this->manager(), 'crm.lead', 'lead-1', $task['public_key'], 'link-2');
        self::assertSame(1, DB::table('tm_host_task_links')->count());
        self::assertSame(1, DB::table('tm_audit_events')->where('action', 'host_subject_linked')->count());
        self::assertSame('crm.lead', $this->subjects()->subjectOf($this->manager(), $task['public_key'])['subject_type']);
    }

    public function test_link_rejects_reused_operation_with_other_payload(): void
    {
        $this->host('lead-1');
        $this->host('lead-2');
        $task = $this->service()->create($this->manager(), 'Standalone', 'task-1');
        $this->subjects()->link($this->manager(), 'crm.lead', 'lead-1', $task['public_key'], 'link-1');

        $this->refused('idempotency_key_conflict', fn () => $this->subjects()->link($this->manager(), 'crm.lead', 'lead-2', $task['public_key'], 'link-1'));
    }

    public function test_link_enforces_scope_subject_access_and_task_visibility(): void
    {
        $this->host('lead-1');
        $this->host('lead-other-scope', scope: 'two');
        $this->host('lead-readonly', actors: []);
        DB::table('host_permissions')->insert(['subject_id' => 'lead-readonly', 'actor' => 'a', 'can_edit' => false]);
        $task = $this->service()->create($this->manager(), 'Mine', 'task-1');
        $foreign = $this->service()->create($this->manager('b'), 'Foreign', 'task-2');

        $this->refused('not_found', fn () => $this->subjects()->link($this->manager(), 'crm.lead', 'lead-other-scope', $task['public_key'], 'x1'));
        $this->refused('not_found', fn () => $this->subjects()->link($this->manager(), 'crm.lead', 'lead-readonly', $task['public_key'], 'x2'));
        $this->refused('not_found', fn () => $this->subjects()->link($this->manager(), 'crm.lead', 'lead-1', $foreign['public_key'], 'x3'));
        $this->refused('not_found', fn () => $this->subjects()->link($this->manager(), 'crm.lead', 'lead-1', 'not-a-uuid', 'x4'));
        self::assertSame(0, DB::table('tm_host_task_links')->count());

        // A task of another scope is invisible even to the same actor.
        $otherScope = $this->service()->create($this->manager('a', 'two'), 'Scope two', 'task-3');
        $this->refused('not_found', fn () => $this->subjects()->link($this->manager(), 'crm.lead', 'lead-1', $otherScope['public_key'], 'x5'));
        self::assertSame(0, DB::table('tm_host_task_links')->count());
    }

    public function test_move_repoints_links_for_a_merge_and_is_idempotent(): void
    {
        $this->host('from');
        $this->host('to');
        $one = $this->subjects()->create($this->manager(), 'crm.lead', 'from', 'One', 'create-1');
        $two = $this->subjects()->create($this->manager(), 'crm.lead', 'from', 'Two', 'create-2');

        $moved = $this->subjects()->move($this->manager(), 'crm.lead', 'from', 'crm.lead', 'to', 'move-1');
        self::assertSame(2, $moved['moved']);
        self::assertSame($moved, $this->subjects()->move($this->manager(), 'crm.lead', 'from', 'crm.lead', 'to', 'move-1'));
        self::assertSame([], $this->subjects()->tasks($this->manager(), 'crm.lead', 'from'));
        self::assertEqualsCanonicalizing([$one['task_key'], $two['task_key']], array_column($this->subjects()->tasks($this->manager(), 'crm.lead', 'to'), 'public_key'));
        self::assertSame(2, DB::table('tm_audit_events')->where('action', 'host_subject_moved')->count());

        // Moving again moves nothing and changes nothing.
        self::assertSame(0, $this->subjects()->move($this->manager(), 'crm.lead', 'from', 'crm.lead', 'to', 'move-2')['moved']);
        self::assertSame(2, DB::table('tm_audit_events')->where('action', 'host_subject_moved')->count());
    }

    public function test_move_keeps_a_single_association_when_the_destination_already_has_the_task(): void
    {
        $this->host('from');
        $this->host('to');
        $task = $this->subjects()->create($this->manager(), 'crm.lead', 'from', 'Shared', 'create-1');
        $this->subjects()->link($this->manager(), 'crm.lead', 'to', $task['task_key'], 'link-1');

        $this->subjects()->move($this->manager(), 'crm.lead', 'from', 'crm.lead', 'to', 'move-1');

        self::assertSame(1, DB::table('tm_host_task_links')->count());
        self::assertSame('to', DB::table('tm_host_task_links')->value('subject_id'));
    }

    public function test_move_does_not_need_to_read_the_source_subject(): void
    {
        $this->host('from');
        $this->host('to');
        $task = $this->subjects()->create($this->manager(), 'crm.lead', 'from', 'Orphaned', 'create-1');
        // The source record disappears from the host view (merged, archived or removed).
        DB::table('host_permissions')->where('subject_id', 'from')->delete();

        self::assertSame(1, $this->subjects()->move($this->manager(), 'crm.lead', 'from', 'crm.lead', 'to', 'move-1')['moved']);
        self::assertSame([$task['task_key']], array_column($this->subjects()->tasks($this->manager(), 'crm.lead', 'to'), 'public_key'));
    }

    public function test_move_enforces_scope_destination_access_and_task_edit_rights(): void
    {
        $this->host('from');
        $this->host('to');
        $this->host('to-other-scope', scope: 'two');
        $this->host('to-readonly', actors: []);
        DB::table('host_permissions')->insert(['subject_id' => 'to-readonly', 'actor' => 'a', 'can_edit' => false]);
        $this->subjects()->create($this->manager(), 'crm.lead', 'from', 'Mine', 'create-1');

        $this->refused('not_found', fn () => $this->subjects()->move($this->manager(), 'crm.lead', 'from', 'crm.lead', 'to-other-scope', 'm1'));
        $this->refused('not_found', fn () => $this->subjects()->move($this->manager(), 'crm.lead', 'from', 'crm.lead', 'to-readonly', 'm2'));
        $this->refused('subject_move_invalid', fn () => $this->subjects()->move($this->manager(), 'crm.lead', 'from', 'crm.lead', 'from', 'm3'));
        self::assertSame('from', DB::table('tm_host_task_links')->value('subject_id'));

        // Another actor who can edit the destination but not the tasks must not move them.
        DB::table('host_permissions')->insert(['subject_id' => 'to', 'actor' => 'b', 'can_edit' => true]);
        $this->refused('access_denied', fn () => $this->subjects()->move($this->manager('b'), 'crm.lead', 'from', 'crm.lead', 'to', 'm4'));
        self::assertSame('from', DB::table('tm_host_task_links')->value('subject_id'));
        self::assertSame(0, DB::table('tm_audit_events')->where('action', 'host_subject_moved')->count());

        // The scope of the links is part of the lookup: another scope sees nothing to move.
        $this->host('to-two', scope: 'two');
        self::assertSame(0, $this->subjects()->move($this->manager('a', 'two'), 'crm.lead', 'from', 'crm.lead', 'to-two', 'm5')['moved']);
        self::assertSame('from', DB::table('tm_host_task_links')->value('subject_id'));
    }
}
