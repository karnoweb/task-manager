<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Integration\WebhookUrlPolicy;
use Karnoweb\TaskManager\Integration\WebhookSignature;
use Karnoweb\TaskManager\Tests\WebhookTestCase;

final class TM140Test extends WebhookTestCase
{
    public function test_scenario():void
    {
        $task=$this->todo('Task');$this->webhooks()->create($this->manager(),'https://hooks.example.com','fixture-secret',['created'],'endpoint');$event=DB::table('tm_outbox')->where('task_id',$task['id'])->first();$this->webhooks()->queue($this->manager(),$event->event_id);$this->network->responses=array_fill(0,5,['status'=>503]);
        foreach([0,5,30,120,600] as $delay){$this->clock->advance('+'.$delay.' seconds');$result=$this->webhooks()->run($this->webhookWorker());}
        self::assertSame(1,$result['dead']);self::assertCount(5,$this->network->requests);self::assertSame(0,$this->webhooks()->run($this->webhookWorker())['delivered']);$delivery=$this->webhooks()->deliveries($this->manager())[0];self::assertSame(5,$delivery['attempts']);$this->webhooks()->retry($this->manager(),$delivery['id'],$delivery['version'],'manual-retry');self::assertSame(1,$this->webhooks()->run($this->webhookWorker())['delivered']);
    }

}
