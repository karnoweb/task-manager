<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;

final class GroupCoreTest extends GroupTestCase
{
    public function test_active_member_without_committed_milestone_fails_with_reason(): void
    {
        [$group, $instances] = $this->campaignFixture();
        $this->closeCampaign();
        $preview = $this->groupService()->previewClosure($this->manager(), $group['group_key']);
        self::assertSame(1, $preview['counts']['failed']);
        $run = $this->groupService()->executeClosure($this->manager(), $preview, 'close');
        $report = $this->processAll($run['run_key']);
        self::assertSame(['failed' => 1], $report['counts']);
        self::assertSame('campaign_ended', $report['items'][0]['reason_code']);
        self::assertSame('failed', $this->instances()->get($this->manager(), $instances[0]['instance_key'])['current_state_key']);
    }

    public function test_verified_milestone_at_inclusive_cutoff_continues(): void
    {
        [$group, $instances] = $this->campaignFixture();
        $this->reserve($instances[0], 'one');
        $this->closeCampaign();
        $preview = $this->groupService()->previewClosure($this->manager(), $group['group_key']);
        self::assertSame(1, $preview['counts']['continued']);
        $run = $this->groupService()->executeClosure($this->manager(), $preview, 'close');
        self::assertSame(['continued' => 1], $this->processAll($run['run_key'])['counts']);
        self::assertSame('reservation', $this->instances()->get($this->manager(), $instances[0]['instance_key'])['current_state_key']);
    }

    public function test_unverified_intent_is_reviewed_instead_of_guessing_failure(): void
    {
        [$group, $instances] = $this->campaignFixture();
        $this->reserve($instances[0], 'one', false);
        $this->closeCampaign();
        $run = $this->groupService()->executeClosure($this->manager(), $this->groupService()->previewClosure($this->manager(), $group['group_key']), 'close');
        $report = $this->processAll($run['run_key']);
        self::assertSame(['manual_review' => 1], $report['counts']);
        self::assertSame('commit_evidence_pending', $report['items'][0]['error_code']);
        self::assertSame('reservation', $this->instances()->get($this->manager(), $instances[0]['instance_key'])['current_state_key']);
    }

    public function test_hundred_member_campaign_has_exact_75_15_10_results_with_five_live_delegations(): void
    {
        [$group, $instances, $handoffs] = $this->campaignHundred(true);
        $this->clock->advance('+60 seconds');
        self::assertSame(12, $this->sla()->run($this->manager(), 100)['events']);
        $this->closeCampaign();
        $preview = $this->groupService()->previewClosure($this->manager(), $group['group_key']);
        self::assertSame(100, $preview['total']);
        self::assertSame(['failed' => 75, 'continued' => 15, 'already_terminal' => 10, 'manual_review' => 0, 'denied' => 0], $preview['counts']);
        $run = $this->groupService()->executeClosure($this->manager(), $preview, 'campaign-close');
        $report = $this->processAll($run['run_key'], 20);
        self::assertSame(['already_terminal' => 10, 'continued' => 15, 'failed' => 75], $report['counts']);
        self::assertSame(75, DB::table('tm_group_operation_items')->where('result', 'failed')->where('reason_code', 'campaign_ended')->count());
        self::assertSame(100, DB::table('tm_outbox')->where('action', 'group_item_processed')->count());
        foreach ($handoffs as $handoff) {
            self::assertSame('active', $this->handoffs()->get($this->manager(), $handoff['handoff_key'])['status']);
            self::assertSame('waiting', DB::table('tm_workflow_instances')->where('id', $handoff['origin_id'])->value('lifecycle'));
            self::assertSame('active', DB::table('tm_workflow_instances')->where('id', $handoff['destination_id'])->value('lifecycle'));
            self::assertSame('paused', $this->sla()->get($this->manager(), $handoff['paused_visit_id'])['sla_state']);
        }
        $events = DB::table('tm_outbox')->count();
        self::assertSame(['processed' => 0, 'state' => 'complete'], $this->groupService()->process($this->manager(), $run['run_key']));
        self::assertSame($run, $this->groupService()->executeClosure($this->manager(), $preview, 'campaign-close'));
        self::assertSame($events, DB::table('tm_outbox')->count());
    }

