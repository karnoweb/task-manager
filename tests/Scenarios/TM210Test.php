<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\PhaseSixTestCase;
use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\UI\Surface;
use Karnoweb\Kanban\Data\{ActionCommand,BoardQuery};
use Karnoweb\Kanban\Enums\{ActionType,ActionStatus};
use Karnoweb\Calendar\Data\{EventQuery,DateRange};

final class TM210Test extends PhaseSixTestCase
{
    public function test_scenario():void
    {
        [$lead,$deal]=$this->crm();$r=$this->sales();$p=$this->pipeline($r['id']);$i=$this->instances()->start($this->manager(),'crm.deal',(string)$deal->id,$r['id'],'start',pipelineKey:$p['pipeline_key']);
        $visit=$this->instances()->visits($this->manager(),$i['instance_key'])[0];DB::table('tm_stage_visits')->where('id',$visit['id'])->update(['deadline_at'=>'2026-10-22 08:00:00']);
        $t=$this->service()->create($this->manager(),'Recurring','task');$t=$this->dates()->change($this->manager(),$t['public_key'],$t['version'],null,null,'2026-10-22 09:00:00','UTC',false,'reject','due');$s=$this->recurrence()->create($this->manager(),$t['public_key'],$t['version'],'gregorian','2026-10-22 09:00:00','UTC','FREQ=DAILY;COUNT=2','series');
        $q=new EventQuery(DateRange::ofLocalDays('2026-10-22','2026-10-24','UTC'));$cal=$this->calendar();$page=$cal->fetch($q,$this->uiCalendar());self::assertCount(3,$page->events);
        foreach($page->events as $event){self::assertTrue($event->isReadOnly());$found=$cal->find($event->reference,$this->uiCalendar());self::assertNotNull($found);self::assertTrue($found->reference->equals($event->reference));}
        self::assertSame(1,DB::table('tm_tasks')->count());
    }
}
