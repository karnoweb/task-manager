<?php

namespace Karnoweb\TaskManager\Tests\Scenarios\Complete;

use Karnoweb\Crm\Models\Deal;
use Karnoweb\TaskManager\Handoff\LocalRouteService;
use Karnoweb\TaskManager\Tests\RealHostE2ETestCase;

final class Scenario11DepartmentCrmTest extends RealHostE2ETestCase
{
    public static function contractOutcomes(): array
    {
        return [['approve', 'review'], ['reject', 'new'], ['cancel', 'new']];
    }

    public function test_complete_scenario_11(): void
    {

        [$ticket,$origin,$sales,$r,$router] = $this->ticketFlow();
        $routes = $this->app->make(LocalRouteService::class);
        $router->failAfterCreate = true;
        $this->error('host_crm_failure', fn () => $routes->spawn($this->manager(), 'ticket.sales-v1', $origin['instance_key'], 1, $sales['pipeline_key'], $r['id'], 'main', 'refer', 'sales_opportunity_identified', 'spawn'));
        self::assertSame(1, Deal::query()->count());
        self::assertNull($ticket->fresh()->referred_deal_id);
        $router->failAfterCreate = false;
        $result = $routes->spawn($this->manager(), 'ticket.sales-v1', $origin['instance_key'], 1, $sales['pipeline_key'], $r['id'], 'main', 'refer', 'sales_opportunity_identified', 'spawn');
        $again = $routes->spawn($this->manager(), 'ticket.sales-v1', $origin['instance_key'], 1, $sales['pipeline_key'], $r['id'], 'main', 'refer', 'sales_opportunity_identified', 'spawn');
        self::assertSame($result, $again);
        self::assertSame(2, Deal::query()->count());
        self::assertSame('crm.deal', $this->instances()->get($this->manager(), $result['destination_key'])['subject_type']);
        self::assertSame('referred', $this->instances()->get($this->manager(), $origin['instance_key'])['current_state_key']);
        self::assertStringNotContainsString('PRIVATE SUPPORT MESSAGE', json_encode($this->cases()->timeline($this->manager(), $result['case_key'])));

    }

    public function test_same_ticket_department_variant(): void
    {

        [$ticket,$origin,$sales] = $this->ticketFlow();
        $this->error('not_found', fn () => $this->instances()->get($this->manager('2'), $origin['instance_key']));
        $preview = $this->instances()->previewMove($this->manager(), $origin['instance_key'], $sales['pipeline_key'], ['new' => 'new']);
        $result = $this->app->make(LocalRouteService::class)->move($this->manager(), 'ticket.sales-v1', $preview, 'sales_opportunity_identified', 'move');
        self::assertSame($origin['instance_key'], $result['instance_key']);
        self::assertSame('sales', $ticket->fresh()->department);
        self::assertSame((string) $ticket->id, $this->instances()->get($this->manager('2'), $origin['instance_key'])['subject_id']);
        $visits = $this->instances()->visits($this->manager('2'), $origin['instance_key']);
        self::assertCount(2, $visits);
        self::assertNotNull($visits[0]['exited_at']);
        self::assertNull($visits[1]['exited_at']);

    }
}
