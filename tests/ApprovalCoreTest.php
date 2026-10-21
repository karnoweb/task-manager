<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;

final class ApprovalCoreTest extends ApprovalTestCase
{
    public function test_quorum_votes_resolve_once_and_duplicate_vote_or_replay_does_not_count_twice(): void
    {
        [$i, $step] = $this->instanceWithApproval();
        $first = $this->approvals()->vote($this->manager('b'), $step['step_key'], 1, 2, 'vote-b', 'approve');
        self::assertSame('pending', $first['state']);
        self::assertCount(1, $first['votes']);
        self::assertSame($first, $this->approvals()->vote($this->manager('b'), $step['step_key'], 1, 2, 'vote-b', 'approve'));
        $duplicate = $this->approvals()->vote($this->manager('b'), $step['step_key'], 2, 2, 'vote-b-again', 'approve');
        self::assertCount(1, $duplicate['votes']);
        $resolved = $this->approvals()->vote($this->manager('c'), $step['step_key'], 2, 2, 'vote-c', 'approve');
        self::assertSame('approved', $resolved['state']);
        self::assertSame('executed', $resolved['execution_state']);
        self::assertSame('won', $this->instances()->get($this->manager(), $i['instance_key'])['current_state_key']);
        self::assertSame(2, DB::table('tm_approval_votes')->count());
        self::assertSame(1, DB::table('tm_outbox')->where('action', 'approval_decided')->count());
        $this->error('approval_already_resolved', fn () => $this->approvals()->vote($this->manager('d'), $step['step_key'], 3, 3, 'late', 'approve'));
        self::assertSame(2, DB::table('tm_approval_votes')->count());
        self::assertSame(0, DB::table('tm_active_approval_steps')->count());
    }

    public function test_sequential_votes_reject_early_next_actor_and_stale_version(): void
    {
        [$i, $step] = $this->instanceWithApproval('sequential');
        $this->error('approval_sequence_violation', fn () => $this->approvals()->vote($this->manager('c'), $step['step_key'], 1, 2, 'early', 'approve'));
        self::assertSame(0, DB::table('tm_approval_votes')->count());
        $this->approvals()->vote($this->manager('b'), $step['step_key'], 1, 2, 'b', 'approve');
        $this->error('version_conflict', fn () => $this->approvals()->vote($this->manager('c'), $step['step_key'], 1, 2, 'stale', 'approve'));
        $middle = $this->approvals()->vote($this->manager('c'), $step['step_key'], 2, 2, 'c', 'approve');
        self::assertSame('pending', $middle['state']);
        $done = $this->approvals()->vote($this->manager('d'), $step['step_key'], 3, 2, 'd', 'approve');
        self::assertSame('approved', $done['state']);
        self::assertCount(3, $done['votes']);
        self::assertSame('won', $this->instances()->get($this->manager(), $i['instance_key'])['current_state_key']);
    }

    public function test_revocation_before_vote_is_rechecked_and_manager_can_cancel_for_explicit_repair(): void
    {
        [$i, $step] = $this->instanceWithApproval();
        DB::table('host_people')->where('actor', 'b')->update(['can_approve' => false]);
        $this->error('approval_vote_denied', fn () => $this->approvals()->vote($this->manager('b'), $step['step_key'], 1, 2, 'revoked', 'approve'));
        self::assertSame('pending', $this->approvals()->get($this->manager(), $step['step_key'])['state']);
        self::assertSame(0, DB::table('tm_approval_votes')->count());
        $cancelled = $this->approvals()->cancel($this->manager(), $step['step_key'], 1, 2, 'cancel', 'approver_unavailable');
        self::assertSame('cancelled', $cancelled['state']);
        self::assertNull($this->approvals()->active($this->manager(), $i['instance_key']));
        self::assertSame('review', $this->instances()->get($this->manager(), $i['instance_key'])['current_state_key']);
        self::assertSame(1, DB::table('tm_outbox')->where('action', 'approval_cancelled')->count());
        $this->error('approval_not_decided', fn () => $this->approvals()->execute($this->manager(), $step['step_key'], 2, 3, 'bad-execute'));
    }

