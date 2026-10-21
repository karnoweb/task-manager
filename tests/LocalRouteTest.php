<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Handoff\SubjectRouteRegistry;
use Karnoweb\TaskManager\Tests\Support\HostLocalRouter;

final class LocalRouteTest extends RouteTestCase
{
    public function test_same_ticket_moves_with_department_change_by_owner_service(): void
    {
        [$origin, $sales] = $this->ticketFixture();
        $preview = $this->instances()->previewMove($this->manager(), $origin['instance_key'], $sales['pipeline_key'], ['new' => 'new']);
        $moved = $this->routes()->move($this->manager(), 'ticket.sales-v1', $preview, 'sales_opportunity_identified', 'route-move');
        self::assertSame($origin['instance_key'], $moved['instance_key']);
        self::assertSame('support.ticket', $moved['subject_type']);
        self::assertSame('ticket-1', $moved['subject_id']);
        self::assertSame($sales['id'], $moved['pipeline_id']);
        self::assertSame('sales', DB::table('host_subjects')->where('id', 'ticket-1')->value('department'));
        self::assertSame(1, DB::table('host_route_operations')->count());
        self::assertSame($moved, $this->routes()->move($this->manager(), 'ticket.sales-v1', $preview, 'sales_opportunity_identified', 'route-move'));
        $visits = $this->instances()->visits($this->manager(), $origin['instance_key']);
        self::assertCount(2, $visits);
        self::assertNotNull($visits[0]['exited_at']);
        self::assertNull($visits[1]['exited_at']);
    }

    public function test_spawn_uses_owner_service_links_two_subjects_and_hides_ticket_private_content(): void
    {
        [$origin, $sales, $r] = $this->ticketFixture();
        $result = $this->routes()->spawn($this->manager(), 'ticket.sales-v1', $origin['instance_key'], 1, $sales['pipeline_key'], $r['id'], 'main', 'refer', 'sales_opportunity_identified', 'spawn');
        $source = $this->instances()->get($this->manager(), $result['origin_key']);
        $target = $this->instances()->get($this->manager(), $result['destination_key']);
        self::assertSame('referred', $source['current_state_key']);
        self::assertSame('support.ticket', $source['subject_type']);
        self::assertSame('crm.opportunity', $target['subject_type']);
        self::assertNotSame($source['subject_id'], $target['subject_id']);
        self::assertSame($source['case_id'], $target['case_id']);
        self::assertSame(2, DB::table('tm_case_subjects')->count());
        self::assertSame(1, DB::table('host_route_operations')->count());
        self::assertSame(1, DB::table('tm_subject_conversions')->count());
        self::assertNull(DB::table('host_subjects')->where('id', $target['subject_id'])->value('private_text'));
        $timeline = $this->cases()->timeline($this->manager('b'), $result['case_key']);
        self::assertNotEmpty($timeline);
        self::assertSame([$target['instance_key']], array_values(array_unique(array_column($timeline, 'aggregate_key'))));
        self::assertStringNotContainsString('PRIVATE-HOST-TEXT', json_encode($timeline));
        self::assertSame($result, $this->routes()->spawn($this->manager(), 'ticket.sales-v1', $origin['instance_key'], 1, $sales['pipeline_key'], $r['id'], 'main', 'refer', 'sales_opportunity_identified', 'spawn'));
        self::assertSame(2, DB::table('host_subjects')->count());
    }

    public function test_owner_write_rolls_back_when_a_later_workflow_guard_denies(): void
    {
        [$origin, $sales, $r] = $this->ticketFixture();
        DB::table('host_subjects')->where('id', 'ticket-1')->update(['veto' => true]);
        $events = DB::table('tm_audit_events')->count();
        try {
            $this->routes()->spawn($this->manager(), 'ticket.sales-v1', $origin['instance_key'], 1, $sales['pipeline_key'], $r['id'], 'main', 'refer', 'sales_opportunity_identified', 'spawn');
            self::fail();
        } catch (DomainError $e) {
            self::assertSame('host_guard_veto', $e->getMessage());
        }
        self::assertSame(1, DB::table('host_subjects')->count());
        self::assertSame(0, DB::table('host_route_operations')->count());
        self::assertSame(0, DB::table('tm_subject_conversions')->count());
        self::assertSame(0, DB::table('tm_process_cases')->count());
        self::assertSame(1, DB::table('tm_workflow_instances')->count());
        self::assertSame($events, DB::table('tm_audit_events')->count());
        self::assertSame('new', $this->instances()->get($this->manager(), $origin['instance_key'])['current_state_key']);
    }

    public function test_mismatched_transaction_connection_is_rejected_before_owner_write(): void
    {
        [$origin, $sales] = $this->ticketFixture();
        $this->app->instance(SubjectRouteRegistry::class, new SubjectRouteRegistry(['ticket.sales-v1' => new HostLocalRouter(DB::connection('remote_fixture'))]));
        $preview = $this->instances()->previewMove($this->manager(), $origin['instance_key'], $sales['pipeline_key'], ['new' => 'new']);
        $this->expectExceptionMessage('same_connection_required');
        $this->routes()->move($this->manager(), 'ticket.sales-v1', $preview, 'sales_opportunity_identified', 'move');
    }
}
