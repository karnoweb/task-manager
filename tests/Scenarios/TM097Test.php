<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM097Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        [$r,$p,$t]=$this->projectTask(); $new=$this->workflows()->publish($c,'other',0,'other',['initial'=>'open','states'=>['open'=>['terminal'=>'none']],'transitions'=>[]]); $this->projects()->change($c,$p['project_key'],1,'mapping','type_workflow',['type'=>'task','revision'=>$new['id']]); self::assertSame($r['id'],DB::table('tm_workflow_instances')->where('task_id',$t['id'])->value('workflow_revision_id')); self::assertSame('new',$this->service()->get($c,$t['public_key'])['state']); $next=$this->service()->create($c,'Next','next'); self::assertSame($new['id'],$this->projects()->previewTaskMove($c,$next['public_key'],$p['project_key'],'task',['pending'=>'open'])['revision_id']);
    }
}

