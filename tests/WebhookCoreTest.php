<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Integration\WebhookSignature;
use Karnoweb\TaskManager\Integration\WebhookUrlPolicy;

final class WebhookCoreTest extends WebhookTestCase
{
    public function test_signed_redacted_webhook_and_receipt_replay_checks_bind_scope_time_and_body(): void
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

    public function test_private_metadata_mixed_dns_rebinding_and_redirects_never_escape_policy(): void
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

    public function test_bounded_backoff_dead_letter_and_versioned_manual_retry_are_observable(): void
    {
        $task = $this->todo('Task');
        $this->webhooks()->create($this->manager(), 'https://hooks.example.com', 'fixture-secret', ['created'], 'endpoint');
        $event = DB::table('tm_outbox')->where('task_id', $task['id'])->first();
        $this->webhooks()->queue($this->manager(), $event->event_id);
        $this->network->responses = array_fill(0, 5, ['status' => 503]);
        foreach ([0, 5, 30, 120, 600] as $delay) {
            $this->clock->advance('+'.$delay.' seconds');
            $result = $this->webhooks()->run($this->webhookWorker());
        }
        self::assertSame(1, $result['dead']);
        self::assertCount(5, $this->network->requests);
        self::assertSame(0, $this->webhooks()->run($this->webhookWorker())['delivered']);
        $delivery = $this->webhooks()->deliveries($this->manager())[0];
        self::assertSame(5, $delivery['attempts']);
        $this->webhooks()->retry($this->manager(), $delivery['id'], $delivery['version'], 'manual-retry');
        self::assertSame(1, $this->webhooks()->run($this->webhookWorker())['delivered']);
    }

    public function test_permission_revoked_during_dns_resolution_is_rechecked_before_transport(): void
    {
        $task = $this->service()->create($this->manager('b'), 'Private owner', 'private-owner');
        $task = $this->service()->share($this->manager('b'), $task['public_key'], 1, 'grant', 'a');
        $this->webhooks()->create($this->manager(), 'https://hooks.example.com', 'fixture-secret', ['created'], 'endpoint');
        $event = DB::table('tm_outbox')->where('task_id', $task['id'])->where('action', 'created')->first();
        $this->webhooks()->queue($this->manager(), $event->event_id);
        $this->network->onResolve = function () use ($task) {
            $this->service()->share($this->manager('b'), $task['public_key'], $task['version'], 'revoke', 'a', revoke: true);
        };
        self::assertSame(1, $this->webhooks()->run($this->webhookWorker())['cancelled']);
        self::assertCount(0, $this->network->requests);
    }
}