    public function test_named_reservation_stage_without_milestone_is_not_protected(): void
    {
        [$group, $instances] = $this->campaignFixture();
        $this->instances()->transition($this->manager(), $instances[0]['instance_key'], 1, 'enter-stage', 'enter_reservation');
        $this->closeCampaign();
        self::assertSame(0, DB::table('tm_milestone_intents')->count());
        $preview = $this->groupService()->previewClosure($this->manager(), $group['group_key']);
        self::assertSame(1, $preview['counts']['failed']);
        $run = $this->groupService()->executeClosure($this->manager(), $preview, 'close');
        self::assertSame(['failed' => 1], $this->processAll($run['run_key'])['counts']);
    }

    public function test_milestone_committed_after_cutoff_does_not_grandfather_member(): void
    {
        [$group, $instances] = $this->campaignFixture();
        $this->closeCampaign();
        $this->clock->advance('+1 second');
        $this->reserve($instances[0], 'late');
        $preview = $this->groupService()->previewClosure($this->manager(), $group['group_key']);
        self::assertSame(1, $preview['counts']['failed']);
        $run = $this->groupService()->executeClosure($this->manager(), $preview, 'close');
        self::assertSame(['failed' => 1], $this->processAll($run['run_key'])['counts']);
        self::assertSame('failed', $this->instances()->get($this->manager(), $instances[0]['instance_key'])['current_state_key']);
    }

    public function test_already_terminal_member_gets_no_second_business_transition(): void
    {
        [$group, $instances] = $this->campaignFixture();
        $terminal = $this->instances()->transition($this->manager(), $instances[0]['instance_key'], 1, 'win', 'win');
        $this->closeCampaign();
        $transitions = DB::table('tm_instance_transitions')->count();
        $run = $this->groupService()->executeClosure($this->manager(), $this->groupService()->previewClosure($this->manager(), $group['group_key']), 'close');
        self::assertSame(['already_terminal' => 1], $this->processAll($run['run_key'])['counts']);
        self::assertSame($transitions, DB::table('tm_instance_transitions')->count());
        self::assertSame($terminal['version'], $this->instances()->get($this->manager(), $instances[0]['instance_key'])['version']);
    }

    public function test_invalid_campaign_source_cannot_start_any_closure_action(): void
    {
        [$group, $instances] = $this->campaignFixture();
        try {
            $this->groupService()->previewClosure($this->manager(), $group['group_key']);
            self::fail();
        } catch (DomainError $error) {
            self::assertSame('group_source_invalid', $error->errorCode);
        }
        self::assertSame(0, DB::table('tm_group_operations')->count());
        self::assertSame(0, DB::table('tm_group_operation_items')->count());
        self::assertSame('new', $this->instances()->get($this->manager(), $instances[0]['instance_key'])['current_state_key']);
    }

    public function test_preview_and_delta_are_read_only_and_detect_process_version_changes(): void
    {
        [$group, $instances] = $this->campaignFixture();
        $this->closeCampaign();
        $events = DB::table('tm_outbox')->count();
        $preview = $this->groupService()->previewClosure($this->manager(), $group['group_key']);
        self::assertSame($group['policy_id'], $preview['policy_id']);
        self::assertSame(1, $preview['policy_revision']);
        self::assertSame($events, DB::table('tm_outbox')->count());
        self::assertSame(0, DB::table('tm_group_operations')->count());
        $this->instances()->transition($this->manager(), $instances[0]['instance_key'], 1, 'update-stage', 'enter_reservation');
        $events = DB::table('tm_outbox')->count();
        $delta = $this->groupService()->previewDelta($this->manager(), $preview);
        self::assertTrue($delta['changed']);
        self::assertNotSame($preview['member_hash'], $delta['preview']['member_hash']);
        self::assertSame($events, DB::table('tm_outbox')->count());
                $this->error('preview_stale', fn () => $this->groupService()->executeClosure($this->manager(), $preview, 'stale'));
    }

    public function test_membership_change_invalidates_preview_before_run_creation(): void
    {
        [$group, $instances, $pipeline] = $this->campaignFixture();
        $this->closeCampaign();
        $preview = $this->groupService()->previewClosure($this->manager(), $group['group_key']);
        $this->host('new-lead');
        $added = $this->instances()->start($this->manager(), 'crm.lead', 'new-lead', $instances[0]['workflow_revision_id'], 'new-member', pipelineKey: $pipeline['pipeline_key']);
        $this->groupService()->enroll($this->manager(), $group['group_key'], $group['version'], [['instance_key' => $added['instance_key'], 'version' => 1]], 'add-member');
        try {
            $this->groupService()->executeClosure($this->manager(), $preview, 'stale');
            self::fail();
        } catch (DomainError $error) {
            self::assertSame('preview_stale', $error->errorCode);
        }
        self::assertSame(0, DB::table('tm_group_operations')->count());
        self::assertSame(1, $this->groupService()->previewDelta($this->manager(), $preview)['counts_delta']['failed']);
    }

