<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;

final class AssignmentCoreTest extends AssignmentTestCase
{
    public function test_primary_and_secondary_capacity_duplicate_and_owner_invariants(): void
    {
        $i = $this->assignableInstance();
        $this->error('assignee_capacity_exceeded', fn () => $this->assignments()->assign($this->manager(), $i['instance_key'], 1, 'too-many', 'b', ['c']));
        self::assertSame(0, DB::table('tm_assignment_slots')->count());
        $state = $this->assignments()->configureInstance($this->manager(), $i['instance_key'], 1, 'policy', ['mode' => 'manual', 'primary' => 'b', 'secondary' => ['c'], 'max_assignees' => 2]);
        self::assertSame('a', $state['owner_ref']);
        self::assertSame('b', $state['primary_assignee']);
        self::assertSame(['c'], $state['secondary']);
        self::assertSame(1, DB::table('tm_assignment_slots')->where('slot_key', 'primary')->count());
        self::assertSame(2, DB::table('tm_assignment_slots')->count());
        $this->error('duplicate_assignee', fn () => $this->assignments()->assign($this->manager(), $i['instance_key'], 2, 'duplicate', 'b', ['b']));
        self::assertSame(2, DB::table('tm_assignment_slots')->count());
        self::assertSame(0, $this->instances()->count($this->manager('b')));
    }

