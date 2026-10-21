<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;

final class ExternalSagaTest extends RouteTestCase
{
    public function test_lost_remote_ack_retries_without_duplicate_or_false_local_success(): void
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

    public function test_remote_create_survives_local_failure_then_explicit_compensation_repairs_it(): void
    {
        [$origin] = $this->ticketFixture();
        $this->remoteRouter->onCreated = fn () => DB::table('host_subjects')->where('id', 'ticket-1')->update(['veto' => true]);
        $saga = $this->sagas()->begin($this->manager(), $origin['instance_key'], 1, 'ticket.remote-v1', 'refer', 'sales_opportunity_identified', 'saga-begin');
        $result = $this->sagas()->execute($this->manager(), $saga['saga_key']);
        self::assertSame('compensated', $result['state']);
        self::assertNull($result['confirmed_at']);
        self::assertSame('new', $this->instances()->get($this->manager(), $origin['instance_key'])['current_state_key']);
        self::assertSame(1, $this->remoteRouter->remote->table('remote_opportunities')->count());
        self::assertSame(1, (int) $this->remoteRouter->remote->table('remote_opportunities')->value('cancelled'));
        self::assertSame(0, DB::table('tm_external_case_subjects')->count());
        self::assertSame(0, DB::table('tm_process_cases')->count());
        self::assertSame(0, DB::table('tm_outbox')->where('action', 'external_spawn_confirmed')->count());
    }

    public function test_failed_compensation_has_durable_manual_repair_state(): void
    {
        [$origin] = $this->ticketFixture();
        $this->remoteRouter->canCompensate = false;
        $this->remoteRouter->onCreated = fn () => DB::table('host_subjects')->where('id', 'ticket-1')->update(['veto' => true]);
        $saga = $this->sagas()->begin($this->manager(), $origin['instance_key'], 1, 'ticket.remote-v1', 'refer', 'sales_opportunity_identified', 'saga-begin');
        $result = $this->sagas()->execute($this->manager(), $saga['saga_key']);
        self::assertSame('needs_manual_repair', $result['state']);
        self::assertNull($result['confirmed_at']);
        self::assertSame('new', $this->instances()->get($this->manager(), $origin['instance_key'])['current_state_key']);
        self::assertSame(0, (int) $this->remoteRouter->remote->table('remote_opportunities')->value('cancelled'));
        self::assertSame(1, DB::table('tm_outbox')->where('action', 'external_spawn_manual_repair')->count());
    }

    public function test_external_calls_are_rejected_inside_an_uncommitted_local_transaction(): void
    {
        [$origin] = $this->ticketFixture();
        $saga = $this->sagas()->begin($this->manager(), $origin['instance_key'], 1, 'ticket.remote-v1', 'refer', 'sales_opportunity_identified', 'saga-begin');
        try {
            DB::transaction(fn () => $this->sagas()->execute($this->manager(), $saga['saga_key']));
            self::fail();
        } catch (DomainError $e) {
            self::assertSame('external_action_requires_committed_boundary', $e->getMessage());
        }
        self::assertSame(0, $this->remoteRouter->remote->table('remote_opportunities')->count());
    }

    public function test_scope_and_current_source_permission_are_rechecked_before_external_create(): void
    {
        [$origin] = $this->ticketFixture();
        $saga = $this->sagas()->begin($this->manager(), $origin['instance_key'], 1, 'ticket.remote-v1', 'refer', 'sales_opportunity_identified', 'saga-begin');
        try {
            $this->sagas()->get($this->manager(scope: 'two'), $saga['saga_key']);
            self::fail();
        } catch (DomainError $e) {
            self::assertSame('not_found', $e->getMessage());
        }
        DB::table('host_permissions')->where('subject_id', 'ticket-1')->delete();
        try {
            $this->sagas()->execute($this->manager(), $saga['saga_key']);
            self::fail();
        } catch (DomainError $e) {
            self::assertSame('not_found', $e->getMessage());
        }
        self::assertSame(0, $this->remoteRouter->remote->table('remote_opportunities')->count());
    }

    public function test_manual_compensation_can_be_retried_with_current_version_and_owner_receipt(): void
    {
        [$origin] = $this->ticketFixture();
        $this->remoteRouter->canCompensate = false;
        $this->remoteRouter->onCreated = fn () => DB::table('host_subjects')->where('id', 'ticket-1')->update(['veto' => true]);
        $saga = $this->sagas()->begin($this->manager(), $origin['instance_key'], 1, 'ticket.remote-v1', 'refer', 'sales_opportunity_identified', 'saga-begin');
        $pending = $this->sagas()->execute($this->manager(), $saga['saga_key']);
        self::assertSame('needs_manual_repair', $pending['state']);
        self::assertSame(1, DB::table('tm_active_external_sagas')->count());
        $this->remoteRouter->canCompensate = true;
        $repaired = $this->sagas()->repair($this->manager(), $saga['saga_key'], $pending['version']);
        self::assertSame('compensated', $repaired['state']);
        self::assertSame(0, DB::table('tm_active_external_sagas')->count());
        self::assertSame(1, (int) $this->remoteRouter->remote->table('remote_opportunities')->value('cancelled'));
    }

