<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Karnoweb\Crm\Models\DealStageHistory;
use Karnoweb\Crm\Services\DealService;
use Karnoweb\Crm\Services\TaskService;
use Karnoweb\TaskManager\UI\IntegrationFactory;

final class CrmCutoverTest extends PhaseSixTestCase
{
    public function test_real_legacy_task_shadow_import_reconciliation_cutover_and_rollback(): void
    {
        [$legacy,$b,$deal] = $this->migrationTasks();
        $svc = $this->cutover();
        $c = $this->manager();
        $before = $legacy->getAttributes();
        self::assertSame(1, $svc->inventory($c, 'crm.tasks')['rows']);
        $r = $svc->importBatch($c, 'crm.tasks', $b['version'], 0, 'import');
        self::assertCount(1, $r['import_ids']);
        $again = $svc->importBatch($c, 'crm.tasks', $b['version'], 0, 'import-again');
        self::assertSame($r['import_ids'], $again['import_ids']);
        self::assertSame(1, DB::table('tm_tasks')->count());
        $shadow = $svc->shadow($c, 'crm.tasks', (string) $legacy->id);
        self::assertSame('legacy', $shadow['reader']);
        self::assertSame('new', $shadow['engine']['instance']['current_state_key']);
        $key = $shadow['engine']['task']['public_key'];
        $task = $this->service()->get($c, $key);
        $this->error('not_found', fn () => $this->service()->mutate($c, $key, $task['version'], 'shadow-edit', 'update', ['title' => 'NO']));
        self::assertTrue($svc->reconcile($c, 'crm.tasks')['consistent']);
        $b = $svc->switch($c, 'crm.tasks', $b['version'], 'active', 'cutover');
        self::assertSame('engine', $svc->shadow($c, 'crm.tasks', (string) $legacy->id)['reader']);
        $this->error('legacy_writer_disabled', fn () => $this->app->make(TaskService::class)->reschedule($legacy, Carbon::parse('2026-10-25')));
        $b = $svc->switch($c, 'crm.tasks', $b['version'], 'rolled_back', 'rollback');
        self::assertSame('rolled_back', $b['mode']);
        self::assertSame($before, $legacy->fresh()->getAttributes());
        $this->app->make(TaskService::class)->reschedule($legacy, Carbon::parse('2026-10-25'));
        self::assertSame('2026-10-25', $legacy->fresh()->due_at->format('Y-m-d'));
        $this->error('not_found', fn () => $this->dates()->change($c, $key, $task['version'], null, null, '2026-10-26 00:00:00', 'UTC', false, 'reject', 'rolled-back-edit'));
    }

    public function test_source_drift_blocks_cutover_and_engine_changes_block_lossy_rollback(): void
    {
        [$legacy,$b] = $this->migrationTasks();
        $svc = $this->cutover();
        $c = $this->manager();
        $svc->importBatch($c, 'crm.tasks', 1, 0, 'import');
        $legacy->forceFill(['title' => 'Changed'])->save();
        self::assertFalse($svc->reconcile($c, 'crm.tasks')['consistent']);
        $this->error('reconciliation_required', fn () => $svc->switch($c, 'crm.tasks', 1, 'active', 'bad-cutover'));
        $legacy->forceFill(['title' => 'Legacy follow-up'])->save();
        $svc->switch($c, 'crm.tasks', 1, 'active', 'cutover');
        $t = $svc->shadow($c, 'crm.tasks', (string) $legacy->id)['engine']['task'];
        $this->service()->mutate($c, $t['public_key'], $t['version'], 'edit-engine', 'update', ['title' => 'Engine update']);
        $this->error('rollback_reverse_sync_required', fn () => $svc->switch($c, 'crm.tasks', 2, 'rolled_back', 'bad-rollback'));
        self::assertSame('active', $svc->get($c, 'crm.tasks')['mode']);
        self::assertSame('Legacy follow-up', $legacy->fresh()->title);
    }

    public function test_followup_task_cannot_mutate_real_crm_deal_status(): void
    {
        [$lead,$deal] = $this->crm();
        $before = $deal->getAttributes();
        $association = $this->app->make(IntegrationFactory::class)->followups()->create($this->manager(), 'crm.deal', (string) $deal->id, 'Follow-up for deal '.$deal->id, 'task');
        $t = $this->service()->get($this->manager(), $association['task_key']);
        self::assertCount(1, $this->app->make(IntegrationFactory::class)->followups()->tasks($this->manager(), 'crm.deal', (string) $deal->id));
        $this->service()->mutate($this->manager(), $t['public_key'], $t['version'], 'finish', 'complete');
        self::assertSame($before, $deal->fresh()->getAttributes());
        self::assertSame(0, DealStageHistory::query()->count());
    }

    public function test_real_deal_pipeline_history_alias_import_and_writer_switch(): void
    {
        [$lead,$deal,$stages] = $this->crm();
        $deal = $this->app->make(DealService::class)->moveStage($deal, $stages[1], 1, 'Review');
        $def = $this->definition();
        $def['states']['lost'] = ['terminal' => 'failure'];
        $def['transitions']['lose'] = ['from' => 'new', 'to' => 'lost', 'reason_required' => true, 'reason_codes' => ['lost']];
        $r = $this->workflows()->publish($this->manager(), 'sales', 0, 'workflow', $def);
        $p = $this->pipelines()->create($this->manager(), 'Sales', $r['id'], ['inbox' => ['state' => 'new'], 'review' => ['state' => 'review'], 'won' => ['state' => 'won'], 'lost' => ['state' => 'lost']], 'pipeline-Sales');
        $svc = $this->cutover();
        $c = $this->manager();
        $b = $svc->configure($c, 'crm.deals', ['revision_id' => $r['id'], 'pipeline_key' => $p['pipeline_key'], 'stages' => [(string) $stages[0]->id => 'inbox', (string) $stages[1]->id => 'review', (string) $stages[2]->id => 'won', (string) $stages[3]->id => 'lost']], 'configure-deals');
        $result = $svc->importBatch($c, 'crm.deals', 1, 0, 'import-deals');
        self::assertCount(1, $result['import_ids']);
        $shadow = $svc->shadow($c, 'crm.deals', (string) $deal->id);
        $i = $shadow['engine'];
        self::assertSame('review', $i['current_state_key']);
        self::assertSame(1, DB::table('tm_instance_transitions')->count());
        self::assertSame(1, DB::table('tm_workflow_instances')->count());
        $svc->switch($c, 'crm.deals', 1, 'active', 'activate-deals');
        $this->error('legacy_writer_disabled', fn () => $this->app->make(DealService::class)->moveStage($deal,$stages[0],1));
        $current = $this->instances()->get($c,$i['instance_key']);
        $this->instances()->transition($c,$i['instance_key'],$current['version'],'approve','approve',['reason_code' => 'approved']);
        self::assertSame('won',$this->instances()->get($c,$i['instance_key'])['current_state_key']);
        self::assertSame($stages[1]->id,$deal->fresh()->pipeline_stage_id);
    }
}
