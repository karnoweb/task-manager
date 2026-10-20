<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM019Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        [$r,$p,$t]=$this->projectTask(); $d=$this->definition(); $d['states']['open']=['terminal'=>'none']; $d['initial']='open'; $d['transitions']['begin']=['from'=>'open','to'=>'review']; $new=$this->workflows()->publish($c,'sales',1,'publish2',$d); $preview=$this->projects()->previewWorkflowMigration($c,$p['project_key'],$new['id'],['new'=>'open']); self::assertSame('new',$this->service()->get($c,$t['public_key'])['state']); $this->projects()->migrateWorkflow($c,$preview,'migrate'); self::assertSame('open',$this->service()->get($c,$t['public_key'])['state']); self::assertSame($new['id'],DB::table('tm_workflow_instances')->value('workflow_revision_id'));
    }
}

