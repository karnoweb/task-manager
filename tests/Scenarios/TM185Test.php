<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\SubjectHandler;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\SubjectRegistry;
use Karnoweb\TaskManager\Tests\HandoffTestCase;
use Karnoweb\TaskManager\Tests\Support\HostSubjectHandler;

final class TM185Test extends HandoffTestCase
{
    public function test_tm185(): void
    {
        [$origin, $destination, $r] = $this->handoffFixture(false);
        $handler = new HostSubjectHandler(DB::connection(), 'crm.lead');
        $route = $destination['pipeline_key'];
        $this->app->instance(SubjectRegistry::class, new SubjectRegistry(['crm.lead' => new class($handler, $route) implements SubjectHandler
        {
            public function __construct(private $handler, private string $route)
            {
            }

            public function query(ExecutionContext $context, string $capability): Builder
            {
                return $this->handler->query($context, $capability);
            }

            public function idColumn(): string
            {
                return 'id';
            }

            public function scopeColumn(): string
            {
                return 'scope_key';
            }

            public function guard(ExecutionContext $context, string $subjectId, array $instance, string $action, array $payload): void
            {
                if ($action === 'start' && ($payload['pipeline_key'] ?? null) === $this->route) {
                    throw new DomainError('destination_not_accepted');
                }
                $this->handler->guard($context, $subjectId, $instance, $action, $payload);
            }
        }]));
        try {
            $this->handoffs()->delegate($this->manager(), $origin['instance_key'], 1, $route, $r['id'], 'contracts', $this->returnPolicy(), 'delegate');
            self::fail();
        } catch (DomainError $e) {
            self::assertSame('destination_not_accepted', $e->errorCode);
        }
        self::assertSame(0, DB::table('tm_handoffs')->count());
        self::assertSame(1, DB::table('tm_workflow_instances')->count());
        self::assertSame('active', $this->instances()->get($this->manager(), $origin['instance_key'])['lifecycle']);
    }
}
