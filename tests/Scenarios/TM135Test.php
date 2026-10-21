<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Integration\AutomationService;
use Karnoweb\TaskManager\Tests\PhaseFiveTestCase;

final class TM135Test extends PhaseFiveTestCase
{
    public function test_scenario(): void
    {
        $task = $this->todo('source');
        $event = DB::table('tm_outbox')->where('task_id', $task['id'])->where('action', 'created')->first();
        $rule = $this->automation()->publish($this->manager(), null, 0, 'created', ['conditions' => [['field' => 'priority', 'operator' => 'equals', 'value' => 'normal']], 'actions' => [['type' => 'add_label', 'args' => ['label' => 'follow_up']], ['type' => 'create_subtask', 'args' => ['title' => 'Child']], ['type' => 'notify']]], 'rule');
        $result = $this->automation()->run($this->worker(), $event->event_id);
        self::assertSame(['completed', 'completed', 'completed'], array_column($result['actions'], 'state'));
        $events = DB::table('tm_outbox')->count();
        self::assertSame($result, $this->automation()->run($this->worker(), $event->event_id));
        self::assertSame($events, DB::table('tm_outbox')->count());
        self::assertSame(2, DB::table('tm_tasks')->count());
        self::assertSame(1, DB::table('tm_task_label_keys')->count());
        self::assertSame(1, DB::table('tm_task_closure')->where('ancestor_task_id', $task['id'])->where('depth', 1)->count());
        $this->error('automation_action_not_allowed', fn () => $this->automation()->publish($this->manager(), null, 0, 'created', ['actions' => [['type' => 'eval', 'args' => ['php' => 'bad']]]], 'bad'));
        self::assertSame(1, DB::table('tm_automation_rules')->count());
    }

    private function automation(): AutomationService
    {
        return $this->app->make(AutomationService::class);
    }

    private function worker(): ExecutionContext
    {
        $base = $this->manager();

        return new ExecutionContext('one','a','automation-worker',true,$base->capabilities);
    }
}
