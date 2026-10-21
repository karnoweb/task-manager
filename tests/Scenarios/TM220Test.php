<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\PhaseSixTestCase;
use Illuminate\Support\Facades\DB;

final class TM220Test extends PhaseSixTestCase
{
    public function test_scenario(): void
    {
        [$legacy,$b,$deal]=$this->migrationTasks();$svc=$this->cutover();$c=$this->manager();$before=$legacy->getAttributes();
        self::assertSame(1,$svc->inventory($c,'crm.tasks')['rows']);
        $r=$svc->importBatch($c,'crm.tasks',$b['version'],0,'import');self::assertCount(1,$r['import_ids']);
        $again=$svc->importBatch($c,'crm.tasks',$b['version'],0,'import-again');self::assertSame($r['import_ids'],$again['import_ids']);self::assertSame(1,DB::table('tm_tasks')->count());
        $shadow=$svc->shadow($c,'crm.tasks',(string)$legacy->id);self::assertSame('legacy',$shadow['reader']);self::assertSame('new',$shadow['engine']['instance']['current_state_key']);
        $key=$shadow['engine']['task']['public_key'];$task=$this->service()->get($c,$key);$this->error('not_found',fn()=>$this->service()->mutate($c,$key,$task['version'],'shadow-edit','update',['title'=>'NO']));
        self::assertTrue($svc->reconcile($c,'crm.tasks')['consistent']);$b=$svc->switch($c,'crm.tasks',$b['version'],'active','cutover');
        self::assertSame('engine',$svc->shadow($c,'crm.tasks',(string)$legacy->id)['reader']);
        $this->error('legacy_writer_disabled',fn()=>$this->app->make(\Karnoweb\Crm\Services\TaskService::class)->reschedule($legacy,\Illuminate\Support\Carbon::parse('2026-10-25')));
        $b=$svc->switch($c,'crm.tasks',$b['version'],'rolled_back','rollback');self::assertSame('rolled_back',$b['mode']);self::assertSame($before,$legacy->fresh()->getAttributes());
        $this->app->make(\Karnoweb\Crm\Services\TaskService::class)->reschedule($legacy,\Illuminate\Support\Carbon::parse('2026-10-25'));self::assertSame('2026-10-25',$legacy->fresh()->due_at->format('Y-m-d'));
        $this->error('not_found',fn()=>$this->dates()->change($c,$key,$task['version'],null,null,'2026-10-26 00:00:00','UTC',false,'reject','rolled-back-edit'));
    }
}