    public function test_stage_dynamic_assignment_changes_executor_but_not_owner_and_keeps_intervals(): void
    {
        [$i, $visit, $p] = $this->instanceWithSla(['duration_seconds' => 7200]);
        $this->assignments()->configureStage($this->manager(), $p['pipeline_key'], 'review', 2, 'assignment-policy', ['mode' => 'team', 'reference' => 'contracts']);
        $this->clock->advance('+30 minutes');
        $next = $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'review', 'review');
        self::assertSame('a', $next['owner_ref']);
        self::assertSame('c', $next['primary_assignee']);
        $history = $this->assignments()->get($this->manager(), $i['instance_key'])['history'];
        self::assertCount(2, $history);
        self::assertSame('c', $history[0]['actor_ref']);
        self::assertNull($history[0]['active_to']);
        self::assertSame('2026-10-20 00:30:00', $history[1]['active_to']);
        self::assertSame(1, DB::table('tm_outbox')->where('action', 'stage_assignment_resolved')->count());
    }

    public function test_empty_or_revoked_resolver_cannot_activate_stage(): void
    {
        [$i, $visit, $p] = $this->instanceWithSla(['duration_seconds' => 7200]);
        $this->assignments()->configureStage($this->manager(), $p['pipeline_key'], 'review', 2, 'assignment-policy', ['mode' => 'team', 'reference' => 'contracts']);
        DB::table('host_people')->where('team', 'contracts')->update(['active' => false]);
        $events = DB::table('tm_outbox')->count();
        $this->error('assignment_unresolvable', fn () => $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'review', 'review'));
        self::assertSame('new', $this->instances()->get($this->manager(), $i['instance_key'])['current_state_key']);
        self::assertSame('a', $this->assignments()->get($this->manager(), $i['instance_key'])['primary_assignee']);
        self::assertSame($events, DB::table('tm_outbox')->count());
        self::assertSame(1, DB::table('tm_stage_visits')->count());
    }

    public function test_round_robin_uses_locked_scoped_queue_and_is_fair_for_stable_candidates(): void
    {
        $r = $this->sales();
        $p = $this->pipeline($r['id']);
        $this->assignments()->configureStage($this->manager(), $p['pipeline_key'], 'inbox', 1, 'assignment-policy', ['mode' => 'round_robin', 'reference' => 'contracts']);
        $assignees = [];
        for ($n = 0; $n < 4; $n++) {
            $id = 'lead-'.$n;
            $this->host($id);
            $i = $this->instances()->start($this->manager(), 'crm.lead', $id, $r['id'], 'start-'.$n, pipelineKey: $p['pipeline_key']);
            $assignees[] = $i['primary_assignee'];
        }
        self::assertSame(['c', 'd', 'c', 'd'], $assignees);
        self::assertSame(4, DB::table('tm_assignment_queues')->value('position'));
        self::assertSame(4, DB::table('tm_assignment_slots')->where('slot_key', 'primary')->count());
    }

    public function test_departure_policies_reject_or_restore_owner_and_close_old_history(): void
    {
        $i = $this->assignableInstance();
        $this->assignments()->assign($this->manager(), $i['instance_key'], 1, 'assign', 'b');
        DB::table('host_people')->where('actor', 'b')->update(['active' => false]);
        $this->error('member_has_active_work', fn () => $this->assignments()->depart($this->manager(), $i['instance_key'], 2, 'reject', 'b', 'reject'));
        $this->clock->advance('+1 hour');
        $state = $this->assignments()->depart($this->manager(), $i['instance_key'], 2, 'restore', 'b', 'reassign_owner');
        self::assertSame('a', $state['primary_assignee']);
        self::assertSame('a', $state['owner_ref']);
        self::assertCount(2, $state['history']);
        self::assertNotNull($state['history'][1]['active_to']);
        self::assertSame('member_departed', $state['history'][0]['reason_code']);
        $this->error('assignment_unresolvable', fn () => $this->assignments()->assign($this->manager(), $i['instance_key'], 3, 'revoked', 'b'));
    }

    public function test_explicit_unassigned_policy_is_required_to_remove_last_primary(): void
    {
        $i = $this->assignableInstance();
        $this->error('primary_assignee_required', fn () => $this->assignments()->assign($this->manager(), $i['instance_key'], 1, 'null', null));
        $state = $this->assignments()->configureInstance($this->manager(), $i['instance_key'], 1, 'nullable-policy', ['allow_unassigned' => true, 'primary' => null]);
        self::assertNull($state['primary_assignee']);
        self::assertSame(0, DB::table('tm_assignment_slots')->count());
    }

    public function test_personal_complete_and_reopen_close_and_restore_fixed_assignments(): void
    {
        $t = $this->service()->create($this->manager(), 'Personal', 'task');
        $i = DB::table('tm_workflow_instances')->where('task_id', $t['id'])->first();
        $this->assignments()->configureInstance($this->manager(), $i->instance_key, 1, 'policy', ['primary' => 'b', 'secondary' => ['c'], 'max_assignees' => 2]);
        $this->clock->advance('+1 hour');
        $t = $this->service()->mutate($this->manager(), $t['public_key'], 2, 'done', 'complete');
        self::assertSame(0, DB::table('tm_assignment_slots')->count());
        $this->clock->advance('+1 hour');
        $t = $this->service()->mutate($this->manager(), $t['public_key'], 3, 'reopen', 'reopen');
        $state = $this->assignments()->get($this->manager(), $i->instance_key);
        self::assertSame('b', $state['primary_assignee']);
        self::assertSame(['c'], $state['secondary']);
        self::assertSame('a', $state['owner_ref']);
        self::assertSame(2, DB::table('tm_assignment_slots')->count());
        self::assertCount(4, $state['history']);
        self::assertSame('pending', $t['state']);
    }

    public function test_assignment_capability_scope_and_stale_version_fail_closed(): void
    {
        $i = $this->assignableInstance();
        $limited = new ExecutionContext('one', 'a', 'worker', true, ['workflow.view']);
        $this->error('capability_denied', fn () => $this->assignments()->assign($limited, $i['instance_key'], 1, 'denied', 'b'));
        $this->error('not_found', fn () => $this->assignments()->assign($this->manager('a', 'two'), $i['instance_key'], 1, 'wrong', 'b'));
        $this->assignments()->assign($this->manager(), $i['instance_key'], 1, 'valid', 'b');
        $this->error('version_conflict', fn () => $this->assignments()->assign($this->manager(), $i['instance_key'], 1, 'stale', 'c'));
        self::assertSame('b', $this->assignments()->get($this->manager(), $i['instance_key'])['primary_assignee']);
    }
}
