<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\Crm\Services\DealService;
use Karnoweb\TaskManager\Tests\PhaseSixTestCase;

final class TM213Test extends PhaseSixTestCase
{
    public function test_scenario(): void
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
        $this->error('legacy_writer_disabled', fn () => $this->app->make(DealService::class)->moveStage($deal, $stages[0], 1));
        $current = $this->instances()->get($c, $i['instance_key']);
        $this->instances()->transition($c, $i['instance_key'], $current['version'], 'approve', 'approve', ['reason_code' => 'approved']);
        self::assertSame('won', $this->instances()->get($c, $i['instance_key'])['current_state_key']);
        self::assertSame($stages[1]->id, $deal->fresh()->pipeline_stage_id);
    }
}
