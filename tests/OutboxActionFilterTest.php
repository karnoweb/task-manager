<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\OutboxDispatcher;

final class OutboxActionFilterTest extends PackageTestCase
{
    public function test_a_filtered_dispatch_leaves_every_other_action_unpublished(): void
    {
        $this->service()->create($this->context(), 'Keep', 'create-keep');
        $others = DB::table('tm_outbox')->where('action', '!=', 'reminder_due')->whereNull('published_at')->count();
        $this->assertGreaterThan(0, $others);

        $sample = (array) DB::table('tm_outbox')->first();
        unset($sample['id']);
        $sample['event_id'] = (string) Str::uuid();
        $sample['action'] = 'reminder_due';
        $sample['operation_id'] = 'reminder:filter';
        $sample['payload'] = '{}';
        $sample['published_at'] = null;
        $sample['attempts'] = 0;
        DB::table('tm_outbox')->insert($sample);

        $seen = [];
        $dispatcher = new OutboxDispatcher(DB::connection());
        $this->assertSame(1, $dispatcher->dispatch('one', function (array $event) use (&$seen): void {
            $seen[] = $event['action'];
        }, 100, 'reminder_due'));

        $this->assertSame(['reminder_due'], $seen);
        $this->assertSame($others, DB::table('tm_outbox')->where('action', '!=', 'reminder_due')->whereNull('published_at')->count());
        $this->assertNotNull(DB::table('tm_outbox')->where('action', 'reminder_due')->value('published_at'));
    }

    public function test_a_failed_filtered_consumer_does_not_mark_the_event_published(): void
    {
        $this->service()->create($this->context(), 'Keep', 'create-keep');
        $sample = (array) DB::table('tm_outbox')->first();
        unset($sample['id']);
        $sample['event_id'] = (string) Str::uuid();
        $sample['action'] = 'reminder_due';
        $sample['operation_id'] = 'reminder:retry';
        $sample['payload'] = '{}';
        $sample['published_at'] = null;
        $sample['attempts'] = 0;
        DB::table('tm_outbox')->insert($sample);

        $dispatcher = new OutboxDispatcher(DB::connection());
        try {
            $dispatcher->dispatch('one', function (): void {
                throw new \RuntimeException('listener failed');
            }, 100, 'reminder_due');
            $this->fail('The consumer failure must surface.');
        } catch (\RuntimeException $e) {
            $this->assertSame('listener failed', $e->getMessage());
        }

        $this->assertNull(DB::table('tm_outbox')->where('action', 'reminder_due')->value('published_at'));
        $this->assertSame(1, (int) DB::table('tm_outbox')->where('action', 'reminder_due')->value('attempts'));
        $this->assertGreaterThan(0, DB::table('tm_outbox')->where('action', '!=', 'reminder_due')->whereNull('published_at')->count());
    }
}
