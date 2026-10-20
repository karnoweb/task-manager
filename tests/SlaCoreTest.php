<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\SLA\WeeklyWorkingCalendar;

final class SlaCoreTest extends SlaTestCase
{
    public function test_warning_and_breach_boundaries_are_exact_and_idempotent(): void
    {
        [$i, $v] = $this->instanceWithSla(['duration_seconds' => 86400, 'warning_before_seconds' => 3600]);
        self::assertSame('2026-10-21 00:00:00', $v['deadline_at']);
        $this->clock->advance('+22 hours 59 minutes 59 seconds'); self::assertSame(0, $this->sla()->run($this->manager())['events']);
        $this->clock->advance('+1 second'); self::assertSame(1, $this->sla()->run($this->manager())['events']);
        self::assertSame('warning', $this->sla()->get($this->manager(), $v['id'])['sla_state']);
        $this->clock->advance('+1 hour'); self::assertSame(1, $this->sla()->run($this->manager())['events']);
        self::assertSame('breached', $this->sla()->get($this->manager(), $v['id'])['sla_state']);
        self::assertSame(0, $this->sla()->run($this->manager())['events']); self::assertSame(2, DB::table('tm_sla_events')->count());
        self::assertSame('new', $this->instances()->get($this->manager(), $i['instance_key'])['current_state_key']);
    }
    public function test_iran_business_calendar_skips_friday_and_holidays(): void
    {
        $this->clock->time = new \DateTimeImmutable('2026-10-22T12:30:00Z');
        [$i, $v] = $this->instanceWithSla(['duration_seconds' => 10800, 'clock_kind' => 'business', 'calendar_ref' => 'iran-v1']);
        self::assertSame('2026-10-24 07:30:00', $v['deadline_at']);
        $calendar = new WeeklyWorkingCalendar('Asia/Tehran', [4 => [['09:00', '17:00']], 6 => [['09:00', '17:00']], 7 => [['09:00', '17:00']]], ['2026-10-24']);
        $start = new \DateTimeImmutable('2026-10-22T12:30:00Z');
        self::assertSame('2026-10-25 07:30:00', $calendar->addSeconds($start, 10800)->format('Y-m-d H:i:s'));
        self::assertSame(10800, $calendar->secondsBetween($start, $calendar->addSeconds($start, 10800)));
    }
    public function test_pause_resume_preserves_remaining_time_and_pause_metrics_across_repeats(): void
    {
        [$i, $v] = $this->instanceWithSla(['duration_seconds' => 3600]);
        $this->clock->advance('+10 minutes'); $paused = $this->sla()->pause($this->manager(), $v['id'], 1, 'pause', 'manual');
        self::assertSame(3000, $paused['remaining_seconds']);
        $this->clock->advance('+2 hours'); self::assertSame(0, $this->sla()->run($this->manager())['events']);
        $resumed = $this->sla()->resume($this->manager(), $v['id'], 2, 'resume');
        self::assertSame('2026-10-20 03:00:00', $resumed['deadline_at']); self::assertSame(7200, $resumed['paused_seconds']);
        $this->clock->advance('+10 minutes'); $paused = $this->sla()->pause($this->manager(), $v['id'], 3, 'pause2', 'manual');
        self::assertSame(2400, $paused['remaining_seconds']); $this->clock->advance('+1 hour');
        $resumed = $this->sla()->resume($this->manager(), $v['id'], 4, 'resume2');
        self::assertSame('2026-10-20 04:00:00', $resumed['deadline_at']); self::assertSame(10800, $resumed['paused_seconds']);
        $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'review', 'review');
        $closed = $this->sla()->get($this->manager(), $v['id']); self::assertSame(1200, $closed['elapsed_seconds']); self::assertSame('resolved', $closed['sla_state']);
    }
    public function test_cumulative_reentry_uses_previous_work_without_rewriting_old_visits(): void
    {
        [$i, $v] = $this->instanceWithSla(['duration_seconds' => 3600, 'reentry' => 'cumulative']);
        $this->clock->advance('+20 minutes'); $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'review', 'review');
        $old = $this->sla()->get($this->manager(), $v['id']); self::assertSame(1200, $old['elapsed_seconds']);
        $this->clock->advance('+10 minutes'); $this->instances()->transition($this->manager(), $i['instance_key'], 2, 'return', 'return');
        $visits = $this->instances()->visits($this->manager(), $i['instance_key']); self::assertCount(3, $visits);
        self::assertSame(2400, $visits[2]['remaining_seconds']); self::assertSame('2026-10-20 01:10:00', $visits[2]['deadline_at']);
        self::assertSame($old, $this->sla()->get($this->manager(), $v['id']));
    }
    public function test_policy_revision_does_not_change_existing_visit_deadline(): void
    {
        [$i, $v, $p] = $this->instanceWithSla(['duration_seconds' => 3600]);
        $this->policy()->publishSla($this->manager(), $p['pipeline_key'], 'inbox', 2, 'policy2', ['duration_seconds' => 7200]);
        self::assertSame($v['deadline_at'], $this->sla()->get($this->manager(), $v['id'])['deadline_at']);
        $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'review', 'review');
        $this->instances()->transition($this->manager(), $i['instance_key'], 2, 'return', 'return');
        $visits = $this->instances()->visits($this->manager(), $i['instance_key']); self::assertSame('2026-10-20 02:00:00', $visits[2]['deadline_at']); self::assertNotSame($v['policy_id'], $visits[2]['policy_id']);
    }
    public function test_stale_and_dead_candidate_are_labels_and_activity_starts_new_stale_epoch(): void
    {
        [$i, $v] = $this->instanceWithSla(['duration_seconds' => 14400, 'stale_after_seconds' => 7200, 'dead_candidate' => true]);
        $this->clock->advance('+2 hours'); self::assertSame(1, $this->sla()->run($this->manager())['events']);
        self::assertSame('stale', $this->sla()->get($this->manager(), $v['id'])['sla_state']);
        $this->clock->advance('+2 hours'); self::assertSame(2, $this->sla()->run($this->manager())['events']);
        self::assertSame('dead_candidate', $this->sla()->get($this->manager(), $v['id'])['sla_state']);
        self::assertSame('new', $this->instances()->get($this->manager(), $i['instance_key'])['current_state_key']);
        $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'activity', 'note', ['note' => 'meaningful']);
        self::assertSame('breached', $this->sla()->get($this->manager(), $v['id'])['sla_state']);
        $this->clock->advance('+2 hours'); self::assertSame(2, $this->sla()->run($this->manager())['events']);
        self::assertSame(1, DB::table('tm_sla_events')->where('event_key', 'breach')->count());
        self::assertSame(2, DB::table('tm_sla_events')->where('event_key', 'stale')->count());
    }
    public function test_auto_transition_without_permission_or_with_host_veto_is_manual_review(): void
    {
        [$i, $v] = $this->instanceWithSla(['duration_seconds' => 60, 'breach_transition' => 'review', 'breach_reason' => 'sla_expired']);
        $this->clock->advance('+60 seconds');
        $worker = new ExecutionContext('one', 'a', 'worker', true, ['workflow.view', 'sla.run']);
        self::assertSame(1, $this->sla()->run($worker)['events']);
        self::assertSame('manual_review', DB::table('tm_sla_events')->value('action_state'));
        self::assertSame('new', $this->instances()->get($this->manager(), $i['instance_key'])['current_state_key']);
        self::assertSame(0, DB::table('tm_instance_transitions')->count());
    }
    public function test_job_for_exited_stage_never_changes_new_stage(): void
    {
        [$i, $v] = $this->instanceWithSla(['duration_seconds' => 60, 'breach_transition' => 'review', 'breach_reason' => 'sla_expired']);
        $this->clock->advance('+59 seconds'); $next = $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'review', 'review');
        $this->clock->advance('+1 hour'); self::assertSame(0, $this->sla()->run($this->manager())['events']);
        self::assertSame($next, $this->instances()->get($this->manager(), $i['instance_key'])); self::assertSame(0, DB::table('tm_sla_events')->count());
    }
    public function test_reset_is_a_new_clock_epoch_and_not_resume(): void
    {
        [$i, $v] = $this->instanceWithSla(['duration_seconds' => 60]);
        $this->clock->advance('+60 seconds'); $this->sla()->run($this->manager()); $current = $this->sla()->get($this->manager(), $v['id']);
        $reset = $this->sla()->reset($this->manager(), $v['id'], $current['sla_version'], 'reset');
        self::assertSame(2, $reset['sla_generation']); self::assertSame('healthy', $reset['sla_state']); self::assertSame('2026-10-20 00:02:00', $reset['deadline_at']);
        $this->clock->advance('+60 seconds'); self::assertSame(1, $this->sla()->run($this->manager())['events']);
        self::assertSame(2, DB::table('tm_sla_events')->where('event_key', 'breach')->count());
    }
    public function test_calendar_and_pause_inputs_fail_closed(): void
    {
        $this->error('timezone_invalid', fn () => new WeeklyWorkingCalendar('+03:30', [1 => [['09:00', '17:00']]]));
        $r = $this->sales(); $p = $this->pipeline($r['id']);
        $this->error('calendar_unregistered', fn () => $this->policy()->publishSla($this->manager(), $p['pipeline_key'], 'inbox', 1, 'bad', ['duration_seconds' => 3600, 'clock_kind' => 'business', 'calendar_ref' => 'missing']));
        self::assertSame(0, DB::table('tm_stage_policies')->count());
    }

    public function test_auto_transition_obeys_host_veto_without_committing_host_side_effect(): void
    {
        [$i, $v] = $this->instanceWithSla(['duration_seconds' => 60, 'breach_transition' => 'review', 'breach_reason' => 'sla_expired']);
        DB::table('host_subjects')->update(['veto' => true]); $this->clock->advance('+60 seconds');
        self::assertSame(1, $this->sla()->run($this->manager())['events']);
        self::assertSame('manual_review', DB::table('tm_sla_events')->value('action_state'));
        self::assertSame('new', $this->instances()->get($this->manager(), $i['instance_key'])['current_state_key']);
        self::assertSame(0, DB::table('tm_instance_transitions')->count()); self::assertSame(0, DB::table('host_subjects')->value('guard_touches'));
    }

    public function test_authorized_auto_transition_runs_through_the_workflow_engine_and_resolves_old_visit(): void
    {
        [$i, $v] = $this->instanceWithSla(['duration_seconds' => 60, 'breach_transition' => 'review', 'breach_reason' => 'sla_expired']);
        $this->clock->advance('+60 seconds'); self::assertSame(1, $this->sla()->run($this->manager())['events']);
        self::assertSame('transitioned', DB::table('tm_sla_events')->value('action_state'));
        self::assertSame('review', $this->instances()->get($this->manager(), $i['instance_key'])['current_state_key']);
        self::assertSame('resolved', $this->sla()->get($this->manager(), $v['id'])['sla_state']);
        self::assertSame(1, DB::table('tm_instance_transitions')->count()); self::assertSame(0, $this->sla()->run($this->manager())['events']);
    }

    public function test_pause_requires_capability_reason_and_current_version_and_cannot_move_clock_backwards(): void
    {
        [$i, $v] = $this->instanceWithSla(['duration_seconds' => 3600]);
        $limited = new ExecutionContext('one', 'a', 'job', true, ['workflow.view']);
        $this->error('capability_denied', fn () => $this->sla()->pause($limited, $v['id'], 1, 'unauthorized', 'manual'));
        $this->error('reason_invalid', fn () => $this->sla()->pause($this->manager(), $v['id'], 1, 'reason', 'arbitrary'));
        $this->clock->advance('+10 minutes'); $this->sla()->pause($this->manager(), $v['id'], 1, 'pause', 'manual');
        $this->error('version_conflict', fn () => $this->sla()->resume($this->manager(), $v['id'], 1, 'stale'));
        $this->clock->advance('-1 second');
        $this->error('clock_moved_backwards', fn () => $this->sla()->resume($this->manager(), $v['id'], 2, 'backwards'));
        self::assertSame('paused', $this->sla()->get($this->manager(), $v['id'])['sla_state']);
    }

    public function test_dst_shift_boundaries_are_rejected_when_ambiguous_or_nonexistent(): void
    {
        $spring = new WeeklyWorkingCalendar('America/New_York', [7 => [['02:30', '04:00']]]);
        $this->error('calendar_local_time_invalid', fn () => $spring->addSeconds(new \DateTimeImmutable('2026-03-08T00:00:00-05:00'), 60));
        $fall = new WeeklyWorkingCalendar('America/New_York', [7 => [['01:30', '04:00']]]);
        $this->error('calendar_local_time_ambiguous', fn () => $fall->addSeconds(new \DateTimeImmutable('2026-11-01T00:00:00-04:00'), 60));
    }

    public function test_terminal_auto_fail_keeps_terminal_visit_and_frees_active_slot(): void
    {
        $d = $this->definition(); $d['states']['lost'] = ['terminal' => 'failure'];
        $d['transitions']['expire'] = ['from' => 'new', 'to' => 'lost', 'reason_codes' => ['sla_expired']];
        $r = $this->workflows()->publish($this->manager(), 'sales', 0, 'workflow', $d);
        $p = $this->pipelines()->create($this->manager(), 'Sales', $r['id'], ['inbox' => ['state' => 'new'], 'lost' => ['state' => 'lost']], 'pipeline');
        $this->policy()->publishSla($this->manager(), $p['pipeline_key'], 'inbox', 1, 'policy', ['duration_seconds' => 60, 'breach_transition' => 'expire', 'breach_reason' => 'sla_expired']);
        $this->host(); $i = $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $r['id'], 'start', pipelineKey: $p['pipeline_key']);
        $this->clock->advance('+60 seconds'); self::assertSame(1, $this->sla()->run($this->manager())['events']);
        $closed = $this->instances()->get($this->manager(), $i['instance_key']); self::assertSame('lost', $closed['current_state_key']); self::assertSame('closed', $closed['lifecycle']);
        self::assertSame(0, DB::table('tm_active_process_slots')->count()); $visits = $this->instances()->visits($this->manager(), $i['instance_key']);
        self::assertCount(2, $visits); self::assertSame('lost', $visits[1]['stage_key']); self::assertSame($visits[1]['entered_at'], $visits[1]['exited_at']);
        self::assertSame(0, $this->sla()->run($this->manager())['events']);
    }

    public function test_watchdog_recovers_pending_dead_candidate_after_prior_thresholds_committed(): void
    {
        [$i, $v] = $this->instanceWithSla(['duration_seconds' => 60, 'stale_after_seconds' => 60, 'dead_candidate' => true]);
        $this->clock->advance('+60 seconds'); $this->sla()->run($this->manager());
        // Reproduce the durable boundary before the final independent threshold commits.
        DB::table('tm_sla_events')->where('event_key', 'dead_candidate')->delete();
        DB::table('tm_outbox')->where('action', 'sla_dead_candidate')->delete(); DB::table('tm_audit_events')->where('action', 'sla_dead_candidate')->delete();
        DB::table('tm_command_journal')->where('operation_id', 'like', '%dead_candidate%')->delete();
        DB::table('tm_stage_visits')->where('id', $v['id'])->update(['dead_candidate_at' => null, 'sla_state' => 'stale']);
        self::assertSame(1, $this->sla()->run($this->manager())['events']);
        self::assertSame(1, DB::table('tm_sla_events')->where('event_key', 'dead_candidate')->count());
        self::assertSame('dead_candidate', $this->sla()->get($this->manager(), $v['id'])['sla_state']);
        self::assertSame(0, $this->sla()->run($this->manager())['events']);
    }
}
