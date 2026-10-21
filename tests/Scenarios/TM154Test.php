<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\SubjectHandler;
use Karnoweb\TaskManager\SubjectRegistry;
use Karnoweb\TaskManager\Tests\Support\HostSubjectHandler;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM154Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $r = $this->sales();
        $this->host('ticket', 'support.ticket');
        $i = $this->instances()->start($c, 'support.ticket', 'ticket', $r['id'], 'start');
        $delegate = new HostSubjectHandler(DB::connection(), 'support.ticket');
        $replacement = new class($delegate) implements SubjectHandler
        {
            public function __construct(private $inner)
            {
            }

            public function query(ExecutionContext $c, string $p): Builder
            {
                return $this->inner->query($c, $p);
            }

            public function idColumn(): string
            {
                return $this->inner->idColumn();
            }

            public function scopeColumn(): string
            {
                return $this->inner->scopeColumn();
            }

            public function guard(ExecutionContext $c, string $id, array $i, string $a, array $p): void
            {
                $this->inner->guard($c, $id, $i, $a, $p);
            }
        };
        $this->app->instance(SubjectRegistry::class, new SubjectRegistry(['support.ticket' => $replacement]));
        self::assertSame($i, $this->instances()->get($c, $i['instance_key']));
        self::assertSame('support.ticket', DB::table('tm_workflow_instances')->value('subject_type'));
    }
}
