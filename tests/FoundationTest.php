<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\TaskManagerServiceProvider;
use Karnoweb\TaskManager\TaskService;
use Orchestra\Testbench\TestCase;

final class FoundationTest extends TestCase
{
    protected function getPackageProviders($app): array { return [TaskManagerServiceProvider::class]; }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $driver = getenv('TM_TEST_DRIVER') ?: 'sqlite';
        $app['config']->set('database.connections.testing', $driver === 'pgsql'
            ? ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 55439, 'database' => 'tm_acceptance', 'username' => 'tm_test', 'password' => '', 'prefix' => '', 'search_path' => 'public']
            : ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh')->run();
    }

    private function context(string $actor = 'a', string $scope = 'one'): ExecutionContext { return new ExecutionContext($scope, $actor, 'test'); }
    private function service(): TaskService { return $this->app->make(TaskService::class); }

    public function test_create_complete_reopen_records_atomic_history(): void
    {
        $s = $this->service(); $c = $this->context();
        $task = $s->create($c, 'تماس', 'create');
        self::assertNull($task['project_id']);
        self::assertSame('a', $task['owner_ref']);
        self::assertSame('pending', $task['state']);
        self::assertSame(1, DB::table('tm_lists')->count());
        $done = $s->mutate($c, $task['public_key'], 1, 'complete', 'complete');
        self::assertSame('done', $done['state']);
        self::assertNotNull($done['completed_at']);
        self::assertSame(2, $done['version']);
        $open = $s->mutate($c, $task['public_key'], 2, 'reopen', 'reopen');
        self::assertSame('pending', $open['state']);
        self::assertNull($open['completed_at']);
        self::assertSame(3, DB::table('tm_audit_events')->count());
        self::assertSame(3, DB::table('tm_outbox')->whereNull('published_at')->count());
    }

    public function test_idempotency_returns_original_result_without_duplicate_writes(): void
    {
        $s = $this->service(); $c = $this->context();
        $first = $s->create($c, 'one', 'create');
        self::assertSame($first, $s->create($c, 'one', 'create'));
        self::assertSame(1, DB::table('tm_tasks')->count());
        self::assertSame(1, DB::table('tm_outbox')->count());
        $this->expectExceptionMessage('idempotency_key_conflict');
        $s->create($c, 'different', 'create');
    }

    public function test_cross_actor_and_scope_cannot_read_count_or_update(): void
    {
        $s = $this->service(); $task = $s->create($this->context(), 'private', 'create');
        foreach ([$this->context('b'), $this->context('a', 'two')] as $other) {
            self::assertSame([], $s->query($other));
            self::assertSame(0, $s->count($other));
            try { $s->get($other, $task['public_key']); self::fail('Unauthorized read'); } catch (DomainError $e) { self::assertSame('not_found', $e->getMessage()); }
            try { $s->mutate($other, $task['public_key'], 1, 'edit', 'update', ['title' => 'stolen']); self::fail('Unauthorized write'); } catch (DomainError $e) { self::assertSame('not_found', $e->getMessage()); }
        }
        self::assertSame(1, DB::table('tm_command_journal')->count());
    }

    public function test_stale_version_rolls_back_all_side_effects(): void
    {
        $s = $this->service(); $c = $this->context(); $task = $s->create($c, 'one', 'create');
        $s->mutate($c, $task['public_key'], 1, 'first', 'update', ['priority' => 'high']);
        try { $s->mutate($c, $task['public_key'], 1, 'stale', 'complete'); self::fail('Stale write'); } catch (DomainError $e) { self::assertSame('version_conflict', $e->getMessage()); }
        self::assertSame('pending', $s->get($c, $task['public_key'])['state']);
        self::assertSame(2, DB::table('tm_command_journal')->count());
        self::assertSame(2, DB::table('tm_outbox')->count());
    }

    public function test_archive_delete_restore_preserve_identity(): void
    {
        $s = $this->service(); $c = $this->context(); $task = $s->create($c, 'one', 'create');
        foreach (['archive', 'restore', 'delete', 'undelete'] as $index => $action) {
            $result = $s->mutate($c, $task['public_key'], $index + 1, $action, $action);
            self::assertSame($task['public_key'], $result['public_key']);
            self::assertSame(in_array($action, ['archive', 'delete']) ? 0 : 1, $s->count($c));
        }
    }

    public function test_invalid_title_creates_no_inbox_or_event(): void
    {
        foreach (['', ' ', str_repeat('آ', 256)] as $title) {
            try { $this->service()->create($this->context(), $title, 'invalid'); self::fail('Invalid title'); } catch (DomainError $e) { self::assertSame('title_invalid', $e->getMessage()); }
        }
        self::assertSame(0, DB::table('tm_lists')->count());
        self::assertSame(0, DB::table('tm_outbox')->count());
    }

    public function test_empty_context_is_rejected(): void
    {
        $this->expectExceptionMessage('context_invalid');
        new ExecutionContext('', '', 'test');
    }

    public function test_share_revoke_immediately_changes_queries_and_activity(): void
    {
        $s = $this->service(); $a = $this->context(); $b = $this->context('b');
        $task = $s->create($a, 'shared', 'create');
        $s->share($a, $task['public_key'], 1, 'share', 'b');
        self::assertSame(1, $s->count($b));
        self::assertCount(2, $s->activity($b, $task['public_key']));
        $s->share($a, $task['public_key'], 2, 'revoke', 'b', revoke: true);
        self::assertSame(0, $s->count($b));
        $this->expectExceptionMessage('not_found');
        $s->activity($b, $task['public_key']);
    }

    public function test_clone_does_not_copy_shares_or_completed_state(): void
    {
        $s = $this->service(); $c = $this->context();
        $task = $s->create($c, 'original', 'create');
        $s->mutate($c, $task['public_key'], 1, 'done', 'complete');
        $copy = $s->cloneTask($c, $task['public_key'], 'clone');
        self::assertNotSame($task['public_key'], $copy['public_key']);
        self::assertSame('pending', $copy['state']);
        self::assertSame(1, $copy['version']);
        self::assertSame($copy, $s->cloneTask($c, $task['public_key'], 'clone'));
        self::assertSame(2, DB::table('tm_tasks')->count());
    }

    public function test_outbox_failure_retries_after_commit_with_same_event_identity(): void
    {
        $this->service()->create($this->context(), 'event', 'create');
        $dispatcher = new \Karnoweb\TaskManager\OutboxDispatcher(DB::connection());
        $eventId = null;
        try {
            $dispatcher->dispatch('one', function ($event) use (&$eventId) { $eventId = $event['event_id']; throw new \RuntimeException('crash'); });
            self::fail('Crash expected');
        } catch (\RuntimeException $e) { self::assertSame('crash', $e->getMessage()); }
        self::assertSame(1, DB::table('tm_outbox')->whereNull('published_at')->count());
        self::assertSame(1, $dispatcher->dispatch('one', function ($event) use ($eventId) { self::assertSame($eventId, $event['event_id']); }));
        self::assertSame(0, $dispatcher->dispatch('one', fn () => self::fail('Duplicate acknowledged event')));
        self::assertSame(2, DB::table('tm_outbox')->value('attempts'));
        self::assertSame(1, DB::table('tm_tasks')->count());
    }

    public function test_outbox_cannot_deliver_inside_outer_transaction(): void
    {
        DB::beginTransaction();
        try {
            $dispatcher = new \Karnoweb\TaskManager\OutboxDispatcher(DB::connection());
            $dispatcher->dispatch('one', fn () => self::fail('Uncommitted delivery'));
            self::fail('Dispatch must be rejected');
        } catch (DomainError $e) { self::assertSame('dispatch_before_commit', $e->getMessage()); }
        finally { DB::rollBack(); }
    }

    public function test_nested_transaction_rollback_removes_task_journal_and_outbox(): void
    {
        DB::beginTransaction();
        $this->service()->create($this->context(), 'rollback', 'rollback');
        DB::rollBack();
        foreach (['tm_tasks', 'tm_lists', 'tm_workflow_instances', 'tm_command_journal', 'tm_outbox', 'tm_audit_events'] as $table) {
            self::assertSame(0, DB::table($table)->count());
        }
    }

    public function test_two_scopes_have_independent_personal_definitions(): void
    {
        $s = $this->service();
        $s->create($this->context(), 'one', 'one');
        $s->create($this->context('a', 'two'), 'two', 'two');
        self::assertSame(2, DB::table('tm_workflow_definitions')->count());
        DB::table('tm_workflow_definitions')->where('scope_key', 'one')->update(['definition' => '{"custom":true}']);
        self::assertStringContainsString('pending', DB::table('tm_workflow_definitions')->where('scope_key', 'two')->value('definition'));
    }

    public function test_move_between_owned_lists_preserves_rank_and_rejects_foreign_list(): void
    {
        $s = $this->service(); $a = $this->context();
        $task = $s->create($a, 'move', 'create');
        $list = $s->createList($a, 'Work', 'list');
        self::assertSame($list, $s->createList($a, 'Work', 'list'));
        $moved = $s->mutate($a, $task['public_key'], 1, 'move', 'move_list', ['list_id' => $list['list_id']]);
        self::assertEquals($task['rank'], $moved['rank']);
        self::assertSame($list['list_id'], $moved['list_id']);
        self::assertSame([], $s->lists($this->context('b')));
        $foreign = $s->createList($this->context('b'), 'Other', 'foreign');
        $this->expectExceptionMessage('not_found');
        $s->mutate($a, $task['public_key'], 2, 'foreign-move', 'move_list', ['list_id' => $foreign['list_id']]);
    }

    public function test_facade_has_service_parity_and_missing_context_fails_closed(): void
    {
        $task = \Karnoweb\TaskManager\Facades\TaskManager::create($this->context(), 'Facade', 'facade');
        self::assertSame($task, $this->service()->get($this->context(), $task['public_key']));
        $this->expectException(\TypeError::class);
        \Karnoweb\TaskManager\Facades\TaskManager::create(null, 'No context', 'invalid');
    }

    public function test_service_actor_requires_explicit_limited_capabilities(): void
    {
        $context = new ExecutionContext('one', 'worker', 'test', true, ['task.view']);
        self::assertSame(0, $this->service()->count($context));
        try { $this->service()->create($context, 'denied', 'denied'); self::fail('Service capability bypass'); }
        catch (DomainError $e) { self::assertSame('capability_denied', $e->getMessage()); }
        self::assertSame(0, DB::table('tm_command_journal')->count());
        $this->expectExceptionMessage('service_capabilities_required');
        new ExecutionContext('one', 'worker', 'test', true);
    }

    public function test_clock_controls_completion_and_audit_without_waiting(): void
    {
        $clock = new class implements \Karnoweb\TaskManager\Contracts\Clock {
            public function now(): \DateTimeImmutable { return new \DateTimeImmutable('2026-10-20T12:00:00Z'); }
        };
        $s = new TaskService(DB::connection(), $clock); $c = $this->context();
        $task = $s->create($c, 'clock', 'create');
        $done = $s->mutate($c, $task['public_key'], 1, 'done', 'complete');
        self::assertSame('2026-10-20 12:00:00', $done['completed_at']);
        self::assertSame('2026-10-20 12:00:00', DB::table('tm_instance_transitions')->value('occurred_at'));
    }

    public function test_share_view_does_not_grant_edit_and_facets_stay_scoped(): void
    {
        $s = $this->service(); $a = $this->context(); $b = $this->context('b');
        $task = $s->create($a, 'view', 'create');
        $s->share($a, $task['public_key'], 1, 'share', 'b');
        self::assertSame([['priority' => 'normal', 'total' => 1]], $s->facets($b));
        self::assertSame([], $s->facets($this->context('b', 'two')));
        $this->expectExceptionMessage('not_found');
        $s->mutate($b, $task['public_key'], 2, 'edit', 'update', ['priority' => 'urgent']);
    }

    public function test_payload_field_order_is_canonical_for_retry(): void
    {
        $s = $this->service(); $c = $this->context(); $task = $s->create($c, 'one', 'create');
        $first = $s->mutate($c, $task['public_key'], 1, 'edit', 'update', ['priority' => 'high', 'title' => 'two']);
        self::assertSame($first, $s->mutate($c, $task['public_key'], 1, 'edit', 'update', ['title' => 'two', 'priority' => 'high']));
        self::assertSame(2, DB::table('tm_outbox')->count());
    }

    public function test_clone_operation_does_not_collide_with_user_supplied_suffix(): void
    {
        $s = $this->service(); $c = $this->context();
        $source = $s->create($c, 'same', 'clone:create');
        $copy = $s->cloneTask($c, $source['public_key'], 'clone');
        self::assertNotSame($source['public_key'], $copy['public_key']);
        self::assertSame(2, DB::table('tm_tasks')->count());
        self::assertSame(2, DB::table('tm_command_journal')->count());
    }

    public function test_display_sequences_and_alias_resolution_are_scoped(): void
    {
        $s = $this->service(); $c = $this->context();
        $a = $s->create($c, 'first', 'one');
        $b = $s->create($c, 'second', 'two');
        $other = $s->create($this->context('a', 'two'), 'third', 'three');
        self::assertSame('TM-1', $a['display_key']);
        self::assertSame('TM-2', $b['display_key']);
        self::assertSame('TM-1', $other['display_key']);
        self::assertSame($a, $s->resolveKey($c, 'TM-1'));
        self::assertSame($other, $s->resolveKey($this->context('a', 'two'), 'TM-1'));
        $this->expectExceptionMessage('not_found');
        $s->resolveKey($this->context('b'), 'TM-1');
    }

    public function test_migration_rollback_and_reinstall(): void
    {
        $this->service()->create($this->context(), 'before rollback', 'one');
        $this->artisan('migrate:rollback')->run();
        self::assertFalse(DB::getSchemaBuilder()->hasTable('tm_tasks'));
        self::assertFalse(DB::getSchemaBuilder()->hasTable('tm_outbox'));
        $this->artisan('migrate')->run();
        self::assertSame(0, $this->service()->count($this->context()));
    }

    public function test_postgres_real_concurrent_same_operation_creates_one_task(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real concurrency runs only in the PostgreSQL profile.');
        }
        $server = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        self::assertIsResource($server);
        $address = 'tcp://'.stream_socket_get_name($server, false);
        $workers = [];
        try {
            for ($i = 0; $i < 4; ++$i) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, __DIR__.'/Support/concurrent-create.php', $address], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                $workers[] = [$process, $pipes];
            }
            $clients = [];
            for ($i = 0; $i < 4; ++$i) {
                $client = stream_socket_accept($server, 10);
                self::assertIsResource($client);
                stream_set_timeout($client, 10);
                self::assertSame("ready\n", fgets($client));
                $clients[] = $client;
            }
            foreach ($clients as $client) { fwrite($client, "go\n"); fclose($client); }
            $ids = [];
            foreach ($workers as [$process, $pipes]) {
                fclose($pipes[0]);
                $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $errors);
                $ids[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR)['id'];
            }
            self::assertCount(1, array_unique($ids));
            foreach (['tm_tasks', 'tm_lists', 'tm_workflow_instances', 'tm_command_journal', 'tm_outbox', 'tm_audit_events'] as $table) {
                self::assertSame(1, DB::table($table)->count(), $table);
            }
        } finally { fclose($server); }
    }

    public function test_postgres_concurrent_updates_accept_exactly_one_version(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') { $this->markTestSkipped('Real PostgreSQL concurrency profile required.'); }
        $task = $this->service()->create(new ExecutionContext('race', 'actor', 'race-test'), 'Concurrent', 'seed');
        $server = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        self::assertIsResource($server);
        $address = 'tcp://'.stream_socket_get_name($server, false);
        $workers = []; $clients = [];
        try {
            for ($i = 0; $i < 2; ++$i) {
                $process = proc_open([PHP_BINARY, __DIR__.'/Support/concurrent-create.php', $address, 'update', $task['public_key'], 'update-'.$i], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process); $workers[] = [$process, $pipes];
            }
            for ($i = 0; $i < 2; ++$i) {
                $client = stream_socket_accept($server, 10); self::assertIsResource($client);
                stream_set_timeout($client, 10); self::assertSame("ready\n", fgets($client)); $clients[] = $client;
            }
            foreach ($clients as $client) { fwrite($client, "go\n"); fclose($client); }
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]); self::assertSame(0, proc_close($process), $errors);
                $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            }
            self::assertCount(1, array_filter($results, fn ($r) => isset($r['id'])));
            self::assertCount(1, array_filter($results, fn ($r) => ($r['error'] ?? '') === 'version_conflict'));
            self::assertSame(2, DB::table('tm_tasks')->value('version'));
            self::assertSame(2, DB::table('tm_audit_events')->count());
            self::assertSame(2, DB::table('tm_outbox')->count());
            self::assertSame(2, DB::table('tm_command_journal')->count());
        } finally { fclose($server); }
    }
}