    public function test_item_report_has_reason_operation_and_authorized_cursor_without_private_content(): void
    {
        [$group] = $this->campaignFixture(3);
        $this->closeCampaign();
        $run = $this->groupService()->executeClosure($this->manager(), $this->groupService()->previewClosure($this->manager(), $group['group_key']), 'close');
        $this->processAll($run['run_key']);
        $one = $this->groupService()->report($this->manager(), $run['run_key'], 2);
        $two = $this->groupService()->report($this->manager(), $run['run_key'], 2, $one['next_after_id']);
        self::assertCount(2, $one['items']);
        self::assertCount(1, $two['items']);
        foreach ([...$one['items'], ...$two['items']] as $item) {
            self::assertSame('campaign_ended', $item['reason_code']);
            self::assertStringStartsWith('group-item:'.$run['run_key'], $item['operation_id']);
            self::assertSame(1, DB::table('tm_audit_events')->where('action', 'group_item_processed')->where('operation_id', $item['operation_id'])->count());
        }
        self::assertStringNotContainsString('PRIVATE-HOST-TEXT', json_encode([$one, $two]));
        DB::table('host_permissions')->delete();
        self::assertSame([], $this->groupService()->report($this->manager(), $run['run_key'])['items']);
        self::assertSame([], $this->groupService()->report($this->manager(), $run['run_key'])['counts']);
    }

    public function test_one_denied_member_does_not_escape_per_item_authorization_or_block_others(): void
    {
        [$group, $instances] = $this->campaignFixture(100);
        $this->closeCampaign();
        $run = $this->groupService()->executeClosure($this->manager(), $this->groupService()->previewClosure($this->manager(), $group['group_key']), 'close');
        DB::table('host_permissions')->where('subject_id', $instances[0]['subject_id'])->update(['can_edit' => false]);
        $report = $this->processAll($run['run_key']);
        self::assertSame(['denied' => 1, 'failed' => 99], $report['counts']);
        self::assertSame('new', $this->instances()->get($this->manager(), $instances[0]['instance_key'])['current_state_key']);
        self::assertSame(99, DB::table('tm_instance_transitions')->where('to_state', 'failed')->count());
        self::assertSame(1, DB::table('tm_group_operation_items')->where('result', 'denied')->where('error_code', 'not_found')->count());
    }

    public function test_host_veto_is_one_manual_review_item_under_partial_success(): void
    {
        [$group, $instances] = $this->campaignFixture(3);
        $this->closeCampaign();
        $run = $this->groupService()->executeClosure($this->manager(), $this->groupService()->previewClosure($this->manager(), $group['group_key']), 'close');
        DB::table('host_subjects')->where('id', $instances[0]['subject_id'])->update(['veto' => true]);
        self::assertSame(['failed' => 2, 'manual_review' => 1], $this->processAll($run['run_key'])['counts']);
        self::assertSame('new', $this->instances()->get($this->manager(), $instances[0]['instance_key'])['current_state_key']);
        self::assertSame(0, DB::table('host_subjects')->where('id', $instances[0]['subject_id'])->value('guard_touches'));
        self::assertSame('host_guard_veto', DB::table('tm_group_operation_items')->where('result', 'manual_review')->value('error_code'));
    }

    public function test_run_key_reuse_with_different_payload_is_rejected_without_new_run(): void
    {
        [$group] = $this->campaignFixture();
        $this->closeCampaign();
        $preview = $this->groupService()->previewClosure($this->manager(), $group['group_key']);
        $run = $this->groupService()->executeClosure($this->manager(), $preview, 'same-key');
        self::assertSame($run, $this->groupService()->executeClosure($this->manager(), $preview, 'same-key'));
        $preview['counts']['failed'] = 999;
        try {
            $this->groupService()->executeClosure($this->manager(), $preview, 'same-key');
            self::fail();
        } catch (DomainError $error) {
            self::assertSame('idempotency_key_conflict', $error->errorCode);
        }
        self::assertSame(1, DB::table('tm_group_operations')->count());
    }

