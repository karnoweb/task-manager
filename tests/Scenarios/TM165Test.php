<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM165Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $r=$this->sales(); $p=$this->pipeline($r['id']); $q=$this->pipeline($r['id'],name:'Contracts'); $this->host(); $i=$this->instances()->start($c,'crm.lead','lead-1',$r['id'],'start',pipelineKey:$p['pipeline_key']); $this->error('state_mapping_required',fn()=>$this->instances()->previewMove($c,$i['instance_key'],$q['pipeline_key'],[])); $preview=$this->instances()->previewMove($c,$i['instance_key'],$q['pipeline_key'],['new'=>'review']); self::assertSame($p['id'],$this->instances()->get($c,$i['instance_key'])['pipeline_id']); $moved=$this->instances()->moveToPipeline($c,$preview,'move'); self::assertSame('lead-1',$moved['subject_id']); self::assertSame($i['instance_key'],$moved['instance_key']); self::assertSame($q['id'],$moved['pipeline_id']); $visits=$this->instances()->visits($c,$i['instance_key']); self::assertCount(2,$visits); self::assertNotNull($visits[0]['exited_at']); self::assertNull($visits[1]['exited_at']);
    }
}

