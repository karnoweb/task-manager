<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Integration\AutomationService;
use Karnoweb\TaskManager\Tests\PhaseFiveTestCase;

final class TM136Test extends PhaseFiveTestCase
{
    public function test_scenario():void
    {
        $task=$this->todo('loop');$this->automation()->publish($this->manager(),null,0,'created',['max_depth'=>3,'actions'=>[['type'=>'notify']]],'initial');$this->automation()->publish($this->manager(),null,0,'automation_notify',['max_depth'=>3,'actions'=>[['type'=>'notify']]],'loop-rule');
        $event=DB::table('tm_outbox')->where('task_id',$task['id'])->where('action','created')->first();$this->automation()->run($this->worker(),$event->event_id);
        for($i=0;$i<5;++$i){$events=DB::table('tm_outbox')->where('action','automation_notify')->orderBy('id')->get();foreach($events as $item){$this->automation()->run($this->worker(),$item->event_id);}}
        self::assertSame(3,DB::table('tm_outbox')->where('action','automation_notify')->count());self::assertSame(1,DB::table('tm_automation_runs')->where('reason_code','automation_depth_limit')->count());self::assertSame(3,DB::table('tm_automation_budgets')->value('used'));
    }
    private function automation():AutomationService{return $this->app->make(AutomationService::class);}
    private function worker():ExecutionContext{$base=$this->manager();return new ExecutionContext('one','a','automation-worker',true,$base->capabilities);}
}
