<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\PhaseSixTestCase;
use Illuminate\Support\Facades\DB;
use Karnoweb\Kanban\Data\{BoardQuery, ActionCommand};
use Karnoweb\Kanban\Enums\{ActionType, ActionStatus};
use Karnoweb\Calendar\Data\{EventQuery, DateRange, EventReference};
use Karnoweb\TaskManager\UI\Surface;

final class TM084Test extends PhaseSixTestCase
{
    public function test_scenario(): void
    {
        $t=$this->service()->create($this->manager(),'Move','create');$id='tasks:'.$t['public_key'];$board=$this->board();$ui=$this->uiBoard();
        $cmd=new ActionCommand('move-once',ActionType::MoveCard,['cardId'=>$id,'toStageId'=>'tasks:done'],(string)$t['version']);
        self::assertSame(ActionStatus::SUCCESS,$board->execute($cmd,$ui)->status);self::assertSame(ActionStatus::SUCCESS,$board->execute($cmd,$ui)->status);
        self::assertSame('done',$this->service()->get($this->manager(),$t['public_key'])['state']);self::assertSame(1,DB::table('tm_instance_transitions')->count());
        self::assertSame(ActionStatus::CONFLICT,$board->execute(new ActionCommand('stale-move',ActionType::MoveCard,['cardId'=>$id,'toStageId'=>'tasks:pending'],(string)$t['version']),$ui)->status);
        self::assertNull($board->card($this->uiBoard('2'),$id));self::assertNull($board->operationStatus($this->uiBoard('2'),'move-once'));
    }
}
