<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Handoff\ExternalSagaService;
use Karnoweb\TaskManager\Handoff\LocalRouteService;
use Karnoweb\TaskManager\Handoff\SubjectRouteRegistry;
use Karnoweb\TaskManager\Tests\Support\HostLocalRouter;
use Karnoweb\TaskManager\Tests\Support\HostRemoteRouter;

abstract class RouteTestCase extends HandoffTestCase
{
    protected HostLocalRouter $localRouter;

    protected HostRemoteRouter $remoteRouter;

    private string $remotePath;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::table('host_subjects', fn (Blueprint $t) => $t->string('department', 100)->default('support'));
        Schema::create('host_route_operations', function (Blueprint $t) {
            $t->string('operation_id', 120)->primary();
            $t->string('kind', 30);
        });
        $this->remotePath = tempnam(sys_get_temp_dir(), 'tm-remote-');
        $this->app['config']->set('database.connections.remote_fixture', ['driver' => 'sqlite', 'database' => $this->remotePath, 'prefix' => '']);
        $remote = DB::connection('remote_fixture');
        $remote->getSchemaBuilder()->create('remote_opportunities', function (Blueprint $t) {
            $t->string('id', 120)->primary();
            $t->string('scope_key', 120);
            $t->string('operation_id', 120)->unique();
            $t->string('summary');
            $t->boolean('cancelled');
        });
        $this->localRouter = new HostLocalRouter(DB::connection());
        $this->remoteRouter = new HostRemoteRouter($remote, DB::connection());
        $this->app->instance(SubjectRouteRegistry::class, new SubjectRouteRegistry(['ticket.sales-v1' => $this->localRouter], ['ticket.remote-v1' => $this->remoteRouter]));
    }

    protected function tearDown(): void
    {
        DB::purge('remote_fixture');
        if (isset($this->remotePath) && is_file($this->remotePath)) {
            unlink($this->remotePath);
        } parent::tearDown();
    }

    protected function manager(string $actor = 'a', string $scope = 'one'): ExecutionContext
    {
        $base = parent::manager($actor, $scope);

        return new ExecutionContext($scope, $actor, 'route-test', capabilities: [...$base->capabilities, 'saga.manage', 'saga.repair']);
    }

    protected function routes(): LocalRouteService
    {
        return $this->app->make(LocalRouteService::class);
    }

    protected function sagas(): ExternalSagaService
    {
        return $this->app->make(ExternalSagaService::class);
    }

    protected function ticketFixture(): array
    {
        $definition = $this->definition();
        $definition['states']['referred'] = ['terminal' => 'success'];
        $definition['transitions']['refer'] = ['from' => 'new', 'to' => 'referred', 'reason_required' => true, 'reason_codes' => ['sales_opportunity_identified']];
        $r = $this->workflows()->publish($this->manager(), 'tickets', 0, 'ticket-definition', $definition);
        $stages = ['inbox' => ['state' => 'new'], 'review' => ['state' => 'review'], 'won' => ['state' => 'won'], 'referred' => ['state' => 'referred']];
        $support = $this->pipelines()->create($this->manager(), 'Support', $r['id'], $stages, 'support-pipeline');
        $sales = $this->pipelines()->create($this->manager(), 'Sales', $r['id'], $stages, 'sales-pipeline');
        $this->host('ticket-1', 'support.ticket');
        $origin = $this->instances()->start($this->manager(), 'support.ticket', 'ticket-1', $r['id'], 'ticket-start', pipelineKey: $support['pipeline_key']);

        return [$origin, $sales, $r];
    }
}
