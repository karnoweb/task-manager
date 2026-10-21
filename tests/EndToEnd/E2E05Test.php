<?php

namespace Karnoweb\TaskManager\Tests\EndToEnd;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Tests\RouteTestCase;

final class E2E05Test extends RouteTestCase
{
    public function test_same_ticket_routes_to_sales_with_host_department_and_current_access_rules(): void
    {
        [$origin, $sales] = $this->ticketFixture();
        try {
            $this->instances()->get($this->manager('b'), $origin['instance_key']);
            self::fail();
        } catch (DomainError $error) {
            self::assertSame('not_found', $error->getMessage());
        }
        $preview = $this->instances()->previewMove($this->manager(), $origin['instance_key'], $sales['pipeline_key'], ['new' => 'new']);
        $result = $this->routes()->move($this->manager(), 'ticket.sales-v1', $preview, 'sales_opportunity_identified', 'move-ticket');
        $salesView = $this->instances()->get($this->manager('b'), $origin['instance_key']);
        self::assertSame($origin['instance_key'], $result['instance_key']);
        self::assertSame('support.ticket', $salesView['subject_type']);
        self::assertSame('ticket-1', $salesView['subject_id']);
        self::assertSame($sales['id'], $salesView['pipeline_id']);
        self::assertSame('sales', DB::table('host_subjects')->where('id', 'ticket-1')->value('department'));
        $visits = $this->instances()->visits($this->manager('b'), $origin['instance_key']);
        self::assertCount(2, $visits);
        self::assertNotNull($visits[0]['exited_at']);
        self::assertNull($visits[1]['exited_at']);
        self::assertSame(1, DB::table('tm_workflow_instances')->count());
        self::assertSame(1, DB::table('host_subjects')->count());
        self::assertSame(1, DB::table('host_route_operations')->count());
    }
}
