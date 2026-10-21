<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Contracts\ExportStorage;
use Karnoweb\TaskManager\Enterprise\ExportService;
use Karnoweb\TaskManager\Integration\AutomationService;
use Karnoweb\TaskManager\Integration\WebhookService;

final class EnterpriseProcessTest extends EnterpriseProcessTestCase
{
    public function test_real_handoff_return_triggers_guarded_automation_once_and_keeps_depth_lineage(): void
    {
        [$handoff,$origin,$destination] = $this->delegateFixture(false);
        $this->instances()->transition($this->manager(), $destination['instance_key'], 1, 'accept', 'accept');
        $this->handoffs()->settle($this->manager(), $handoff['handoff_key'], 1, 'settle');
        $event = DB::table('tm_outbox')->where('action', 'handoff_returned')->first();
        self::assertNotNull($event);
        $automation = $this->app->make(AutomationService::class);
        $automation->publish($this->manager(), null, 0, 'handoff_returned', ['max_depth' => 2, 'actions' => [['type' => 'transition', 'args' => ['edge' => 'approve', 'payload' => ['reason_code' => 'approved']]], ['type' => 'notify']]], 'rule');
        $result = $automation->run($this->worker(), $event->event_id);
        self::assertSame(['completed', 'completed'], array_column($result['actions'], 'state'));
        self::assertSame('won', $this->instances()->get($this->manager(), $origin['instance_key'])['current_state_key']);
        $events = DB::table('tm_outbox')->count();
        self::assertSame($result, $automation->run($this->worker(), $event->event_id));
        self::assertSame($events, DB::table('tm_outbox')->count());
        self::assertGreaterThan(0, DB::table('tm_event_lineage')->where('root_event_id', $event->event_id)->where('depth', 1)->count());
    }

    public function test_handoff_webhook_has_signed_scope_allowlisted_payload_and_no_host_private_content(): void
    {
        [$handoff,$origin,$destination] = $this->delegateFixture(false);
        $this->instances()->transition($this->manager(), $destination['instance_key'], 1, 'accept', 'accept');
        $this->handoffs()->settle($this->manager(), $handoff['handoff_key'], 1, 'settle');
        $event = DB::table('tm_outbox')->where('action', 'handoff_returned')->first();
        $webhooks = $this->app->make(WebhookService::class);
        $webhooks->create($this->manager(), 'https://hooks.example.com/events', 'fixture-secret', ['handoff_returned'], 'endpoint');
        self::assertSame(1, $webhooks->queue($this->manager(), $event->event_id));
        self::assertSame(1, $webhooks->run($this->worker())['delivered']);
        $request = $this->network->requests[0];
        $body = json_decode($request['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('one', $body['scope_key']);
        self::assertSame($handoff['handoff_key'], $body['data']['handoff_key']);
        self::assertStringNotContainsString('PRIVATE-HOST-TEXT', $request['body']);
        self::assertStringContainsString('X-TaskManager-Signature: sha256=', implode('\n', $request['headers']));
    }

    public function test_group_closure_export_filters_items_and_rechecks_external_host_revocation(): void
    {
        [$group,$instances] = $this->campaignFixture(3);
        $this->closeCampaign();
        $preview = $this->groupService()->previewClosure($this->manager(), $group['group_key']);
        $run = $this->groupService()->executeClosure($this->manager(), $preview, 'close');
        $this->processAll($run['run_key']);
        DB::table('host_permissions')->where('subject_id', 'lead-1')->where('actor', 'a')->delete();
        DB::table('host_subjects')->where('id', 'lead-0')->update(['id' => '=FORMULA()']);
        DB::table('host_permissions')->where('subject_id', 'lead-0')->update(['subject_id' => '=FORMULA()']);
        DB::table('tm_workflow_instances')->where('id', $instances[0]['id'])->update(['subject_id' => '=FORMULA()']);
        $exports = $this->app->make(ExportService::class);
        $definition = ['source' => 'group_closure', 'group_key' => $group['group_key'], 'format' => 'csv', 'columns' => ['subject_id', 'result']];
        $csv = implode('', iterator_to_array($exports->stream($this->manager(), $definition)));
        self::assertStringContainsString("'=FORMULA()", $csv);
        self::assertStringNotContainsString('lead-1', $csv);
        self::assertStringContainsString('lead-2', $csv);
        $job = $exports->enqueue($this->manager(), $definition, 'export');
        $ready = $exports->build($this->worker(), $job['export_key'], 1);
        self::assertSame('ready', $ready['state']);
        DB::table('host_permissions')->where('subject_id', 'lead-2')->where('actor', 'a')->delete();
        $this->error('export_permission_revoked', fn () => $exports->download($this->manager(), $job['export_key']));
        $reference = DB::table('tm_exports')->where('id',$job['id'])->value('file_ref');
        $this->app->make(ExportStorage::class)->delete($this->manager(),$reference);
    }
}
