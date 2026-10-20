<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM014Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        [$r,$p,$t]=$this->projectTask(); $p=$this->projects()->change($c,$p['project_key'],1,'member','member',['actor'=>'b','role'=>'member']); self::assertSame(1,$this->service()->count($this->manager('b'))); $edited=$this->service()->mutate($this->manager('b'),$t['public_key'],2,'edit','update',['priority'=>'high']); self::assertSame('high',$edited['priority']); $this->error('not_found',fn()=>$this->projects()->change($this->manager('b'),$p['project_key'],2,'illegal','archive',[]));
    }
}