    public function test_lost_receipt_with_changed_source_uses_durable_lookup_for_manual_repair(): void
    {
        [$origin] = $this->ticketFixture();
        $this->remoteRouter->failAfterCreate = true;
        $saga = $this->sagas()->begin($this->manager(), $origin['instance_key'], 1, 'ticket.remote-v1', 'refer', 'sales_opportunity_identified', 'saga-begin');
        $this->sagas()->execute($this->manager(), $saga['saga_key']);
        $this->instances()->transition($this->manager(), $origin['instance_key'], 1, 'source-update', 'review');
        $pending = $this->sagas()->execute($this->manager(), $saga['saga_key']);
        self::assertSame('needs_manual_repair', $pending['state']);
        self::assertSame('source_changed_before_retry', $pending['failure_code']);
        $repaired = $this->sagas()->repair($this->manager(), $saga['saga_key'], $pending['version']);
        self::assertSame('compensated', $repaired['state']);
        self::assertSame(1, $this->remoteRouter->remote->table('remote_opportunities')->count());
        self::assertSame(1, (int) $this->remoteRouter->remote->table('remote_opportunities')->value('cancelled'));
        self::assertSame('review', $this->instances()->get($this->manager(), $origin['instance_key'])['current_state_key']);
    }

    public function test_second_pending_saga_cannot_duplicate_origin_external_creation(): void
    {
        [$origin] = $this->ticketFixture();
        $first = $this->sagas()->begin($this->manager(), $origin['instance_key'], 1, 'ticket.remote-v1', 'refer', 'sales_opportunity_identified', 'first');
        self::assertSame($first, $this->sagas()->begin($this->manager(), $origin['instance_key'], 1, 'ticket.remote-v1', 'refer', 'sales_opportunity_identified', 'first'));
        try {
            $this->sagas()->begin($this->manager(), $origin['instance_key'], 1, 'ticket.remote-v1', 'refer', 'sales_opportunity_identified', 'second');
            self::fail();
        } catch (DomainError $e) {
            self::assertSame('external_action_pending', $e->getMessage());
        }
        self::assertSame(1, DB::table('tm_external_sagas')->count());
        self::assertSame(0, $this->remoteRouter->remote->table('remote_opportunities')->count());
    }

    public function test_recovery_scan_finishes_only_current_scope_pending_requests(): void
    {
        [$origin] = $this->ticketFixture();
        $this->sagas()->begin($this->manager(), $origin['instance_key'], 1, 'ticket.remote-v1', 'refer', 'sales_opportunity_identified', 'first');
        self::assertSame([], $this->sagas()->run($this->manager(scope: 'two')));
        self::assertSame(['confirmed' => 1], $this->sagas()->run($this->manager()));
        self::assertSame([], $this->sagas()->run($this->manager()));
    }

    public function test_invalid_remote_scope_is_manual_repair_and_never_confirmed(): void
    {
        [$origin] = $this->ticketFixture();
        $this->remoteRouter->receiptScope = 'two';
        $saga = $this->sagas()->begin($this->manager(), $origin['instance_key'], 1, 'ticket.remote-v1', 'refer', 'sales_opportunity_identified', 'first');
        $result = $this->sagas()->execute($this->manager(), $saga['saga_key']);
        self::assertSame('needs_manual_repair', $result['state']);
        self::assertSame('external_receipt_invalid', $result['failure_code']);
        self::assertSame(0, DB::table('tm_external_case_subjects')->count());
        self::assertSame(0, DB::table('tm_outbox')->where('action', 'external_spawn_confirmed')->count());
        self::assertSame('new', $this->instances()->get($this->manager(), $origin['instance_key'])['current_state_key']);
        self::assertSame(1, DB::table('tm_outbox')->where('action', 'external_spawn_manual_repair')->count());
    }

    public function test_private_export_is_rejected_before_saga_or_remote_effect_exists(): void
    {
        [$origin] = $this->ticketFixture();
        $this->remoteRouter->exportPrivate = true;
        try {
            $this->sagas()->begin($this->manager(), $origin['instance_key'], 1, 'ticket.remote-v1', 'refer', 'sales_opportunity_identified', 'first');
            self::fail();
        } catch (DomainError $e) {
            self::assertSame('external_export_invalid', $e->getMessage());
        }
        self::assertSame(0, DB::table('tm_external_sagas')->count());
        self::assertSame(0, $this->remoteRouter->remote->table('remote_opportunities')->count());
        self::assertStringNotContainsString('PRIVATE-HOST-TEXT', json_encode(DB::table('tm_outbox')->get()));
    }
}
