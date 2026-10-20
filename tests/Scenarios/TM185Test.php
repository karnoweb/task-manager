<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Tests\HandoffTestCase;
use Karnoweb\TaskManager\Tests\Support\ProcessBarrier;

final class TM185Test extends HandoffTestCase
{
    public function test_tm185(): void
    {
        [$origin, $destination, $r] = $this->handoffFixture(false);
        $handler = new \Karnoweb\TaskManager\Tests\Support\HostSubjectHandler(DB::connection(), 'crm.lead');
        $route = $destination['pipeline_key'];
        $this->app->instance(\Karnoweb\TaskManager\SubjectRegistry::class, new \Karnoweb\TaskManager\SubjectRegistry(['crm.lead' => new class($handler, $route) implements \Karnoweb\TaskManager\Contracts\SubjectHandler {
            public function __construct(private $handler, private string $route) {}
            public function query(\Karnoweb\TaskManager\Context\ExecutionContext $context, string $capability): \Illuminate\Database\Query\Builder { return $this->handler->query($context, $capability); }
            public function idColumn(): string { return 'id'; }
            public function scopeColumn(): string { return 'scope_key'; }
            public function guard(\Karnoweb\TaskManager\Context\ExecutionContext $context, string $subjectId, array $instance, string $action, array $payload): void {
                if ($action === 'start' && ($payload['pipeline_key'] ?? null) === $this->route) { throw new DomainError('destination_not_accepted'); }
                $this->handler->guard($context, $subjectId, $instance, $action, $payload);
            }
        }]));
        try { $this->handoffs()->delegate($this->manager(), $origin['instance_key'], 1, $route, $r['id'], 'contracts', $this->returnPolicy(), 'delegate'); self::fail(); }
        catch (DomainError $e) { self::assertSame('destination_not_accepted', $e->getMessage()); }
        self::assertSame(0, DB::table('tm_handoffs')->count()); self::assertSame(1, DB::table('tm_workflow_instances')->count());
        self::assertSame('active', $this->instances()->get($this->manager(), $origin['instance_key'])['lifecycle']);
    }
}

