<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Workflow\Definition;

final class WorkflowSecurityTest extends WorkflowTestCase
{
    public function test_definition_rejects_malformed_arrays_and_unsafe_condition_operators(): void
    {
        $bad = $this->definition();
        $bad['initial'] = [];
        $this->error('workflow_invalid', fn () => Definition::validate($bad));
        $bad = $this->definition();
        $bad['transitions']['review']['from'] = [];
        $this->error('transition_invalid', fn () => Definition::validate($bad));
        $bad = $this->definition();
        $bad['transitions']['review']['conditions'] = 'eval';
        $this->error('condition_invalid', fn () => Definition::validate($bad));
        $bad = $this->definition();
        $bad['transitions']['review']['conditions'] = [['field' => 'amount', 'operator' => 'eval', 'value' => 'php']];
        $this->error('condition_invalid', fn () => Definition::validate($bad));
        $bad = $this->definition();
        unset($bad['transitions']['approve']['reason_codes']);
        $this->error('reason_catalog_required', fn () => Definition::validate($bad));
    }

    public function test_subject_conditions_cannot_be_overwritten_by_caller_payload(): void
    {
        $d = $this->definition();
        $d['transitions']['review']['conditions'] = [['field' => 'amount', 'operator' => 'gte', 'value' => 200]];
        $r = $this->workflows()->publish($this->manager(), 'sales', 0, 'workflow', $d);
        $this->host();
        $i = $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $r['id'], 'start');
        $this->error('condition_failed', fn () => $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'forged', 'review', ['amount' => 999]));
        self::assertSame('new', $this->instances()->get($this->manager(), $i['instance_key'])['current_state_key']);
        DB::table('host_subjects')->update(['amount' => 200]);
        self::assertSame('review', $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'valid', 'review')['current_state_key']);
    }

    public function test_unknown_reason_code_cannot_close_instance(): void
    {
        $r = $this->sales();
        $this->host();
        $i = $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $r['id'], 'start');
        $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'review', 'review');
        $this->error('reason_invalid', fn () => $this->instances()->transition($this->manager(), $i['instance_key'], 2, 'bad', 'approve', ['reason_code' => 'arbitrary']));
        self::assertSame('active', $this->instances()->get($this->manager(), $i['instance_key'])['lifecycle']);
        self::assertSame(1, DB::table('tm_active_process_slots')->count());
    }

    public function test_external_only_service_actor_does_not_need_native_task_capabilities(): void
    {
        $r = $this->sales();
        $this->host();
        $i = $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $r['id'], 'start');
        $service = new ExecutionContext('one', 'a', 'worker', true, ['workflow.view', 'workflow.transition']);
        self::assertSame(1, $this->instances()->count($service));
        self::assertSame($i, $this->instances()->get($service, $i['instance_key']));
        self::assertSame('review', $this->instances()->transition($service, $i['instance_key'], 1, 'service', 'review')['current_state_key']);
    }

    public function test_workflow_migration_rejects_preview_after_instance_changes_without_partial_writes(): void
    {
        [$r, $p, $t] = $this->projectTask();
        $d = $this->definition();
        $d['states']['open'] = ['terminal' => 'none'];
        $new = $this->workflows()->publish($this->manager(), 'sales', 1, 'new', $d);
        $preview = $this->projects()->previewWorkflowMigration($this->manager(), $p['project_key'], $new['id'], ['new' => 'open']);
        $i = DB::table('tm_workflow_instances')->where('task_id', $t['id'])->first();
        $this->instances()->transition($this->manager(), $i->instance_key, $i->version, 'review', 'review');
        $events = DB::table('tm_outbox')->count();
        $this->error('state_mapping_required', fn () => $this->projects()->migrateWorkflow($this->manager(), $preview, 'stale'));
        self::assertSame($r['id'], DB::table('tm_workflow_instances')->where('id', $i->id)->value('workflow_revision_id'));
        self::assertSame($events, DB::table('tm_outbox')->count());
        self::assertSame(0, DB::table('tm_command_journal')->where('operation_id', 'stale')->count());
    }

    public function test_downgrade_refuses_to_discard_external_identity(): void
    {
        $r = $this->sales();
        $this->host();
        $i = $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $r['id'], 'start');
        $migration = require __DIR__.'/../database/migrations/2026_10_20_000003_create_workflow_process_core.php';
        try {
            $migration->down();
            self::fail('Unsafe downgrade');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('process_rollback_requires_export', $e->getMessage());
        }
        self::assertSame($i, $this->instances()->get($this->manager(), $i['instance_key']));
        self::assertSame(1, DB::table('tm_active_process_slots')->count());
    }

    public function test_default_revision_migration_preserves_closed_history_and_type_overrides(): void
    {
        [$r, $p, $t] = $this->projectTask();
        $i = DB::table('tm_workflow_instances')->where('task_id', $t['id'])->first();
        $this->instances()->transition($this->manager(), $i->instance_key, $i->version, 'review', 'review');
        $this->instances()->transition($this->manager(), $i->instance_key, $i->version + 1, 'approve', 'approve', ['reason_code' => 'approved']);
        $closed = DB::table('tm_workflow_instances')->where('id', $i->id)->first();
        $bug = $this->workflows()->publish($this->manager(), 'bug', 0, 'bug-workflow', ['initial' => 'triage', 'states' => ['triage' => ['terminal' => 'none']], 'transitions' => []]);
        $this->projects()->change($this->manager(), $p['project_key'], 1, 'type', 'type_workflow', ['type' => 'bug', 'revision' => $bug['id']]);
        $todo = $this->service()->create($this->manager(), 'Bug', 'bug-task');
        $preview = $this->projects()->previewTaskMove($this->manager(), $todo['public_key'], $p['project_key'], 'bug', ['pending' => 'triage']);
        $child = $this->projects()->moveTask($this->manager(), $preview, 'convert-bug');
        $new = $this->workflows()->publish($this->manager(), 'sales', 1, 'revision2', $this->definition());
        $preview = $this->projects()->previewWorkflowMigration($this->manager(), $p['project_key'], $new['id'], []);
        self::assertSame([], $preview['items']);
        $this->projects()->migrateWorkflow($this->manager(), $preview, 'migrate');
        self::assertEquals($closed, DB::table('tm_workflow_instances')->where('id', $i->id)->first());
        self::assertSame($bug['id'], DB::table('tm_workflow_instances')->where('task_id', $child['id'])->value('workflow_revision_id'));
        self::assertSame('triage', $this->service()->get($this->manager(), $child['public_key'])['state']);
    }

    public function test_inaccessible_and_nonexistent_case_timelines_are_indistinguishable(): void
    {
        $case = $this->cases()->create($this->manager(), 'Private case', 'case');
        self::assertSame([], $this->cases()->timeline($this->manager('guest'), $case['case_key']));
        self::assertSame([], $this->cases()->timeline($this->manager('guest'), (string) Str::uuid()));
    }
}
