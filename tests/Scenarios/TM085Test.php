<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\PhaseSixTestCase;
use Illuminate\Support\Facades\{Schema,DB};
use Illuminate\Database\Schema\Blueprint;
use Karnoweb\Kanban\Data\{BoardQuery,ActionCommand};
use Karnoweb\Kanban\Enums\{ActionType,ActionStatus};
use Karnoweb\TaskManager\Tests\Support\{PhaseSixOrder,PhaseSixDeal,PhaseSixBoardFactory};

final class TM085Test extends PhaseSixTestCase
{
    public function test_scenario(): void
    {
        $t=$this->service()->create($this->manager(),'ISO move','create');$t=$this->dates()->change($this->manager(),$t['public_key'],$t['version'],'2026-10-22 09:00:00','2026-10-22 10:00:00',null,'UTC',false,'reject','span');
        $cmd=new \Karnoweb\Calendar\Data\ActionCommand('iso-move-1',\Karnoweb\Calendar\Enums\CalendarAction::Move,new \Karnoweb\Calendar\Data\EventReference('tm.schedule',$t['public_key']),['mode'=>'time','start'=>'2026-10-23T12:30:00+03:30','end'=>'2026-10-23T13:30:00+03:30','allDay'=>false,'timezone'=>'Asia/Tehran'],(string)$t['version']);
        self::assertTrue($this->calendar()->execute($cmd,$this->uiCalendar())->isSuccess());self::assertSame('2026-10-23 09:00:00.000000',$this->service()->get($this->manager(),$t['public_key'])['starts_at']);
    }
}
