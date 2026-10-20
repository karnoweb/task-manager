<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM100Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        [$r,$p,$t]=$this->projectTask(); $q=$this->projects()->create($c,'NEXT','Next',$r['id'],'next'); $preview=$this->projects()->previewTaskMove($c,$t['public_key'],$q['project_key'],'task',['new'=>'new']); $moved=$this->projects()->moveTask($c,$preview,'move'); self::assertSame('NEXT-1',$moved['display_key']); self::assertSame($t['public_key'],$this->service()->resolveKey($c,'APP-1')['public_key']); self::assertSame($t['public_key'],$this->service()->resolveKey($c,'TM-1')['public_key']); $this->error('not_found',fn()=>$this->service()->resolveKey($this->manager('b'),'APP-1'));
    }
}

