<?php

namespace Karnoweb\TaskManager\Tests\Scenarios\Complete;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;
use Karnoweb\TaskManager\Workflow\Definition;

final class Scenario06WorkflowPolicyTest extends WorkflowTestCase
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

    public function test_complete_scenario_06(): void
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
            self::assertSame('transition_policy_denied', $error->getMessage());
            self::assertSame(['reviewing', 'done'], $error->details['allowed_destinations']);
        }
        self::assertSame(1, DB::table('tm_security_decisions')->where('reason', 'transition_policy_denied')->count());
        $back = $this->instances()->transition($this->manager(), $i['instance_key'], 3, 'back', 'move.waiting.reviewing');
        self::assertSame('reviewing', $back['current_state_key']);
        self::assertSame(3, DB::table('tm_instance_transitions')->count());

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
}
