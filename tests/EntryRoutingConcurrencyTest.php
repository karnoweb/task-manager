<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\EntryRoutingProvider;
use Karnoweb\TaskManager\Tests\Support\ProcessBarrier;
use Karnoweb\TaskManager\Workflow\EntryRoutingService;

final class EntryRoutingConcurrencyTest extends WorkflowTestCase
{
    public function test_two_server_connections_cannot_replace_one_default_with_the_same_version(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mariadb'], true)) {
            self::markTestSkipped('Physical concurrency requires the dedicated PostgreSQL or MariaDB fixture.');
        }
        $this->app->instance(EntryRoutingProvider::class, new class implements EntryRoutingProvider
        {
            public function authorize(ExecutionContext $context, string $routingKey): void
            {
            }

            public function parent(ExecutionContext $context, string $routingKey): ?string
            {
                return null;
            }
        });
        $r = $this->sales();
        $a = $this->pipeline($r['id'], name: 'A');
        $b = $this->pipeline($r['id'], name: 'B');
        $routing = $this->app->make(EntryRoutingService::class);
        $routing->setDefault($this->manager(), 'support', $a['pipeline_key'], 0, 'initial');
        $results = ProcessBarrier::run([[$a['pipeline_key'], 'race-a'], [$b['pipeline_key'], 'race-b']], 'concurrent-entry-route.php');
        self::assertCount(1, array_filter($results, fn ($r) => ($r['version'] ?? null) === 2));
        self::assertCount(1, array_filter($results, fn ($r) => ($r['error'] ?? null) === 'version_conflict'));
        self::assertSame(1, DB::table('tm_entry_routes')->count());
        self::assertSame(2, $routing->getDefault($this->manager(), 'support')['version']);
        self::assertSame(2, DB::table('tm_outbox')->where('action', 'entry_route_changed')->count());
    }
}
