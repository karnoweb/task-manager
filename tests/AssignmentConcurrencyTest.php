<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\Support\ProcessBarrier;

final class AssignmentConcurrencyTest extends AssignmentTestCase
{
    public function test_round_robin_serializes_shared_team_across_two_independent_stage_rows(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires real PostgreSQL assignment concurrency.');
        }
        $r = $this->sales();
        $p = $this->pipeline($r['id']);
        $q = $this->pipeline($r['id'], name: 'Other');
        foreach ([$p, $q] as $pipeline) {
            $this->assignments()->configureStage($this->manager(), $pipeline['pipeline_key'], 'inbox', 1, 'policy-'.$pipeline['id'], ['mode' => 'round_robin', 'reference' => 'contracts']);
        }
        $this->host('lead-1');
        $this->host('lead-2');
        $results = ProcessBarrier::run([['start', 'lead-1', $r['id'], 'first', $p['pipeline_key']], ['start', 'lead-2', $r['id'], 'second', $q['pipeline_key']]]);
        self::assertCount(2, array_filter($results, fn ($r) => isset($r['key'])));
        $actors = DB::table('tm_workflow_instances')->orderBy('primary_assignee')->pluck('primary_assignee')->all();
        self::assertSame(['c', 'd'], $actors);
        self::assertSame(2, DB::table('tm_assignment_queues')->value('position'));
        self::assertSame(2, DB::table('tm_assignment_slots')->where('slot_key', 'primary')->count());
        self::assertSame(2, DB::table('tm_outbox')->where('action', 'stage_assignment_resolved')->count());
    }
}
