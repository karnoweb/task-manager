<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\SlaTestCase;

final class TM202Test extends SlaTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $d=$this->definition(); $d['states']['lost']=['terminal'=>'failure']; $d['transitions']['expire']=['from'=>'new','to'=>'lost','reason_codes'=>['sla_expired']]; $r=$this->workflows()->publish($c,'sales',0,'workflow',$d); $p=$this->pipelines()->create($c,'Sales',$r['id'],['inbox'=>['state'=>'new'],'lost'=>['state'=>'lost']],'pipeline'); $this->policy()->publishSla($c,$p['pipeline_key'],'inbox',1,'policy',['duration_seconds'=>60,'breach_transition'=>'expire','breach_reason'=>'sla_expired']); $this->host(); $i=$this->instances()->start($c,'crm.lead','lead-1',$r['id'],'start',pipelineKey:$p['pipeline_key']); $v=$this->instances()->visits($c,$i['instance_key'])[0]; $worker=new \Karnoweb\TaskManager\Context\ExecutionContext('one','a','worker',true,['workflow.view','sla.run']); $this->clock->advance('+60 seconds'); self::assertSame(1,$this->sla()->run($worker)['events']); self::assertSame('manual_review',DB::table('tm_sla_events')->value('action_state')); $current=$this->sla()->get($c,$v['id']); $this->sla()->reset($c,$v['id'],$current['sla_version'],'reset'); DB::table('host_subjects')->update(['veto'=>true]); $this->clock->advance('+60 seconds'); self::assertSame(1,$this->sla()->run($c)['events']); self::assertSame(2,DB::table('tm_sla_events')->where('action_state','manual_review')->count()); self::assertSame('new',$this->instances()->get($c,$i['instance_key'])['current_state_key']); self::assertSame(0,DB::table('tm_instance_transitions')->count()); self::assertSame(0,DB::table('host_subjects')->value('guard_touches'));
    }
}

