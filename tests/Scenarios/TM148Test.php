<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM148Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $t=$this->service()->create($c,'Original','create'); $first=DB::table('tm_audit_events')->first(); $this->service()->mutate($c,$t['public_key'],1,'title','update',['title'=>'Changed']); $this->service()->mutate($c,$t['public_key'],2,'priority','update',['priority'=>'high']); $events=DB::table('tm_audit_events')->orderBy('id')->get(); self::assertCount(3,$events); self::assertEquals($first,$events[0]); $body=json_decode($events[1]->payload,true); self::assertSame(hash('sha256','Original'),$body['before_redacted']['title_hash']); self::assertSame(hash('sha256','Changed'),$body['after_redacted']['title_hash']); self::assertSame('workflow-test',$events[1]->correlation_id); self::assertSame('a',$events[1]->actor_ref); self::assertSame(3,$this->service()->get($c,$t['public_key'])['version']);
    }
}

