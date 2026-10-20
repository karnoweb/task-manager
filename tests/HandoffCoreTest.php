<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;

final class HandoffCoreTest extends HandoffTestCase
{
    public function test_delegate_pauses_origin_without_creating_a_new_origin_visit(): void
    {
        [$h, $origin, $destination] = $this->delegateFixture();
        $waiting = $this->instances()->get($this->manager(), $origin['instance_key']);
        self::assertSame('waiting', $waiting['lifecycle']); self::assertSame('new', $waiting['current_state_key']);
        self::assertSame('active', $destination['lifecycle']); self::assertSame('contracts', $destination['slot_key']);
        self::assertNotNull($this->sla()->get($this->manager(), $h['paused_visit_id'])['paused_at']);
        self::assertCount(1, $this->instances()->visits($this->manager(), $origin['instance_key']));
        self::assertSame($waiting['case_id'], $destination['case_id']);
        $this->expectExceptionMessage('instance_on_hold');
        $this->instances()->transition($this->manager(), $origin['instance_key'], 2, 'bypass', 'review');
    }
    public function test_success_returns_through_workflow_once_and_preserves_clock_metrics(): void
    {
        [$h, $origin, $destination] = $this->delegateFixture();
        $this->clock->advance('+60 seconds');
        $this->instances()->transition($this->manager(), $destination['instance_key'], 1, 'contract-accepted', 'accept');
        $result = $this->handoffs()->settle($this->manager(), $h['handoff_key'], 1, 'return');
        self::assertSame('approved', $result['outcome']); self::assertSame('resolved', $result['status']);
        self::assertSame('review', $this->instances()->get($this->manager(), $origin['instance_key'])['current_state_key']);
        self::assertSame(60, $this->sla()->get($this->manager(), $h['paused_visit_id'])['paused_seconds']);
        $count = DB::table('tm_audit_events')->count();
        self::assertSame($result, $this->handoffs()->settle($this->manager(), $h['handoff_key'], 1, 'return'));
        self::assertSame($result, $this->handoffs()->settle($this->manager(), $h['handoff_key'], 2, 'duplicate-return'));
        self::assertSame($count, DB::table('tm_audit_events')->count());
    }
    public function test_changed_origin_is_never_overwritten(): void
    {
        [$h, $origin, $destination] = $this->delegateFixture();
        $this->instances()->transition($this->manager(), $origin['instance_key'], 2, 'origin-cancel', 'cancel', ['reason_code' => 'recalled']);
        $this->instances()->transition($this->manager(), $destination['instance_key'], 1, 'destination-success', 'accept');
        $r = $this->handoffs()->settle($this->manager(), $h['handoff_key'], 1, 'return');
        self::assertSame('return_conflict', $r['status']); self::assertSame('approved', $r['outcome']);
        self::assertSame('handoff_return_conflict', $r['failure_code']);
        self::assertSame('cancelled', $this->instances()->get($this->manager(), $origin['instance_key'])['current_state_key']);
    }
    public function test_return_guard_failure_preserves_destination_evidence_and_waiting_origin(): void
    {
        [$h, $origin, $destination] = $this->delegateFixture();
        $this->instances()->transition($this->manager(), $destination['instance_key'], 1, 'destination-reject', 'reject', ['reason_code' => 'invalid']);
        $r = $this->handoffs()->settle($this->manager(), $h['handoff_key'], 1, 'return');
        self::assertSame('rejected', $r['outcome']); self::assertSame('required_field_missing', $r['failure_code']);
        self::assertSame('waiting', $this->instances()->get($this->manager(), $origin['instance_key'])['lifecycle']);
        self::assertNotNull($this->sla()->get($this->manager(), $h['paused_visit_id'])['paused_at']);
        self::assertSame('closed', $this->instances()->get($this->manager(), $destination['instance_key'])['lifecycle']);
    }
    public function test_recall_is_a_guarded_cancel_and_never_success(): void
    {
        [$h, $origin, $destination] = $this->delegateFixture();
        $r = $this->handoffs()->recall($this->manager(), $h['handoff_key'], 1, 'recall');
        self::assertSame('recalled', $r['outcome']); self::assertSame('resolved', $r['status']);
        self::assertSame('cancelled', $this->instances()->get($this->manager(), $destination['instance_key'])['current_state_key']);
        self::assertSame('review', $this->instances()->get($this->manager(), $origin['instance_key'])['current_state_key']);
    }
    public function test_timeout_cannot_run_early_and_replays_one_result(): void
    {
        [$h] = $this->delegateFixture();
        try { $this->handoffs()->recall($this->manager(), $h['handoff_key'], 1, 'early-timeout', true); self::fail(); }
        catch (DomainError $e) { self::assertSame('handoff_timeout_not_due', $e->getMessage()); }
        $this->clock->advance('+120 seconds');
        $r = $this->handoffs()->recall($this->manager(), $h['handoff_key'], 1, 'timeout', true);
        self::assertSame('timed_out', $r['outcome']); self::assertSame('resolved', $r['status']);
        self::assertSame($r, $this->handoffs()->recall($this->manager(), $h['handoff_key'], 1, 'timeout', true));
    }
    public function test_scope_is_checked_before_handoff_details(): void
    {
        [$h] = $this->delegateFixture(false);
        $this->expectExceptionMessage('not_found'); $this->handoffs()->get($this->manager(scope: 'two'), $h['handoff_key']);
    }
    public function test_nonterminal_destination_cannot_claim_success(): void
    {
        [$h] = $this->delegateFixture(false);
        $this->expectExceptionMessage('handoff_destination_not_terminal'); $this->handoffs()->settle($this->manager(), $h['handoff_key'], 1, 'premature');
    }
    public function test_rejection_returns_for_correction_with_original_reason_audited(): void
    {
        $policy = $this->returnPolicy(); $policy['returns']['rejected'] = ['transition' => 'correct', 'reason_code' => 'invalid'];
        [$h, $origin, $destination] = $this->delegateFixture(false, $policy);
        $this->instances()->transition($this->manager(), $destination['instance_key'], 1, 'reject', 'reject', ['reason_code' => 'invalid']);
        $r = $this->handoffs()->settle($this->manager(), $h['handoff_key'], 1, 'return');
        self::assertSame('resolved', $r['status']); self::assertSame('rejected', $r['outcome']);
        $result = $this->instances()->get($this->manager(), $origin['instance_key']);
        self::assertSame('new', $result['current_state_key']); self::assertSame('active', $result['lifecycle']);
        self::assertStringContainsString('invalid', DB::table('tm_audit_events')->where('aggregate_key', $origin['instance_key'])->where('action', 'instance_transitioned')->value('payload'));
    }
    public function test_duplicate_delegate_does_not_create_a_second_destination(): void
    {
        [$origin, $destination, $r] = $this->handoffFixture(false);
        $h = $this->handoffs()->delegate($this->manager(), $origin['instance_key'], 1, $destination['pipeline_key'], $r['id'], 'contracts', $this->returnPolicy(), 'delegate');
        self::assertSame($h, $this->handoffs()->delegate($this->manager(), $origin['instance_key'], 1, $destination['pipeline_key'], $r['id'], 'contracts', $this->returnPolicy(), 'delegate'));
        self::assertSame(2, DB::table('tm_workflow_instances')->count()); self::assertSame(1, DB::table('tm_handoffs')->count());
        $this->expectExceptionMessage('version_conflict');
        $this->handoffs()->delegate($this->manager(), $origin['instance_key'], 1, $destination['pipeline_key'], $r['id'], 'other', $this->returnPolicy(), 'again');
    }
    public function test_cycle_is_rejected_before_creating_another_process(): void
    {
        [$h, $origin, $destination, $pipeline] = $this->delegateFixture(false);
        $this->expectExceptionMessage('handoff_cycle_or_depth');
        $this->handoffs()->delegate($this->manager(), $destination['instance_key'], 1, $pipeline['pipeline_key'], $origin['workflow_revision_id'], 'cycle', $this->returnPolicy(), 'cycle');
    }
    public function test_denied_destination_rolls_back_case_origin_and_events(): void
    {
        [$origin, $destination, $r] = $this->handoffFixture(false);
        DB::table('host_subjects')->where('id', 'lead-1')->update(['veto' => true]); $count = DB::table('tm_audit_events')->count();
        try { $this->handoffs()->delegate($this->manager(), $origin['instance_key'], 1, $destination['pipeline_key'], $r['id'], 'contracts', $this->returnPolicy(), 'delegate'); self::fail(); }
        catch (DomainError $e) { self::assertSame('host_guard_veto', $e->getMessage()); }
        self::assertSame(0, DB::table('tm_handoffs')->count()); self::assertSame(0, DB::table('tm_process_cases')->count());
        self::assertSame($count, DB::table('tm_audit_events')->count()); self::assertSame(1, DB::table('tm_workflow_instances')->count());
        self::assertSame('active', $this->instances()->get($this->manager(), $origin['instance_key'])['lifecycle']);
    }
    public function test_delegation_cannot_hide_an_unprocessed_expired_deadline(): void
    {
        [$origin, $destination, $r] = $this->handoffFixture(); $this->clock->advance('+3600 seconds');
        try { $this->handoffs()->delegate($this->manager(), $origin['instance_key'], 1, $destination['pipeline_key'], $r['id'], 'contracts', $this->returnPolicy(), 'delegate'); self::fail(); }
        catch (DomainError $e) { self::assertSame('sla_due_requires_detection', $e->getMessage()); }
        self::assertSame(1, DB::table('tm_workflow_instances')->count()); self::assertSame(0, DB::table('tm_handoffs')->count());
        self::assertSame(1, $this->sla()->run($this->manager())['events']);
    }
    public function test_cancellation_has_its_own_outcome_and_return_mapping(): void
    {
        [$h, $origin, $destination] = $this->delegateFixture(false);
        $this->instances()->transition($this->manager(), $destination['instance_key'], 1, 'cancel-destination', 'cancel', ['reason_code' => 'recalled']);
        $result = $this->handoffs()->settle($this->manager(), $h['handoff_key'], 1, 'return');
        self::assertSame('cancelled', $result['outcome']); self::assertSame('resolved', $result['status']);
        self::assertSame('review', $this->instances()->get($this->manager(), $origin['instance_key'])['current_state_key']);
        self::assertSame('cancelled', DB::table('tm_handoff_outcomes')->value('outcome'));
    }
    public function test_contradictory_terminal_outcome_cannot_replace_recorded_success(): void
    {
        [$h, $origin, $destination] = $this->delegateFixture(false);
        $this->instances()->transition($this->manager(), $destination['instance_key'], 1, 'accept', 'accept');
        $this->handoffs()->settle($this->manager(), $h['handoff_key'], 1, 'return');
        $this->instances()->transition($this->manager(), $destination['instance_key'], 2, 'reopen', 'reopen');
        $this->instances()->transition($this->manager(), $destination['instance_key'], 3, 'reject', 'reject', ['reason_code' => 'invalid']);
        try { $this->handoffs()->settle($this->manager(), $h['handoff_key'], 2, 'contradictory-return'); self::fail(); }
        catch (DomainError $e) { self::assertSame('handoff_already_resolved', $e->getMessage()); }
        self::assertSame('approved', DB::table('tm_handoff_outcomes')->value('outcome'));
        self::assertSame(3, $this->instances()->get($this->manager(), $origin['instance_key'])['version']);
    }
    public function test_destination_clock_runs_independently_while_origin_is_paused(): void
    {
        [$origin, $destination, $r] = $this->handoffFixture();
        $this->policy()->publishSla($this->manager(), $destination['pipeline_key'], 'inbox', 1, 'destination-sla', ['duration_seconds' => 60]);
        $h = $this->handoffs()->delegate($this->manager(), $origin['instance_key'], 1, $destination['pipeline_key'], $r['id'], 'contracts', $this->returnPolicy(), 'delegate');
        $this->clock->advance('+120 seconds'); self::assertSame(1, $this->sla()->run($this->manager())['events']);
        $visit = DB::table('tm_stage_visits')->where('instance_id', $h['destination_id'])->first();
        self::assertSame('breached', $visit->sla_state);
        self::assertSame('paused', $this->sla()->get($this->manager(), $h['paused_visit_id'])['sla_state']);
        self::assertSame(3600, $this->sla()->get($this->manager(), $h['paused_visit_id'])['remaining_seconds']);
        $destinationKey = DB::table('tm_workflow_instances')->where('id', $h['destination_id'])->value('instance_key');
        $this->instances()->transition($this->manager(), $destinationKey, 1, 'accept', 'accept');
        $this->handoffs()->settle($this->manager(), $h['handoff_key'], 1, 'return');
        self::assertSame(120, $this->sla()->get($this->manager(), $h['paused_visit_id'])['paused_seconds']);
        self::assertSame(0, DB::table('tm_sla_events')->where('visit_id', $h['paused_visit_id'])->count());
    }
    public function test_destination_acceptance_receives_route_before_origin_is_changed(): void
    {
        [$origin, $destination, $r] = $this->handoffFixture(false);
        $handler = new \Karnoweb\TaskManager\Tests\Support\HostSubjectHandler(DB::connection(), 'crm.lead');
        $route = $destination['pipeline_key'];
        $this->app->instance(\Karnoweb\TaskManager\SubjectRegistry::class, new \Karnoweb\TaskManager\SubjectRegistry(['crm.lead' => new class($handler, $route) implements \Karnoweb\TaskManager\Contracts\SubjectHandler {
            public function __construct(private $handler, private string $route) {}
            public function query(\Karnoweb\TaskManager\Context\ExecutionContext $context, string $capability): \Illuminate\Database\Query\Builder { return $this->handler->query($context, $capability); }
            public function idColumn(): string { return 'id'; }
            public function scopeColumn(): string { return 'scope_key'; }
            public function guard(\Karnoweb\TaskManager\Context\ExecutionContext $context, string $subjectId, array $instance, string $action, array $payload): void {
                if ($action === 'start' && ($payload['pipeline_key'] ?? null) === $this->route) { throw new DomainError('destination_not_accepted'); }
                $this->handler->guard($context, $subjectId, $instance, $action, $payload);
            }
        }]));
        try { $this->handoffs()->delegate($this->manager(), $origin['instance_key'], 1, $route, $r['id'], 'contracts', $this->returnPolicy(), 'delegate'); self::fail(); }
        catch (DomainError $e) { self::assertSame('destination_not_accepted', $e->getMessage()); }
        self::assertSame(0, DB::table('tm_handoffs')->count()); self::assertSame(1, DB::table('tm_workflow_instances')->count());
        self::assertSame('active', $this->instances()->get($this->manager(), $origin['instance_key'])['lifecycle']);
    }
    public function test_changed_live_origin_requires_review_without_losing_its_waiting_flag(): void
    {
        [$h, $origin, $destination] = $this->delegateFixture();
        $changed = $this->instances()->transition($this->manager(), $origin['instance_key'], 2, 'progress', 'progress');
        self::assertSame('waiting', $changed['lifecycle']);
        $this->instances()->transition($this->manager(), $destination['instance_key'], 1, 'accept', 'accept');
        $r = $this->handoffs()->settle($this->manager(), $h['handoff_key'], 1, 'return');
        self::assertSame('return_conflict', $r['status']); self::assertSame('handoff_return_conflict', $r['failure_code']);
        $after = $this->instances()->get($this->manager(), $origin['instance_key']);
        self::assertSame('new', $after['current_state_key']); self::assertSame(3, $after['version']);
        self::assertNotNull($this->sla()->get($this->manager(), $h['paused_visit_id'])['paused_at']);
    }
    public function test_two_boards_read_independent_instances_for_one_subject(): void
    {
        [$h, $origin, $destination] = $this->delegateFixture(false);
        $this->instances()->transition($this->manager(), $destination['instance_key'], 1, 'review-destination', 'review');
        $all = $this->instances()->query($this->manager()); self::assertCount(2, $all);
        self::assertSame($origin['subject_id'], $destination['subject_id']);
        self::assertSame('new', $this->instances()->get($this->manager(), $origin['instance_key'])['current_state_key']);
        self::assertSame('review', $this->instances()->get($this->manager(), $destination['instance_key'])['current_state_key']);
        self::assertSame(2, DB::table('tm_active_process_slots')->count());
    }
    public function test_committed_destination_is_recovered_by_scoped_worker_after_restart(): void
    {
        [$h, $origin, $destination] = $this->delegateFixture(false);
        $this->instances()->transition($this->manager(), $destination['instance_key'], 1, 'accept', 'accept');
        self::assertSame('waiting', $this->instances()->get($this->manager(), $origin['instance_key'])['lifecycle']);
        self::assertSame(['processed' => 1, 'conflicts' => 0], $this->handoffs()->run($this->manager()));
        self::assertSame(['processed' => 0, 'conflicts' => 0], $this->handoffs()->run($this->manager()));
        self::assertSame('resolved', $this->handoffs()->get($this->manager(), $h['handoff_key'])['status']);
    }
    public function test_nested_handoffs_share_a_redacted_permission_filtered_case_timeline(): void
    {
        [$h, $origin, $destination] = $this->delegateFixture(false);
        $stages = ['inbox' => ['state' => 'new'], 'review' => ['state' => 'review'], 'won' => ['state' => 'won']];
        $risk = $this->pipelines()->create($this->manager(), 'Risk', $origin['workflow_revision_id'], $stages, 'risk-pipeline');
        $second = $this->handoffs()->delegate($this->manager(), $destination['instance_key'], 1, $risk['pipeline_key'], $origin['workflow_revision_id'], 'risk', $this->returnPolicy(), 'risk-handoff');
        $riskKey = DB::table('tm_workflow_instances')->where('id', $second['destination_id'])->value('instance_key');
        $this->instances()->transition($this->manager(), $riskKey, 1, 'risk-accept', 'accept');
        self::assertSame('resolved', $this->handoffs()->settle($this->manager(), $second['handoff_key'], 1, 'risk-return')['status']);
        $this->instances()->transition($this->manager(), $destination['instance_key'], 3, 'contract-approve', 'approve', ['reason_code' => 'approved']);
        self::assertSame('resolved', $this->handoffs()->settle($this->manager(), $h['handoff_key'], 1, 'return')['status']);
        $caseKey = DB::table('tm_process_cases')->value('case_key');
        $timeline = $this->cases()->timeline($this->manager(), $caseKey);
        self::assertCount(3, array_unique(array_column($timeline, 'aggregate_key')));
        self::assertStringNotContainsString('PRIVATE-HOST-TEXT', json_encode($timeline));
        self::assertSame([], $this->cases()->timeline($this->manager('b'), $caseKey));
        self::assertSame(1, DB::table('tm_process_cases')->count()); self::assertSame(1, DB::table('tm_case_subjects')->count());
    }
    public function test_cross_scope_destination_cannot_create_a_handoff_or_case_link(): void
    {
        [$origin] = $this->handoffFixture(false); $other = $this->manager(scope: 'two');
        $r = $this->workflows()->publish($other, 'sales', 0, 'other-workflow', $this->definition());
        $p = $this->pipelines()->create($other, 'Other', $r['id'], ['inbox' => ['state' => 'new'], 'review' => ['state' => 'review'], 'won' => ['state' => 'won']], 'other-pipeline');
        try { $this->handoffs()->delegate($this->manager(), $origin['instance_key'], 1, $p['pipeline_key'], $r['id'], 'cross', $this->returnPolicy(), 'cross-scope'); self::fail(); }
        catch (DomainError $e) { self::assertSame('not_found', $e->getMessage()); }
        self::assertSame(0, DB::table('tm_handoffs')->count()); self::assertSame(0, DB::table('tm_process_cases')->count());
        self::assertSame(0, DB::table('tm_case_subjects')->count());
        self::assertSame('active', $this->instances()->get($this->manager(), $origin['instance_key'])['lifecycle']);
    }
}

