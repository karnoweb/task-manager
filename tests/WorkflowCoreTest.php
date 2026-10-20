<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;

final class WorkflowCoreTest extends WorkflowTestCase
{
    public function test_external_subject_keeps_identity_and_is_not_copied_to_tasks(): void
    {
        $revision = $this->sales(); $this->host(); $pipeline = $this->pipeline($revision['id']);
        $i = $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $revision['id'], 'start', pipelineKey: $pipeline['pipeline_key']);
        self::assertSame(0, DB::table('tm_tasks')->count()); self::assertSame('lead-1', $i['subject_id']); self::assertNull($i['task_id']);
        $next = $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'review', 'review');
        self::assertSame('review', $next['current_state_key']); self::assertSame('Host-owned', DB::table('host_subjects')->value('title'));
        self::assertNull(DB::table('host_subjects')->value('legacy_status'));
    }
    public function test_invalid_transition_required_fields_self_action_and_cancel(): void
    {
        $r = $this->sales(); $this->host(); $p = $this->pipeline($r['id']);
        $i = $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $r['id'], 'start', pipelineKey: $p['pipeline_key']);
        $count = DB::table('tm_outbox')->count();
        $this->error('transition_invalid', fn () => $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'invalid', 'approve'));
        $this->error('required_field_missing', fn () => $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'missing', 'note'));
        $cancel = $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'cancel', 'review', confirmed: false);
        self::assertSame($i, $cancel); self::assertSame($count, DB::table('tm_outbox')->count());
        $note = $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'note', 'note', ['note' => 'private']);
        self::assertSame('new', $note['current_state_key']); self::assertSame(2, $note['version']);
        self::assertCount(1, $this->instances()->visits($this->manager(), $i['instance_key']));
        self::assertStringNotContainsString('private', DB::table('tm_audit_events')->where('operation_id', 'note')->value('payload'));
    }
    public function test_host_veto_rolls_back_host_domain_history_journal_and_outbox(): void
    {
        $r = $this->sales(); $this->host(); $i = $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $r['id'], 'start');
        DB::table('host_subjects')->update(['veto' => true]); $events = DB::table('tm_outbox')->count();
        $this->error('host_guard_veto', fn () => $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'veto', 'review'));
        self::assertSame($i, $this->instances()->get($this->manager(), $i['instance_key']));
        self::assertSame(0, DB::table('host_subjects')->value('guard_touches')); self::assertSame(0, DB::table('tm_instance_transitions')->count());
        self::assertSame(0, DB::table('tm_command_journal')->where('operation_id', 'veto')->count()); self::assertSame($events, DB::table('tm_outbox')->count());
        self::assertSame('host_guard_veto', DB::table('tm_security_decisions')->value('reason'));
    }
    public function test_external_permissions_are_applied_before_count_facets_and_paging(): void
    {
        $r = $this->sales(); $this->host(); $i = $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $r['id'], 'start');
        self::assertSame(1, $this->instances()->count($this->manager()));
        self::assertSame([], $this->instances()->query($this->manager('b'))); self::assertSame(0, $this->instances()->count($this->manager('b')));
        self::assertSame([], $this->instances()->facets($this->manager('b')));
        DB::table('host_permissions')->delete(); self::assertSame(0, $this->instances()->count($this->manager()));
        $this->error('not_found', fn () => $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $r['id'], 'start'));
        self::assertSame(0, $this->instances()->count($this->manager('a', 'two')));
    }
    public function test_slots_are_independent_duplicates_rejected_and_terminal_frees_slot(): void
    {
        $r = $this->sales(); $this->host();
        $a = $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $r['id'], 'start');
        $b = $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $r['id'], 'legal', slot: 'legal');
        $this->error('active_slot_conflict', fn () => $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $r['id'], 'duplicate'));
        $a = $this->instances()->transition($this->manager(), $a['instance_key'], 1, 'review', 'review');
        $this->error('reason_required', fn () => $this->instances()->transition($this->manager(), $a['instance_key'], 2, 'missing-reason', 'approve'));
        $a = $this->instances()->transition($this->manager(), $a['instance_key'], 2, 'approve', 'approve', ['reason_code' => 'approved']);
        self::assertSame('closed', $a['lifecycle']); self::assertSame('new', $this->instances()->get($this->manager(), $b['instance_key'])['current_state_key']);
        $next = $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $r['id'], 'next');
        self::assertNotSame($a['instance_key'], $next['instance_key']); self::assertSame(3, DB::table('tm_workflow_instances')->count()); self::assertSame(2, DB::table('tm_active_process_slots')->count());
    }
    public function test_stage_reentry_and_pipeline_move_preserve_visits_and_subject_identity(): void
    {
        $r = $this->sales(); $this->host(); $p = $this->pipeline($r['id']); $q = $this->pipeline($r['id'], name: 'Contracts');
        $i = $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $r['id'], 'start', pipelineKey: $p['pipeline_key']);
        $i = $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'review', 'review');
        $i = $this->instances()->transition($this->manager(), $i['instance_key'], 2, 'return', 'return');
        $visits = $this->instances()->visits($this->manager(), $i['instance_key']); self::assertCount(3, $visits);
        self::assertNotNull($visits[0]['exited_at']); self::assertNull($visits[2]['exited_at']);
        $preview = $this->instances()->previewMove($this->manager(), $i['instance_key'], $q['pipeline_key'], ['new' => 'review']);
        $moved = $this->instances()->moveToPipeline($this->manager(), $preview, 'move');
        self::assertSame('lead-1', $moved['subject_id']); self::assertSame($i['instance_key'], $moved['instance_key']); self::assertSame($q['id'], $moved['pipeline_id']);
        self::assertCount(4, $this->instances()->visits($this->manager(), $i['instance_key']));
    }
    public function test_stage_mapping_validation_ambiguity_and_capacity(): void
    {
        $r = $this->sales(); $this->host(); $this->host('lead-2');
        $this->error('stage_mapping_invalid', fn () => $this->pipelines()->create($this->manager(), 'bad', $r['id'], ['x' => ['state' => 'unknown']], 'bad'));
        $p = $this->pipeline($r['id'], 1);
        $a = $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $r['id'], 'a', pipelineKey: $p['pipeline_key']);
        $b = $this->instances()->start($this->manager(), 'crm.lead', 'lead-2', $r['id'], 'b', pipelineKey: $p['pipeline_key']);
        $this->instances()->transition($this->manager(), $a['instance_key'], 1, 'review-a', 'review');
        $this->error('capacity_exceeded', fn () => $this->instances()->transition($this->manager(), $b['instance_key'], 1, 'review-b', 'review'));
        self::assertSame('new', $this->instances()->get($this->manager(), $b['instance_key'])['current_state_key']);
        $ambiguous = $this->pipelines()->create($this->manager(), 'Ambiguous', $r['id'], ['a' => ['state' => 'new'], 'b' => ['state' => 'new']], 'ambiguous');
        $this->error('stage_ambiguous', fn () => $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $r['id'], 'ambiguous-start', 'other', $ambiguous['pipeline_key']));
        $valid = $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $r['id'], 'explicit-start', 'other', $ambiguous['pipeline_key'], 'b');
        self::assertSame('b', $valid['active_stage_key']);
    }
    public function test_project_membership_revocation_is_immediate_even_for_task_owner(): void
    {
        [$revision, $project, $task] = $this->projectTask();
        self::assertSame($project['id'], $task['project_id']); self::assertSame('APP-1', $task['display_key']);
        $project = $this->projects()->change($this->manager(), $project['project_key'], 1, 'viewer', 'member', ['actor' => 'b', 'role' => 'viewer']);
        self::assertSame(1, $this->service()->count($this->manager('b')));
        $this->error('not_found', fn () => $this->service()->mutate($this->manager('b'), $task['public_key'], 2, 'edit', 'update', ['title' => 'denied']));
        $project = $this->projects()->change($this->manager(), $project['project_key'], 2, 'revoke', 'member', ['actor' => 'b', 'role' => null]);
        self::assertSame(0, $this->service()->count($this->manager('b'))); self::assertSame([], $this->service()->query($this->manager('b')));
        $this->error('not_found', fn () => $this->service()->resolveKey($this->manager('b'), 'TM-1'));
        self::assertSame($task['public_key'], $this->service()->resolveKey($this->manager(), 'TM-1')['public_key']);
        $this->projects()->change($this->manager(), $project['project_key'], 3, 'archive', 'archive', []);
        $personal = $this->service()->create($this->manager(), 'Later', 'later');
        $this->error('project_archived', fn () => $this->projects()->previewTaskMove($this->manager(), $personal['public_key'], $project['project_key'], 'task', ['pending' => 'new']));
    }
    public function test_project_workflow_revisions_are_immutable_and_migration_requires_fresh_complete_map(): void
    {
        [$revision, $project, $task] = $this->projectTask();
        $old = DB::table('tm_workflow_definitions')->where('id', $revision['id'])->value('definition');
        $newDefinition = ['initial' => 'open', 'states' => ['open' => ['terminal' => 'none'], 'closed' => ['terminal' => 'success']], 'transitions' => ['close' => ['from' => 'open', 'to' => 'closed']]];
        $new = $this->workflows()->publish($this->manager(), 'sales', 1, 'revision2', $newDefinition);
        self::assertSame($old, DB::table('tm_workflow_definitions')->where('id', $revision['id'])->value('definition'));
        $this->error('state_mapping_required', fn () => $this->projects()->previewWorkflowMigration($this->manager(), $project['project_key'], $new['id'], []));
        $preview = $this->projects()->previewWorkflowMigration($this->manager(), $project['project_key'], $new['id'], ['new' => 'open']);
        self::assertSame('new', $this->service()->get($this->manager(), $task['public_key'])['state']);
        $this->projects()->migrateWorkflow($this->manager(), $preview, 'migrate');
        self::assertSame('open', $this->service()->get($this->manager(), $task['public_key'])['state']);
        self::assertSame($new['id'], DB::table('tm_workflow_instances')->value('workflow_revision_id'));
        $this->error('preview_stale', fn () => $this->projects()->migrateWorkflow($this->manager(), $preview, 'stale-migrate'));
    }
    public function test_case_timeline_filters_subject_permissions_without_private_host_text(): void
    {
        $r = $this->sales(); $this->host('ticket', 'support.ticket'); $this->host('opportunity', 'crm.opportunity', actors: ['a', 'sales']);
        $case = $this->cases()->create($this->manager(), 'Case', 'case');
        $this->instances()->start($this->manager(), 'support.ticket', 'ticket', $r['id'], 'ticket', caseKey: $case['case_key']);
        $this->instances()->start($this->manager(), 'crm.opportunity', 'opportunity', $r['id'], 'opportunity', caseKey: $case['case_key']);
        $all = $this->cases()->timeline($this->manager(), $case['case_key']); $sales = $this->cases()->timeline($this->manager('sales'), $case['case_key']);
        self::assertCount(2, $all); self::assertCount(1, $sales); self::assertStringNotContainsString('PRIVATE-HOST-TEXT', json_encode($all));
        self::assertSame([], $this->cases()->timeline($this->manager('guest'), $case['case_key']));
    }
    public function test_legacy_host_writer_is_reported_as_conflict_and_alias_remains_stable(): void
    {
        $r = $this->sales(); $this->host(); $i = $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $r['id'], 'start');
        DB::table('host_subjects')->update(['legacy_status' => 'legacy-won']);
        self::assertSame('conflict', $this->instances()->reconcile($this->manager(), $i['instance_key'])['status']);
        $this->error('host_state_conflict', fn () => $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'blocked', 'review'));
        self::assertSame('crm.lead', DB::table('tm_workflow_instances')->value('subject_type')); self::assertSame('new', DB::table('tm_workflow_instances')->value('current_state_key'));
    }
    public function test_board_visibility_does_not_mutate_state_journal_or_events(): void
    {
        $r = $this->sales(); $this->host(); $i = $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $r['id'], 'start');
        $events = DB::table('tm_outbox')->count(); $commands = DB::table('tm_command_journal')->count();
        self::assertSame($i, $this->instances()->changeBoardVisibility($this->manager(), $i['instance_key']));
        self::assertSame($events, DB::table('tm_outbox')->count()); self::assertSame($commands, DB::table('tm_command_journal')->count());
    }
}