    public function test_reject_requires_catalog_reason_and_uses_distinct_return_transition(): void
    {
        [$i, $step] = $this->instanceWithApproval();
        $this->error('approval_reason_required', fn () => $this->approvals()->vote($this->manager('b'), $step['step_key'], 1, 2, 'missing', 'reject'));
        $rejected = $this->approvals()->vote($this->manager('b'), $step['step_key'], 1, 2, 'reject', 'reject', 'rejected');
        self::assertSame('rejected', $rejected['state']);
        self::assertSame('executed', $rejected['execution_state']);
        self::assertSame('new', $this->instances()->get($this->manager(), $i['instance_key'])['current_state_key']);
        self::assertSame(1, DB::table('tm_approval_votes')->count());
        self::assertSame('rejected', DB::table('tm_approval_votes')->value('reason_code'));
    }

    public function test_pending_approval_cannot_be_bypassed_by_transition_or_pipeline_move(): void
    {
        [$i, $step, $p] = $this->instanceWithApproval();
        $events = DB::table('tm_outbox')->count();
        $this->error('approval_pending', fn () => $this->instances()->transition($this->manager(), $i['instance_key'], 2, 'bypass', 'approve', ['reason_code' => 'approved']));
        $r = $this->workflows()->revision($this->manager(), $i['workflow_revision_id']);
        $target = $this->pipeline($r['id'], name: 'Other');
        $preview = $this->instances()->previewMove($this->manager(), $i['instance_key'], $target['pipeline_key'], ['review' => 'review']);
        $this->error('approval_pending', fn () => $this->instances()->moveToPipeline($this->manager(), $preview, 'move-bypass'));
        self::assertSame('review', $this->instances()->get($this->manager(), $i['instance_key'])['current_state_key']);
        self::assertSame($p['id'], $this->instances()->get($this->manager(), $i['instance_key'])['pipeline_id']);
        self::assertSame(0, DB::table('tm_approval_votes')->count());
    }

    public function test_view_only_voter_records_consent_but_needs_authorized_executor(): void
    {
        [$i, $step] = $this->instanceWithApproval();
        DB::table('host_permissions')->where('actor', 'b')->update(['can_edit' => false]);
        DB::table('host_permissions')->where('actor', 'c')->update(['can_edit' => false]);
        $voterB = new ExecutionContext('one', 'b', 'vote', capabilities: ['workflow.view', 'approval.vote']);
        $voterC = new ExecutionContext('one', 'c', 'vote', capabilities: ['workflow.view', 'approval.vote']);
        $this->approvals()->vote($voterB, $step['step_key'], 1, 2, 'b', 'approve');
        $pending = $this->approvals()->vote($voterC, $step['step_key'], 2, 2, 'c', 'approve');
        self::assertSame('approved', $pending['state']);
        self::assertSame('pending', $pending['execution_state']);
        self::assertSame('review', $this->instances()->get($this->manager(), $i['instance_key'])['current_state_key']);
        $executed = $this->approvals()->execute($this->manager(), $step['step_key'], 3, 2, 'execute');
        self::assertSame('executed', $executed['execution_state']);
        self::assertSame('won', $this->instances()->get($this->manager(), $i['instance_key'])['current_state_key']);
        self::assertSame(2, DB::table('tm_approval_votes')->count());
    }

    public function test_host_veto_retains_valid_votes_without_faking_workflow_completion(): void
    {
        [$i, $step] = $this->instanceWithApproval();
        $this->approvals()->vote($this->manager('b'), $step['step_key'], 1, 2, 'b', 'approve');
        DB::table('host_subjects')->update(['veto' => true]);
        $blocked = $this->approvals()->vote($this->manager('c'), $step['step_key'], 2, 2, 'c', 'approve');
        self::assertSame('approved', $blocked['state']);
        self::assertSame('manual_review', $blocked['execution_state']);
        self::assertSame('review', $this->instances()->get($this->manager(), $i['instance_key'])['current_state_key']);
        self::assertSame(2, DB::table('tm_approval_votes')->count());
        self::assertSame(0, DB::table('host_subjects')->value('guard_touches'));
        DB::table('host_subjects')->update(['veto' => false]);
        self::assertSame('executed', $this->approvals()->execute($this->manager(), $step['step_key'], 3, 2, 'retry')['execution_state']);
    }

