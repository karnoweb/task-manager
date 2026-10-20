<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM160Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $clock=new class implements \Karnoweb\TaskManager\Contracts\Clock{public \DateTimeImmutable $time;public function __construct(){$this->time=new \DateTimeImmutable('2026-10-20T00:00:00Z');}public function now():\DateTimeImmutable{return $this->time;}}; $this->app->instance(\Karnoweb\TaskManager\Contracts\Clock::class,$clock); $r=$this->sales(); $p=$this->pipeline($r['id']); $this->host(); $i=$this->instances()->start($c,'crm.lead','lead-1',$r['id'],'start',pipelineKey:$p['pipeline_key']); $clock->time=$clock->time->modify('+1 hour'); $this->instances()->transition($c,$i['instance_key'],1,'review','review'); $clock->time=$clock->time->modify('+30 minutes'); $this->instances()->transition($c,$i['instance_key'],2,'return','return'); $visits=$this->instances()->visits($c,$i['instance_key']); self::assertCount(3,$visits); self::assertSame('2026-10-20 00:00:00',$visits[0]['entered_at']); self::assertSame('2026-10-20 01:00:00',$visits[0]['exited_at']); self::assertSame('2026-10-20 01:30:00',$visits[2]['entered_at']); self::assertNull($visits[2]['exited_at']); self::assertSame(2,DB::table('tm_instance_transitions')->count());
    }
}

