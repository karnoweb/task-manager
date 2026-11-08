<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;

final class SlaNativeLifecycleTest extends SlaTestCase
{
    private function nativePipeline(): array
    {
        $r = $this->sales();
        $p = $this->pipeline($r['id']);
        $this->policy()->publishSla($this->manager(), $p['pipeline_key'], 'inbox', 1, 'sla-policy', ['duration_seconds' => 60]);
        $task = $this->service()->create($this->manager(), 'Native source', 'task');
        $instance = $this->instances()->start($this->manager(), 'task', $task['public_key'], $r['id'], 'start', 'sales', $p['pipeline_key']);

        return [$task, $instance];
    }

    public function test_archive_and_delete_cannot_hide_an_active_auxiliary_process_or_clock(): void
    {
        [$task, $instance] = $this->nativePipeline();
        foreach (['archive', 'delete'] as $action) {
            try {
                $this->service()->mutate($this->manager(), $task['public_key'], 2, $action, $action);
                self::fail();
            } catch (DomainError $error) {
                self::assertSame('active_process_blocks_archive', $error->errorCode);
            }
        }
        self::assertNull(DB::table('tm_tasks')->where('public_key', $task['public_key'])->value('archived_at'));
        self::assertNull(DB::table('tm_tasks')->where('public_key', $task['public_key'])->value('deleted_at'));
        $this->instances()->transition($this->manager(), $instance['instance_key'], 1, 'review', 'review');
        $this->instances()->transition($this->manager(), $instance['instance_key'], 2, 'approve', 'approve', ['reason_code' => 'approved']);
        $current = $this->service()->get($this->manager(), $task['public_key']);
        $archived = $this->service()->mutate($this->manager(), $task['public_key'], $current['version'], 'archive-after-process', 'archive');
        self::assertNotNull($archived['archived_at']);
        $this->clock->advance('+1 hour');
        self::assertSame(0, $this->sla()->run($this->manager())['events']);
    }

    public function test_watchdog_ignores_a_native_source_archived_by_a_legacy_writer(): void
    {
        [$task] = $this->nativePipeline();
        DB::table('tm_tasks')->where('public_key', $task['public_key'])->update(['archived_at' => $this->clock->now()->format('Y-m-d H:i:s')]);
        $this->clock->advance('+120 seconds');
        self::assertSame(['events' => 0, 'skipped' => 0], $this->sla()->run($this->manager()));
        self::assertSame(0, DB::table('tm_sla_events')->count());
    }
}
