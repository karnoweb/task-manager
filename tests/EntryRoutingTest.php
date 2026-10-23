<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\EntryRoutingProvider;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Workflow\EntryRoutingService;

final class EntryRoutingTest extends WorkflowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(EntryRoutingProvider::class, new class implements EntryRoutingProvider
        {
            public function authorize(ExecutionContext $context, string $routingKey): void
            {
                if (! in_array($routingKey, ['support', 'sales', 'child', 'orphan', 'cycle'], true) || $context->scope !== 'one') {
                    throw new DomainError('not_found');
                }
            }

            public function parent(ExecutionContext $context, string $routingKey): ?string
            {
                return match ($routingKey) {
                    'child' => 'support', 'cycle' => 'cycle', default => null
                };
            }
        });
    }

    private function routing(): EntryRoutingService
    {
        return $this->app->make(EntryRoutingService::class);
    }

    public function test_own_parent_and_missing_entry_destinations_have_one_stable_instance_and_audit(): void
    {
        $r = $this->sales();
        $p = $this->pipeline($r['id']);
        $routing = $this->routing();
        $routing->setDefault($this->manager(), 'support', $p['pipeline_key'], 0, 'default');
        foreach (['support', 'child', 'orphan'] as $node) {
            $this->host($node, 'support.ticket');
            $i = $routing->enter($this->manager(), 'support.ticket', $node, $r['id'], $node, 'entry-'.$node);
            self::assertSame($node === 'orphan' ? null : $p['id'], $i['pipeline_id']);
            self::assertSame('new', $i['current_state_key']);
            self::assertSame($i, $routing->enter($this->manager(), 'support.ticket', $node, $r['id'], $node, 'entry-'.$node));
        }
        self::assertSame(3, DB::table('tm_active_process_slots')->count());
        self::assertSame(3, DB::table('tm_outbox')->where('action', 'entry_route_selected')->count());
        self::assertSame(0, DB::table('tm_tasks')->count());
        $this->error('routing_hierarchy_cycle', fn () => $routing->resolve($this->manager(), 'cycle'));
    }

    public function test_default_replacement_is_versioned_and_does_not_relocate_existing_subjects(): void
    {
        $r = $this->sales();
        $a = $this->pipeline($r['id'], name: 'A');
        $b = $this->pipeline($r['id'], name: 'B');
        $routing = $this->routing();
        $first = $routing->setDefault($this->manager(), 'support', $a['pipeline_key'], 0, 'a');
        $this->host('ticket', 'support.ticket');
        $old = $routing->enter($this->manager(), 'support.ticket', 'ticket', $r['id'], 'support', 'entry');
        $second = $routing->setDefault($this->manager(), 'support', $b['pipeline_key'], 1, 'b');
        $this->error('version_conflict', fn () => $routing->setDefault($this->manager(), 'support', $a['pipeline_key'], 1, 'stale'));
        self::assertSame(2, $second['version']);
        self::assertSame(1, DB::table('tm_entry_routes')->count());
        self::assertSame($old, $this->instances()->get($this->manager(), $old['instance_key']));
        self::assertSame($b['pipeline_key'], $routing->resolve($this->manager(), 'support')['pipeline_key']);
        $this->error('not_found', fn () => $routing->setDefault($this->manager('a', 'two'), 'support', $b['pipeline_key'], 0, 'foreign'));
        self::assertSame($a['id'], $first['pipeline_id']);
    }

    public function test_retirement_replaces_default_and_recoverable_detach_preserves_records_and_history(): void
    {
        $r = $this->sales();
        $a = $this->pipeline($r['id'], name: 'A');
        $b = $this->pipeline($r['id'], name: 'B');
        $routing = $this->routing();
        $routing->setDefault($this->manager(), 'support', $a['pipeline_key'], 0, 'default');
        $original = [];
        foreach (['one-ticket', 'two-ticket'] as $id) {
            $this->host($id, 'support.ticket');
            $original[] = $routing->enter($this->manager(), 'support.ticket', $id, $r['id'], 'support', 'entry-'.$id);
        }
        $routing->retirePipeline($this->manager(), $a['pipeline_key'], 1, 'retire', $b['pipeline_key']);
        self::assertSame($b['pipeline_key'], $routing->resolve($this->manager(), 'support')['pipeline_key']);
        self::assertSame(['processed' => 1, 'visible_remaining' => 1], $routing->detachRetired($this->manager(), $a['pipeline_key'], 1));
        self::assertSame(['processed' => 1, 'visible_remaining' => 0], $routing->detachRetired($this->manager(), $a['pipeline_key'], 1));
        self::assertSame(['processed' => 0, 'visible_remaining' => 0], $routing->detachRetired($this->manager(), $a['pipeline_key'], 1));
        foreach ($original as $before) {
            $after = $this->instances()->get($this->manager(), $before['instance_key']);
            self::assertNull($after['pipeline_id']);
            self::assertSame($before['current_state_key'], $after['current_state_key']);
            self::assertSame($before['owner_ref'], $after['owner_ref']);
            self::assertSame($before['primary_assignee'], $after['primary_assignee']);
        }
        self::assertSame(2, DB::table('host_subjects')->count());
        self::assertSame(2, DB::table('tm_stage_visits')->whereNotNull('exited_at')->count());
        self::assertSame(2, DB::table('tm_active_process_slots')->count());
        self::assertSame(0, DB::table('tm_instance_transitions')->count());
        $this->host('late', 'support.ticket');
        $this->error('pipeline_retired', fn () => $this->instances()->start($this->manager(), 'support.ticket', 'late', $r['id'], 'late-start', pipelineKey: $a['pipeline_key']));
    }
}
