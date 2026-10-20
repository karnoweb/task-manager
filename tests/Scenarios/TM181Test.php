<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\RouteTestCase;

final class TM181Test extends RouteTestCase
{
    public function test_tm181(): void
    {
        [$origin, $sales] = $this->ticketFixture();
        $preview = $this->instances()->previewMove($this->manager(), $origin['instance_key'], $sales['pipeline_key'], ['new' => 'new']);
        $moved = $this->routes()->move($this->manager(), 'ticket.sales-v1', $preview, 'sales_opportunity_identified', 'route-move');
        self::assertSame($origin['instance_key'], $moved['instance_key']); self::assertSame('support.ticket', $moved['subject_type']);
        self::assertSame('ticket-1', $moved['subject_id']); self::assertSame($sales['id'], $moved['pipeline_id']);
        self::assertSame('sales', DB::table('host_subjects')->where('id', 'ticket-1')->value('department'));
        self::assertSame(1, DB::table('host_route_operations')->count());
        self::assertSame($moved, $this->routes()->move($this->manager(), 'ticket.sales-v1', $preview, 'sales_opportunity_identified', 'route-move'));
        $visits = $this->instances()->visits($this->manager(), $origin['instance_key']);
        self::assertCount(2, $visits); self::assertNotNull($visits[0]['exited_at']); self::assertNull($visits[1]['exited_at']);
    }
}

