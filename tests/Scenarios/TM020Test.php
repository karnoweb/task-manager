<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM020Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        [$r,$p,$t]=$this->projectTask(); $d=['initial'=>'open','states'=>['open'=>['terminal'=>'none']],'transitions'=>[]]; $new=$this->workflows()->publish($c,'sales',1,'publish2',$d); $this->error('state_mapping_required',fn()=>$this->projects()->previewWorkflowMigration($c,$p['project_key'],$new['id'],[])); self::assertSame('new',$this->service()->get($c,$t['public_key'])['state']); $preview=$this->projects()->previewWorkflowMigration($c,$p['project_key'],$new['id'],['new'=>'open']); $this->projects()->migrateWorkflow($c,$preview,'migrate'); self::assertSame('open',$this->service()->get($c,$t['public_key'])['state']); self::assertNotNull(DB::table('tm_workflow_definitions')->where('id',$r['id'])->first());
    }
}

