<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Enterprise\PresetService;
use Karnoweb\TaskManager\Tests\PhaseFiveTestCase;

final class TM064Test extends PhaseFiveTestCase
{
    public function test_scenario():void
    {
        $task=$this->todo('master');$series=$this->recurrence()->create($this->manager(),$task['public_key'],1,'gregorian','2026-10-20 09:00:00','UTC',['frequency'=>'daily','after_completion'=>true],'series');$from=new \DateTimeImmutable('2026-10-20');$until=$from->modify('+10 days');
        self::assertSame([],$this->recurrence()->generate($this->manager(),$series['series_key'],1,$from,$until,'before')['created']);$this->service()->mutate($this->manager(),$task['public_key'],1,'complete','complete');
        $result=$this->recurrence()->generate($this->manager(),$series['series_key'],1,$from,$until,'after');self::assertCount(1,$result['created']);self::assertSame([],$this->recurrence()->generate($this->manager(),$series['series_key'],1,$from,$until,'still-pending')['created']);
        $next=$this->service()->get($this->manager(),$result['created'][0]);$this->service()->mutate($this->manager(),$next['public_key'],$next['version'],'complete-next','complete');self::assertCount(1,$this->recurrence()->generate($this->manager(),$series['series_key'],1,$from,$until,'next')['created']);
    }

}