    public function test_native_todo_complete_archive_delete_and_conversion_respect_pending_step(): void
    {
        $c = $this->manager();
        $t = $this->service()->create($c, 'Todo', 'task');
        $this->service()->share($c, $t['public_key'], 1, 'share-b', 'b');
        $i = DB::table('tm_workflow_instances')->where('task_id', $t['id'])->first();
        $step = $this->approvals()->begin($c, $i->instance_key, 1, 'step', ['approvers' => ['b'], 'approved_transition' => 'complete']);
        $t = $this->service()->get($c, $t['public_key']);
        foreach (['complete', 'archive', 'delete'] as $action) {
            $this->error('approval_pending', fn () => $this->service()->mutate($c, $t['public_key'], $t['version'], 'bypass-'.$action, $action));
        }
        $r = $this->sales();
        $p = $this->projects()->create($c, 'APP', 'App', $r['id'], 'project');
        $preview = $this->projects()->previewTaskMove($c, $t['public_key'], $p['project_key'], 'task', ['pending' => 'new']);
        $this->error('approval_pending', fn () => $this->projects()->moveTask($c, $preview, 'convert'));
        self::assertNull($this->service()->get($c, $t['public_key'])['project_id']);
        $vote = $this->approvals()->vote($this->manager('b'), $step['step_key'], 1, 2, 'vote', 'approve');
        self::assertSame('manual_review', $vote['execution_state']);
        $this->approvals()->execute($c, $step['step_key'], 2, 2, 'execute');
        self::assertSame('done', $this->service()->get($c, $t['public_key'])['state']);
    }

    public function test_self_approval_and_invalid_quorum_fail_closed(): void
    {
        $r = $this->sales();
        $this->host();
        $i = $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $r['id'], 'start');
        $this->error('approval_unresolvable', fn () => $this->approvals()->begin($this->manager(), $i['instance_key'], 1, 'self', ['approvers' => ['a'], 'approved_transition' => 'review']));
        $this->error('approval_quorum_invalid', fn () => $this->approvals()->begin($this->manager(), $i['instance_key'], 1, 'quorum', ['mode' => 'quorum', 'approvers' => ['b'], 'quorum' => 2, 'approved_transition' => 'review']));
        self::assertSame(0, DB::table('tm_approval_steps')->count());
        self::assertSame(0, DB::table('tm_approval_votes')->count());
    }

    public function test_project_migration_cannot_bypass_a_pending_manual_step(): void
    {
        [$r, $p, $task] = $this->projectTask();
        $i = DB::table('tm_workflow_instances')->where('task_id', $task['id'])->first();
        $this->approvals()->begin($this->manager(), $i->instance_key, $i->version, 'step', ['approvers' => ['b'], 'approved_transition' => 'review']);
        $new = $this->workflows()->publish($this->manager(), 'sales', 1, 'revision2', $this->definition());
        $preview = $this->projects()->previewWorkflowMigration($this->manager(), $p['project_key'], $new['id'], ['new' => 'review']);
        $before = DB::table('tm_outbox')->count();
        $this->error('approval_pending', fn () => $this->projects()->migrateWorkflow($this->manager(), $preview, 'migrate'));
        self::assertSame($r['id'], DB::table('tm_workflow_instances')->where('id', $i->id)->value('workflow_revision_id'));
        self::assertSame('new', $this->service()->get($this->manager(), $task['public_key'])['state']);
        self::assertSame($before, DB::table('tm_outbox')->count());
    }

    public function test_native_owner_can_execute_consented_completion_without_leaving_stale_execution_projection(): void
    {
        $c = $this->manager();
        $task = $this->service()->create($c, 'Task', 'task');
        $this->service()->share($c, $task['public_key'], 1, 'share', 'b');
        $i = DB::table('tm_workflow_instances')->where('task_id', $task['id'])->first();
        $step = $this->approvals()->begin($c, $i->instance_key, 1, 'step', ['approvers' => ['b'], 'approved_transition' => 'complete']);
        $voter = new ExecutionContext('one', 'b', 'vote', capabilities: ['task.view', 'workflow.view', 'approval.vote']);
        $vote = $this->approvals()->vote($voter, $step['step_key'], 1, 2, 'vote', 'approve');
        self::assertSame('pending', $vote['execution_state']);
        $current = $this->service()->get($c, $task['public_key']);
        $done = $this->service()->mutate($c, $task['public_key'], $current['version'], 'complete', 'complete');
        self::assertSame('done', $done['state']);
        self::assertSame('executed', $this->approvals()->get($c, $step['step_key'])['execution_state']);
        self::assertSame(0, DB::table('tm_active_approval_steps')->count());
    }
}
