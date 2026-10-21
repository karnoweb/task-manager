<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Integration\WebhookUrlPolicy;
use Karnoweb\TaskManager\Tests\WebhookTestCase;

final class TM139Test extends WebhookTestCase
{
    public function test_scenario(): void
    {
        foreach (['127.0.0.1', '169.254.169.254', '10.1.2.3', '100.64.0.1', '::1', '::ffff:127.0.0.1', 'fe80::1', '2001:db8::1', '2002:7f00:1::1'] as $ip) {
            self::assertFalse(WebhookUrlPolicy::publicAddress($ip));
        }
        $this->network->addresses = ['8.8.8.8', '10.0.0.1'];
        $this->error('webhook_private_address', fn () => $this->webhooks()->create($this->manager(), 'https://hooks.example.com', 'fixture-secret', ['created'], 'bad'));
        $this->network->addresses = ['8.8.8.8'];
        $endpoint = $this->webhooks()->create($this->manager(), 'https://hooks.example.com', 'fixture-secret', ['created'], 'endpoint');
        $task = $this->todo('Task');
        $event = DB::table('tm_outbox')->where('task_id', $task['id'])->first();
        $this->webhooks()->queue($this->manager(), $event->event_id);
        $this->network->addresses = ['127.0.0.1'];
        self::assertSame(1, $this->webhooks()->run($this->webhookWorker())['retry']);
        self::assertCount(0, $this->network->requests);
        $this->clock->advance('+5 seconds');
        $this->network->addresses = ['8.8.8.8'];
        $this->network->responses = [['status' => 302, 'redirect' => true]];
        self::assertSame(1, $this->webhooks()->run($this->webhookWorker())['retry']);
        self::assertSame('webhook_redirect_blocked', $this->webhooks()->deliveries($this->manager())[0]['reason_code']);
    }
}
