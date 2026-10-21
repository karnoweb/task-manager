<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Integration\ConsumerRegistry;
use Karnoweb\TaskManager\Integration\EventPump;

final class EventDeliveryTest extends PhaseFiveTestCase
{
    private function worker(): ExecutionContext
    {
        $base = $this->manager();

        return new ExecutionContext('one', 'a', 'event-worker', true, $base->capabilities);
    }

    public function test_durable_subscription_retry_and_replay_do_not_duplicate_successful_consumer_effects(): void
    {
        $attempts = 0;
        $received = [];
        $registry = $this->app->make(ConsumerRegistry::class);
        $registry->register('fixture', function ($context, $event) use (&$attempts, &$received) {
            if (++$attempts === 1) {
                throw new \RuntimeException('PRIVATE failure');
            }$received[$event['event_id']] = true;
        });
        $pump = $this->app->make(EventPump::class);
        $pump->subscribe($this->manager(), 'created', 'fixture', 'subscribe');
        $task = $this->todo('Own');
        $this->service()->create($this->manager('b'), 'PRIVATE', 'hidden');
        self::assertSame(1, $pump->run($this->worker())['retry']);
        self::assertSame(1, count($pump->deliveries($this->manager())));
        self::assertSame(0, $pump->run($this->worker())['sent']);
        $this->clock->advance('+5 seconds');
        self::assertSame(1, $pump->run($this->worker())['sent']);
        self::assertCount(1, $received);
        $pump->enqueue($this->worker(), 100, 0);
        self::assertSame(0, $pump->run($this->worker())['sent']);
        self::assertSame(2, $attempts);
        self::assertStringNotContainsString('PRIVATE', json_encode(DB::table('tm_event_deliveries')->get()->all()));
    }

    public function test_permission_revocation_after_enqueue_cancels_delivery_before_host_callback(): void
    {
        $delivered = 0;
        $this->app->make(ConsumerRegistry::class)->register('fixture', function () use (&$delivered) {
            $delivered++;
        });
        $pump = $this->app->make(EventPump::class);
        $pump->subscribe($this->manager(), 'created', 'fixture', 'subscribe');
        $task = $this->service()->create($this->manager('b'), 'Shared', 'task');
        $task = $this->service()->share($this->manager('b'), $task['public_key'], 1, 'share', 'a');
        $pump->enqueue($this->worker());
        $this->service()->share($this->manager('b'), $task['public_key'], $task['version'], 'revoke', 'a', revoke: true);
        self::assertSame(1, $pump->run($this->worker())['cancelled']);
        self::assertSame(0, $delivered);
    }

    public function test_producer_rollback_and_committed_undispatched_event_recovery_are_explicit(): void
    {
        $received = [];
        $this->app->make(ConsumerRegistry::class)->register('recovery', function ($context, $event) use (&$received) {
            $received[$event['event_id']] = true;
        });
        $pump = $this->app->make(EventPump::class);
        $pump->subscribe($this->manager(), 'created', 'recovery', 'subscribe');
        DB::beginTransaction();
        $this->todo('Rolled back');
        $this->error('event_worker_context_invalid', fn () => $pump->run($this->worker()));
        DB::rollBack();
        self::assertSame(0, DB::table('tm_tasks')->count());
        self::assertSame(0, DB::table('tm_outbox')->where('action', 'created')->count());
        $this->todo('Committed before dispatch');
        self::assertCount(0, $received);
        self::assertSame(1, $pump->run($this->worker())['sent']);
        self::assertCount(1,$received);
        self::assertSame(0,$pump->run($this->worker())['sent']);
    }
}
