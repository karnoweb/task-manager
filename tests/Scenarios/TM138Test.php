<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Integration\WebhookSignature;
use Karnoweb\TaskManager\Tests\WebhookTestCase;

final class TM138Test extends WebhookTestCase
{
    public function test_scenario(): void
    {
        $task = $this->todo('PRIVATE_TITLE');
        $endpoint = $this->webhooks()->create($this->manager(), 'https://hooks.example.com/events', 'fixture-secret', ['created'], 'endpoint');
        $event = DB::table('tm_outbox')->where('task_id', $task['id'])->where('action', 'created')->first();
        self::assertSame(1, $this->webhooks()->queue($this->manager(), $event->event_id));
        self::assertSame(0, $this->webhooks()->queue($this->manager(), $event->event_id));
        self::assertSame(1, $this->webhooks()->run($this->webhookWorker())['delivered']);
        self::assertCount(1, $this->network->requests);
        $request = $this->network->requests[0];
        self::assertSame('8.8.8.8', $request['target']->address);
        self::assertStringNotContainsString('PRIVATE_TITLE', $request['body']);
        $headers = [];
        foreach ($request['headers'] as $header) {
            [$key,$value] = explode(': ', $header, 2);
            $headers[$key] = $value;
        }
        $signature = WebhookSignature::sign(str_repeat('s', 32), $headers['X-TaskManager-Timestamp'], $event->event_id, $request['body']);
        self::assertSame($signature, $headers['X-TaskManager-Signature']);
        self::assertTrue($this->webhooks()->verifyIncoming($this->manager(), $endpoint['endpoint_key'], $headers['X-TaskManager-Timestamp'], $event->event_id, $request['body'], $signature));
        self::assertFalse($this->webhooks()->verifyIncoming($this->manager(), $endpoint['endpoint_key'], $headers['X-TaskManager-Timestamp'], $event->event_id, $request['body'], $signature));
        $this->error('webhook_signature_invalid', fn () => $this->webhooks()->verifyIncoming($this->manager(), $endpoint['endpoint_key'], $headers['X-TaskManager-Timestamp'], $event->event_id, $request['body'].' ', $signature));
        $this->clock->advance('+301 seconds');
        $this->error('webhook_signature_invalid', fn () => $this->webhooks()->verifyIncoming($this->manager(), $endpoint['endpoint_key'], $headers['X-TaskManager-Timestamp'], $event->event_id, $request['body'], $signature));
        self::assertStringNotContainsString(str_repeat('s', 32), json_encode(DB::table('tm_command_journal')->get()->all()));
    }
}
