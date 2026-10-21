<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Karnoweb\Crm\Services\RecurringTaskService;
use Karnoweb\Crm\Services\TaskService;
use Karnoweb\TaskManager\UI\IntegrationFactory;

final class CrmImportSafetyTest extends PhaseSixTestCase
{
    public function test_real_crm_recurrence_history_import_has_one_series_and_no_duplicate_generation(): void
    {
        [$legacy,$batch,$deal] = $this->migrationTasks();
        $c = $this->manager();
        $crmSeries = $this->app->make(RecurringTaskService::class)->create($deal, Carbon::parse('2026-10-22 09:00:00'), 'daily', Carbon::parse('2026-10-26 09:00:00'), 'UTC', 1, ['title' => 'Recurring CRM']);
        $first = $crmSeries->tasks()->first();
        $this->app->make(TaskService::class)->complete($first, 'done');
        $this->cutover()->importBatch($c, 'crm.tasks', 1, 0, 'import');
        self::assertSame(3, DB::table('tm_tasks')->count());
        self::assertSame(1, DB::table('tm_recurrences')->count());
        self::assertSame(2, DB::table('tm_recurrence_occurrences')->count());
        self::assertTrue($this->cutover()->reconcile($c, 'crm.tasks')['consistent']);
        $this->cutover()->switch($c, 'crm.tasks', 1, 'active', 'activate');
        $this->error('legacy_writer_disabled', fn () => $this->app->make(RecurringTaskService::class)->cancel($crmSeries));
        $series = (array) DB::table('tm_recurrences')->first();
        $none = $this->recurrence()->generate($c, $series['series_key'], 1, new \DateTimeImmutable('2026-10-22Z'), new \DateTimeImmutable('2026-10-27Z'), 'generate-pending');
        self::assertSame([], $none['created']);
        $last = DB::table('tm_recurrence_occurrences')->orderByDesc('scheduled_at')->first();
        $i = DB::table('tm_workflow_instances')->where('task_id', $last->task_id)->where('slot_key', 'main')->first();
        $this->instances()->transition($c, $i->instance_key, $i->version, 'complete-child', 'complete');
        $next = $this->recurrence()->generate($c, $series['series_key'], 1, new \DateTimeImmutable('2026-10-22Z'), new \DateTimeImmutable('2026-10-27Z'), 'generate-next');
        self::assertCount(1, $next['created']);
        self::assertSame('2026-10-24 09:00:00.000000', $this->service()->get($c, $next['created'][0])['due_at']);
        self::assertSame($next, $this->recurrence()->generate($c, $series['series_key'], 1, new \DateTimeImmutable('2026-10-22Z'), new \DateTimeImmutable('2026-10-27Z'), 'generate-next'));
        self::assertSame(4, DB::table('tm_tasks')->count());
        $this->error('rollback_reverse_sync_required', fn () => $this->cutover()->switch($c, 'crm.tasks', 2, 'rolled_back', 'rollback'));
    }

    public function test_unmapped_task_type_rolls_back_the_entire_import_and_its_events(): void
    {
        [$legacy] = $this->migrationTasks();
        $legacy->forceFill(['type' => 'phone'])->save();
        $events = DB::table('tm_outbox')->count();
        $this->error('migration_type_mapping_required', fn () => $this->cutover()->importBatch($this->manager(), 'crm.tasks', 1, 0, 'import'));
        self::assertSame(0, DB::table('tm_tasks')->count());
        self::assertSame(0, DB::table('tm_host_imports')->count());
        self::assertSame($events, DB::table('tm_outbox')->count());
    }

    public function test_invalid_terminal_mapping_is_rejected_before_any_host_or_engine_write(): void
    {
        [$lead,$deal,$stages] = $this->crm();
        $r = $this->sales();
        $p = $this->pipeline($r['id']);
        $this->error('migration_mapping_invalid', fn () => $this->cutover()->configure($this->manager(), 'crm.deals', ['revision_id' => $r['id'], 'pipeline_key' => $p['pipeline_key'], 'stages' => [(string) $stages[3]->id => 'won']], 'wrong-terminal-map'));
        self::assertSame(0, DB::table('tm_host_cutovers')->count());
        self::assertSame($stages[0]->id, $deal->fresh()->pipeline_stage_id);
    }

    public function test_scoped_followup_association_rechecks_both_sides_and_never_leaks_private_crm_content(): void
    {
        [$lead,$deal] = $this->crm();
        $service = $this->app->make(IntegrationFactory::class)->followups();
        $link = $service->create($this->manager(), 'crm.deal', (string) $deal->id, 'Follow-up', 'followup');
        self::assertSame($link, $service->create($this->manager(), 'crm.deal', (string) $deal->id, 'Follow-up', 'followup'));
        self::assertSame(1, DB::table('tm_tasks')->count());
        $this->error('not_found', fn () => $service->tasks($this->manager('2'), 'crm.deal', (string) $deal->id));
        $deal->forceFill(['assigned_to' => 2])->save();
        $this->error('not_found',fn () => $service->tasks($this->manager(),'crm.deal',(string) $deal->id));
        self::assertSame([],$service->tasks($this->manager('2'),'crm.deal',(string) $deal->id));
    }
}
