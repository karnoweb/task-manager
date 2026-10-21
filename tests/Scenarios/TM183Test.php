<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\RouteTestCase;

final class TM183Test extends RouteTestCase
{
    public function test_tm183(): void
    {
        [$origin] = $this->ticketFixture();
        $this->remoteRouter->failAfterCreate = true;
        $saga = $this->sagas()->begin($this->manager(), $origin['instance_key'], 1, 'ticket.remote-v1', 'refer', 'sales_opportunity_identified', 'saga-begin');
        self::assertSame('pending_external', $saga['state']);
        $pending = $this->sagas()->execute($this->manager(), $saga['saga_key']);
        self::assertSame('pending_external', $pending['state']);
        self::assertSame(1, $this->remoteRouter->remote->table('remote_opportunities')->count());
        self::assertSame('new', $this->instances()->get($this->manager(), $origin['instance_key'])['current_state_key']);
        self::assertSame(0, DB::table('tm_external_case_subjects')->count());
        $confirmed = $this->sagas()->execute($this->manager(), $saga['saga_key']);
        self::assertSame('confirmed', $confirmed['state']);
        self::assertSame('referred', $this->instances()->get($this->manager(), $origin['instance_key'])['current_state_key']);
        self::assertSame(1, DB::table('tm_external_case_subjects')->count());
        self::assertSame(1, $this->remoteRouter->remote->table('remote_opportunities')->count());
        self::assertSame($confirmed, $this->sagas()->execute($this->manager(), $saga['saga_key']));
        self::assertSame(1, DB::table('tm_outbox')->where('action', 'external_spawn_confirmed')->count());
        self::assertArrayNotHasKey('prepared_payload', $confirmed);
        self::assertArrayNotHasKey('receipt', $confirmed);
    }
}