    public function test_new_run_repreviews_terminal_members_without_duplicate_failure(): void
    {
        [$group] = $this->campaignFixture(3);
        $this->closeCampaign();
        $first = $this->groupService()->executeClosure($this->manager(), $this->groupService()->previewClosure($this->manager(), $group['group_key']), 'first');
        $this->processAll($first['run_key']);
        $transitions = DB::table('tm_instance_transitions')->count();
        $preview = $this->groupService()->previewClosure($this->manager(), $group['group_key']);
        self::assertSame(3, $preview['counts']['already_terminal']);
        $second = $this->groupService()->executeClosure($this->manager(), $preview, 'second');
        self::assertSame(['already_terminal' => 3], $this->processAll($second['run_key'])['counts']);
        self::assertSame($transitions, DB::table('tm_instance_transitions')->count());
        self::assertSame(2, DB::table('tm_outbox')->where('action', 'group_closure_completed')->count());
        self::assertSame(6, DB::table('tm_outbox')->where('action', 'group_item_processed')->count());
    }

    public function test_sla_breach_only_marks_until_explicit_campaign_failure_policy_runs(): void
    {
        [$group, $instances] = $this->campaignFixture(2, true);
        $this->clock->advance('+60 seconds');
        self::assertSame(2, $this->sla()->run($this->manager())['events']);
        foreach ($instances as $instance) {
            self::assertSame('new', $this->instances()->get($this->manager(), $instance['instance_key'])['current_state_key']);
        }
        self::assertSame(0, DB::table('tm_instance_transitions')->count());
        $this->closeCampaign();
        $run = $this->groupService()->executeClosure($this->manager(), $this->groupService()->previewClosure($this->manager(), $group['group_key']), 'close');
        self::assertSame(['failed' => 2], $this->processAll($run['run_key'])['counts']);
        self::assertSame(2, DB::table('tm_group_operation_items')->where('reason_code', 'campaign_ended')->count());
    }

    public function test_protected_delegated_contract_continues_with_source_handoff_and_clock_intact(): void
    {
        [$group, $instances, $pipeline, $contract] = $this->campaignFixture(1, true);
        $reserved = $this->reserve($instances[0], 'one');
        $handoff = $this->handoffs()->delegate($this->manager(), $reserved['instance_key'], 2, $contract['pipeline_key'], $reserved['workflow_revision_id'], 'contracts',
            ['reason_code' => 'contract_review', 'target_team' => 'contracts', 'returns' => ['approved' => ['transition' => 'contract_return']]], 'delegate');
        $this->closeCampaign();
        $run = $this->groupService()->executeClosure($this->manager(), $this->groupService()->previewClosure($this->manager(), $group['group_key']), 'close');
        self::assertSame(['continued' => 1], $this->processAll($run['run_key'])['counts']);
        self::assertSame('active', $this->handoffs()->get($this->manager(), $handoff['handoff_key'])['status']);
        self::assertSame('waiting', $this->instances()->get($this->manager(), $reserved['instance_key'])['lifecycle']);
        self::assertSame('active', DB::table('tm_workflow_instances')->where('id', $handoff['destination_id'])->value('lifecycle'));
        self::assertSame('paused', $this->sla()->get($this->manager(), $handoff['paused_visit_id'])['sla_state']);
        self::assertSame(3, $this->instances()->get($this->manager(), $reserved['instance_key'])['version']);
    }

    public function test_item_snapshot_pins_the_exact_hashed_version_when_source_changes_during_materialization(): void
    {
        [$group, $instances] = $this->campaignFixture();
        $this->closeCampaign();
        $preview = $this->groupService()->previewClosure($this->manager(), $group['group_key']);
        $armed = true;
        DB::listen(function ($event) use (&$armed, $instances) {
            if ($armed && DB::transactionLevel() > 0 && str_contains($event->sql, 'tm_process_group_members') && str_contains($event->sql, 'pending_intents')) {
                $armed = false;
                $this->instances()->transition($this->manager(), $instances[0]['instance_key'], 1, 'interleaved-win', 'win');
            }
        });
        $run = $this->groupService()->executeClosure($this->manager(), $preview, 'close');
        self::assertFalse($armed);
        self::assertSame(1, DB::table('tm_group_operation_items')->value('expected_instance_version'));
        self::assertSame(['manual_review' => 1], $this->processAll($run['run_key'])['counts']);
        self::assertSame('version_conflict', DB::table('tm_group_operation_items')->value('error_code'));
        self::assertSame('won', $this->instances()->get($this->manager(), $instances[0]['instance_key'])['current_state_key']);
        self::assertSame(0, DB::table('tm_instance_transitions')->where('to_state', 'failed')->count());
    }
}
