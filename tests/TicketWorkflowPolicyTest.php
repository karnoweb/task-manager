<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Workflow\Definition;

final class TicketWorkflowPolicyTest extends WorkflowTestCase
{
    private function ticketWorkflow(): array
    {
        $states = array_fill_keys(['open', 'reviewing', 'waiting', 'done', 'closed', 'archived'], ['terminal' => 'none']);
        $states['closed'] = $states['archived'] = ['terminal' => 'success'];
        $definition = Definition::stateMachine($states, 'open', ['close' => ['to' => 'closed', 'capability' => 'ticket.close_result', 'reason_required' => true, 'reason_codes' => ['resolved'], 'required_fields' => ['result']]]);

        return $this->workflows()->publish($this->manager(), 'tickets', 0, 'workflow', $definition);
    }

    private function ticketPipeline(array $revision, string $mode): array
    {
        $stages = array_combine(['open', 'reviewing', 'waiting', 'done', 'closed', 'archived'], array_map(fn ($state) => ['state' => $state], ['open', 'reviewing', 'waiting', 'done', 'closed', 'archived']));

        return $this->pipelines()->create($this->manager(), $mode, $revision['id'], $stages, 'pipeline-'.$mode, $mode);
    }

    public function test_sequential_forward_backward_and_structured_jump_denial(): void
    {
        $r = $this->ticketWorkflow();
        $p = $this->ticketPipeline($r, 'sequential');
        $this->host('ticket', 'support.ticket');
        $i = $this->instances()->start($this->manager(), 'support.ticket', 'ticket', $r['id'], 'start', pipelineKey: $p['pipeline_key']);
        $i = $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'review', 'move.open.reviewing');
        $i = $this->instances()->transition($this->manager(), $i['instance_key'], 2, 'wait', 'move.reviewing.waiting');
        try {
            $this->instances()->transition($this->manager(), $i['instance_key'], 3, 'bad', 'move.waiting.closed');
            self::fail('Jump must be denied.');
        } catch (DomainError $error) {
            self::assertSame('transition_policy_denied', $error->errorCode);
            self::assertSame(['reviewing', 'done'], $error->details['allowed_destinations']);
        }
        self::assertSame(1, DB::table('tm_security_decisions')->where('reason', 'transition_policy_denied')->count());
        $back = $this->instances()->transition($this->manager(), $i['instance_key'], 3, 'back', 'move.waiting.reviewing');
        self::assertSame('reviewing', $back['current_state_key']);
        self::assertSame(3, DB::table('tm_instance_transitions')->count());
    }

    public function test_free_and_no_pipeline_still_enforce_host_guard_versions_and_operation_hash(): void
    {
        $r = $this->ticketWorkflow();
        $p = $this->ticketPipeline($r, 'free');
        foreach (['unplaced', 'free'] as $id) {
            $this->host($id, 'support.ticket');
            $i = $this->instances()->start($this->manager(), 'support.ticket', $id, $r['id'], 'start-'.$id, pipelineKey: $id === 'free' ? $p['pipeline_key'] : null);
            $closed = $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'close-'.$id, 'move.open.closed');
            self::assertSame('closed', $closed['current_state_key']);
            self::assertSame($closed, $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'close-'.$id, 'move.open.closed'));
            $this->error('idempotency_key_conflict', fn () => $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'close-'.$id, 'move.open.done'));
        }
        $this->host('veto', 'support.ticket');
        $i = $this->instances()->start($this->manager(), 'support.ticket', 'veto', $r['id'], 'veto-start');
        DB::table('host_subjects')->where('id', 'veto')->update(['veto' => true]);
        $this->error('host_guard_veto', fn () => $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'veto-close', 'move.open.closed'));
        self::assertSame('open', $this->instances()->get($this->manager(), $i['instance_key'])['current_state_key']);
    }

    public function test_business_command_has_separate_capability_required_result_and_no_ordinary_bypass(): void
    {
        $r = $this->ticketWorkflow();
        $p = $this->ticketPipeline($r, 'sequential');
        $this->host('ticket', 'support.ticket');
        $i = $this->instances()->start($this->manager(), 'support.ticket', 'ticket', $r['id'], 'start', pipelineKey: $p['pipeline_key']);
        $this->error('business_command_required', fn () => $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'bypass', 'command.close.open', ['reason_code' => 'resolved', 'result' => 'done']));
        $this->error('capability_denied', fn () => $this->instances()->executeBusinessCommand($this->manager(), $i['instance_key'], 1, 'denied', 'close', ['reason_code' => 'resolved', 'result' => 'done']));
        $context = new ExecutionContext('one', 'a', 'business', capabilities: [...$this->manager()->capabilities, 'ticket.close_result']);
        $this->error('required_field_missing', fn () => $this->instances()->executeBusinessCommand($context, $i['instance_key'], 1, 'missing', 'close', ['reason_code' => 'resolved']));
        $closed = $this->instances()->executeBusinessCommand($context, $i['instance_key'], 1, 'close', 'close', ['reason_code' => 'resolved', 'result' => 'Resolved privately']);
        self::assertSame('closed', $closed['current_state_key']);
        self::assertSame($closed, $this->instances()->executeBusinessCommand($context, $i['instance_key'], 1, 'close', 'close', ['reason_code' => 'resolved', 'result' => 'Resolved privately']));
        self::assertSame(1, DB::table('tm_instance_transitions')->count());
        self::assertStringNotContainsString('Resolved privately', DB::table('tm_audit_events')->pluck('payload')->implode(''));
    }

    public function test_pipeline_change_applies_new_policy_and_detach_preserves_process_identity_and_assignments(): void
    {
        $r = $this->ticketWorkflow();
        $free = $this->ticketPipeline($r, 'free');
        $sequential = $this->ticketPipeline($r, 'sequential');
        $this->host('ticket', 'support.ticket');
        $i = $this->instances()->start($this->manager(), 'support.ticket', 'ticket', $r['id'], 'start', pipelineKey: $free['pipeline_key']);
        $this->error('state_mapping_required', fn () => $this->instances()->previewMove($this->manager(), $i['instance_key'], $sequential['pipeline_key'], []));
        $i = $this->instances()->moveToPipeline($this->manager(), $this->instances()->previewMove($this->manager(), $i['instance_key'], $sequential['pipeline_key'], ['open' => 'open']), 'move');
        $this->error('transition_policy_denied', fn () => $this->instances()->transition($this->manager(), $i['instance_key'], $i['version'], 'jump', 'move.open.closed'));
        $left = $this->instances()->leavePipeline($this->manager(), $i['instance_key'], $i['version'], 'leave');
        self::assertNull($left['pipeline_id']);
        foreach (['instance_key', 'subject_id', 'current_state_key', 'owner_ref', 'primary_assignee', 'lifecycle'] as $field) {
            self::assertSame($i[$field], $left[$field]);
        }
        self::assertSame(1, DB::table('tm_active_process_slots')->count());
        self::assertSame(1, DB::table('host_subjects')->count());
        self::assertSame(1, DB::table('tm_audit_events')->where('action', 'instance_pipeline_left')->count());
    }
}
