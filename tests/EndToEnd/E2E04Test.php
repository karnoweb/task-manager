<?php

namespace Karnoweb\TaskManager\Tests\EndToEnd;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\RouteTestCase;

final class E2E04Test extends RouteTestCase
{
    public function test_ticket_to_opportunity_local_ownership_and_remote_failure_recovery(): void
    {
        [$origin, $sales, $revision] = $this->ticketFixture();
        $local = $this->routes()->spawn($this->manager(), 'ticket.sales-v1', $origin['instance_key'], 1, $sales['pipeline_key'], $revision['id'], 'main', 'refer', 'sales_opportunity_identified', 'local-spawn');
        $target = $this->instances()->get($this->manager('b'), $local['destination_key']);
        self::assertSame('crm.opportunity', $target['subject_type']);
        self::assertSame('referred', $this->instances()->get($this->manager(), $origin['instance_key'])['current_state_key']);
        $timeline = $this->cases()->timeline($this->manager('b'), $local['case_key']);
        self::assertNotEmpty($timeline);
        self::assertStringNotContainsString($origin['instance_key'], json_encode($timeline));
        self::assertStringNotContainsString('PRIVATE-HOST-TEXT', json_encode($timeline));
        $this->host('remote-ticket', 'support.ticket');
        $supportKey = DB::table('tm_pipelines')->where('id', $origin['pipeline_id'])->value('pipeline_key');
        $remoteOrigin = $this->instances()->start($this->manager(), 'support.ticket', 'remote-ticket', $revision['id'], 'remote-ticket-start', pipelineKey: $supportKey);
        $this->remoteRouter->failAfterCreate = true;
        $saga = $this->sagas()->begin($this->manager(), $remoteOrigin['instance_key'], 1, 'ticket.remote-v1', 'refer', 'sales_opportunity_identified', 'remote-spawn');
        self::assertSame('pending_external', $this->sagas()->execute($this->manager(), $saga['saga_key'])['state']);
        self::assertSame('new', $this->instances()->get($this->manager(), $remoteOrigin['instance_key'])['current_state_key']);
        self::assertSame(1, $this->remoteRouter->remote->table('remote_opportunities')->count());
        self::assertSame('confirmed', $this->sagas()->execute($this->manager(), $saga['saga_key'])['state']);
        self::assertSame('referred', $this->instances()->get($this->manager(), $remoteOrigin['instance_key'])['current_state_key']);
        self::assertSame(1, $this->remoteRouter->remote->table('remote_opportunities')->count());
        self::assertSame(1, DB::table('tm_subject_conversions')->count());
        self::assertSame(1, DB::table('tm_external_case_subjects')->count());
        self::assertSame(2, DB::table('tm_process_cases')->count());
    }
}
