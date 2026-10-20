<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM022Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        [$r,$p,$t]=$this->projectTask(); $this->projects()->change($c,$p['project_key'],1,'join','member',['actor'=>'b','role'=>'member']); self::assertSame(1,$this->service()->count($this->manager('b'))); $this->projects()->change($c,$p['project_key'],2,'leave','member',['actor'=>'b','role'=>null]); self::assertSame(0,$this->service()->count($this->manager('b'))); $this->error('not_found',fn()=>$this->service()->get($this->manager('b'),$t['public_key'])); self::assertSame([],$this->service()->facets($this->manager('b')));
    }
}

