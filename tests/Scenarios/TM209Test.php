<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\PhaseSixTestCase;
use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\UI\Surface;
use Karnoweb\Kanban\Data\{ActionCommand,BoardQuery};
use Karnoweb\Kanban\Enums\{ActionType,ActionStatus};
use Karnoweb\Calendar\Data\{EventQuery,DateRange};

final class TM209Test extends PhaseSixTestCase
{
    public function test_scenario():void
    {
        [$lead,$deal]=$this->crm();$r=$this->sales();$p=$this->pipeline($r['id']);$i=$this->instances()->start($this->manager(),'crm.deal',(string)$deal->id,$r['id'],'start',pipelineKey:$p['pipeline_key']);$task=$this->service()->create($this->manager(),'Follow-up','task');
        $b=$this->board(new Surface('my.tasks',['tasks','crm.deal'],['crm.deal'=>$p['pipeline_key']]));$page=$b->listCards($this->uiBoard(),new BoardQuery);self::assertCount(2,$page->items);self::assertSame(2,$b->counts($this->uiBoard(),new BoardQuery)->boardTotal);
        $deal->forceFill(['assigned_to'=>2])->save();$page=$b->listCards($this->uiBoard(),new BoardQuery);self::assertCount(1,$page->items);self::assertSame('tasks:'.$task['public_key'],$page->items[0]->id);self::assertNull($b->card($this->uiBoard(),'crm.deal:'.$i['instance_key']));
        $this->error('not_found',fn()=>$this->board(new Surface('my.tasks',['tasks']))->card($this->uiBoard(),'crm.deal:'.$i['instance_key']));
    }
}
