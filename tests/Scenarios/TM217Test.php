<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Integration\WebhookService;
use Karnoweb\TaskManager\Tests\EnterpriseProcessTestCase;

final class TM217Test extends EnterpriseProcessTestCase
{
    public function test_scenario(): void
    {
        [$handoff,$origin,$destination] = $this->delegateFixture(false);
        $this->instances()->transition($this->manager(), $destination['instance_key'], 1, 'accept', 'accept');
        $this->handoffs()->settle($this->manager(), $handoff['handoff_key'], 1, 'settle');
        $event = DB::table('tm_outbox')->where('action', 'handoff_returned')->first();
        $webhooks = $this->app->make(WebhookService::class);
        $webhooks->create($this->manager(), 'https://hooks.example.com/events', 'fixture-secret', ['handoff_returned'], 'endpoint');
        self::assertSame(1, $webhooks->queue($this->manager(), $event->event_id));
        self::assertSame(1, $webhooks->run($this->worker())['delivered']);
        $request = $this->network->requests[0];
        $body = json_decode($request['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('one', $body['scope_key']);
        self::assertSame($handoff['handoff_key'], $body['data']['handoff_key']);
        self::assertStringNotContainsString('PRIVATE-HOST-TEXT', $request['body']);
        self::assertStringContainsString('X-TaskManager-Signature: sha256=', implode('\n', $request['headers']));
    }
}
