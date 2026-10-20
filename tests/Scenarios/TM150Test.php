<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM150Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $t=$this->service()->create($c,'Task','task'); $resources=$this->app->make(\Karnoweb\TaskManager\TaskResourceService::class); $comment=$resources->create($c,$t['public_key'],'comment',['body'=>'PRIVATE-ONE'],'comment'); $comment=$resources->change($c,'comment',$comment['public_key'],1,'edit-comment',['body'=>'PRIVATE-TWO']); self::assertSame('PRIVATE-TWO',$comment['body']); $resources->change($c,'comment',$comment['public_key'],2,'delete-comment',null); $file=$resources->create($c,$t['public_key'],'attachment',['file_ref'=>'PRIVATE-FILE','media_type'=>'text/plain','size'=>12],'file'); $file=$resources->change($c,'attachment',$file['public_key'],1,'edit-file',['file_ref'=>'PRIVATE-FILE-NEW','media_type'=>'text/plain','size'=>20]); $resources->change($c,'attachment',$file['public_key'],2,'delete-file',null); $events=DB::table('tm_audit_events')->get(); self::assertCount(7,$events); $raw=json_encode($events); foreach(['PRIVATE-ONE','PRIVATE-TWO','PRIVATE-FILE']as $secret){self::assertStringNotContainsString($secret,$raw);} self::assertSame(7,DB::table('tm_outbox')->count());
    }
}

