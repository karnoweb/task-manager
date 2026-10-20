<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;

final class WorkflowConcurrencyTest extends WorkflowTestCase
{
    private function race(array $commands): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $error, $message); self::assertIsResource($server);
        $address = 'tcp://'.stream_socket_get_name($server, false); $workers = []; $clients = [];
        try {
            foreach ($commands as $command) {
                $process = proc_open([PHP_BINARY, __DIR__.'/Support/concurrent-workflow.php', $address, ...array_map(strval(...), $command)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process); $workers[] = [$process, $pipes];
            }
            foreach ($workers as $_) {
                $client = stream_socket_accept($server, 10); self::assertIsResource($client); stream_set_timeout($client, 10);
                self::assertSame("ready\n", fgets($client)); $clients[] = $client;
            }
            foreach ($clients as $client) { fwrite($client, "go\n"); fclose($client); }
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]); self::assertSame(0, proc_close($process), $errors);
                $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            }
            return $results;
        } finally { fclose($server); }
    }
    public function test_same_slot_concurrent_start_has_one_winner_and_no_orphan_instance(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') { $this->markTestSkipped('Requires real PostgreSQL process concurrency.'); }
        $r = $this->sales(); $this->host(); $events = DB::table('tm_outbox')->count();
        $results = $this->race([['start', 'lead-1', $r['id'], 'start-a'], ['start', 'lead-1', $r['id'], 'start-b']]);
        self::assertCount(1, array_filter($results, fn ($r) => isset($r['key'])));
        self::assertCount(1, array_filter($results, fn ($r) => ($r['error'] ?? '') === 'active_slot_conflict'));
        self::assertSame(1, DB::table('tm_workflow_instances')->count()); self::assertSame(1, DB::table('tm_active_process_slots')->count());
        self::assertSame($events + 1, DB::table('tm_outbox')->count()); self::assertSame(2, DB::table('tm_command_journal')->count());
    }
    public function test_wip_one_serializes_competing_transitions_using_stage_lock(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') { $this->markTestSkipped('Requires real PostgreSQL process concurrency.'); }
        $r = $this->sales(); $p = $this->pipeline($r['id'], 1); $this->host(); $this->host('lead-2');
        $a = $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $r['id'], 'a', pipelineKey: $p['pipeline_key']);
        $b = $this->instances()->start($this->manager(), 'crm.lead', 'lead-2', $r['id'], 'b', pipelineKey: $p['pipeline_key']);
        $events = DB::table('tm_outbox')->count();
        $results = $this->race([['transition', $a['instance_key'], 'review-a'], ['transition', $b['instance_key'], 'review-b']]);
        self::assertCount(1, array_filter($results, fn ($r) => isset($r['key'])));
        self::assertCount(1, array_filter($results, fn ($r) => ($r['error'] ?? '') === 'capacity_exceeded'));
        self::assertSame(1, DB::table('tm_workflow_instances')->where('active_stage_key', 'review')->count());
        self::assertSame(1, DB::table('tm_workflow_instances')->where('active_stage_key', 'inbox')->count());
        self::assertSame(1, DB::table('tm_instance_transitions')->count()); self::assertSame($events + 1, DB::table('tm_outbox')->count());
        self::assertSame(3, DB::table('tm_stage_visits')->count());
    }
}
