<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Integration\AutomationService;

final class AutomationCoreTest extends PhaseFiveTestCase
{
    private function automation():AutomationService{return $this->app->make(AutomationService::class);}
    private function worker():ExecutionContext{$base=$this->manager();return new ExecutionContext('one','a','automation-worker',true,$base->capabilities);}
    public function test_whitelisted_actions_use_real_guards_audit_and_retry_deduplication():void
    {
        $task=$this->todo('source');$event=DB::table('tm_outbox')->where('task_id',$task['id'])->where('action','created')->first();
        $rule=$this->automation()->publish($this->manager(),null,0,'created',['conditions'=>[['field'=>'priority','operator'=>'equals','value'=>'normal']],'actions'=>[['type'=>'add_label','args'=>['label'=>'follow_up']],['type'=>'create_subtask','args'=>['title'=>'Child']],['type'=>'notify']]],'rule');
        $result=$this->automation()->run($this->worker(),$event->event_id);self::assertSame(['completed','completed','completed'],array_column($result['actions'],'state'));$events=DB::table('tm_outbox')->count();self::assertSame($result,$this->automation()->run($this->worker(),$event->event_id));self::assertSame($events,DB::table('tm_outbox')->count());self::assertSame(2,DB::table('tm_tasks')->count());self::assertSame(1,DB::table('tm_task_label_keys')->count());self::assertSame(1,DB::table('tm_task_closure')->where('ancestor_task_id',$task['id'])->where('depth',1)->count());
        $this->error('automation_action_not_allowed',fn()=>$this->automation()->publish($this->manager(),null,0,'created',['actions'=>[['type'=>'eval','args'=>['php'=>'bad']]]],'bad'));self::assertSame(1,DB::table('tm_automation_rules')->count());
    }
    public function test_recursive_notification_events_stop_at_depth_and_budget_limits():void
    {
        $task=$this->todo('loop');$this->automation()->publish($this->manager(),null,0,'created',['max_depth'=>3,'actions'=>[['type'=>'notify']]],'initial');$this->automation()->publish($this->manager(),null,0,'automation_notify',['max_depth'=>3,'actions'=>[['type'=>'notify']]],'loop-rule');
        $event=DB::table('tm_outbox')->where('task_id',$task['id'])->where('action','created')->first();$this->automation()->run($this->worker(),$event->event_id);
        for($i=0;$i<5;++$i){$events=DB::table('tm_outbox')->where('action','automation_notify')->orderBy('id')->get();foreach($events as $item){$this->automation()->run($this->worker(),$item->event_id);}}
        self::assertSame(3,DB::table('tm_outbox')->where('action','automation_notify')->count());self::assertSame(1,DB::table('tm_automation_runs')->where('reason_code','automation_depth_limit')->count());self::assertSame(3,DB::table('tm_automation_budgets')->value('used'));
    }
    public function test_unknown_or_revoked_source_events_do_not_grant_automation_privileges():void
    {
        $task=$this->service()->create($this->manager('b'),'Hidden','hidden');$event=DB::table('tm_outbox')->where('task_id',$task['id'])->first();$this->automation()->publish($this->manager(),null,0,'created',['actions'=>[['type'=>'notify']]],'rule');
        $this->error('not_found',fn()=>$this->automation()->run($this->worker(),$event->event_id));self::assertSame(0,DB::table('tm_automation_runs')->count());
    }
}
