<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\RouteTestCase;

final class TM182Test extends RouteTestCase
{
    public function test_tm182(): void
    {
        [$origin, $sales, $r] = $this->ticketFixture();
        $result = $this->routes()->spawn($this->manager(), 'ticket.sales-v1', $origin['instance_key'], 1, $sales['pipeline_key'], $r['id'], 'main', 'refer', 'sales_opportunity_identified', 'spawn');
        $source = $this->instances()->get($this->manager(), $result['origin_key']); $target = $this->instances()->get($this->manager(), $result['destination_key']);
        self::assertSame('referred', $source['current_state_key']); self::assertSame('support.ticket', $source['subject_type']);
        self::assertSame('crm.opportunity', $target['subject_type']); self::assertNotSame($source['subject_id'], $target['subject_id']);
        self::assertSame($source['case_id'], $target['case_id']); self::assertSame(2, DB::table('tm_case_subjects')->count());
        self::assertSame(1, DB::table('host_route_operations')->count()); self::assertSame(1, DB::table('tm_subject_conversions')->count());
        self::assertNull(DB::table('host_subjects')->where('id', $target['subject_id'])->value('private_text'));
        $timeline = $this->cases()->timeline($this->manager('b'), $result['case_key']);
        self::assertNotEmpty($timeline); self::assertSame([$target['instance_key']], array_values(array_unique(array_column($timeline, 'aggregate_key'))));
        self::assertStringNotContainsString('PRIVATE-HOST-TEXT', json_encode($timeline));
        self::assertSame($result, $this->routes()->spawn($this->manager(), 'ticket.sales-v1', $origin['instance_key'], 1, $sales['pipeline_key'], $r['id'], 'main', 'refer', 'sales_opportunity_identified', 'spawn'));
        self::assertSame(2, DB::table('host_subjects')->count());
    }
}

