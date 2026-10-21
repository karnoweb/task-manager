<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\Calendar\Data\DateRange;
use Karnoweb\Calendar\Data\EventQuery;
use Karnoweb\Crm\Enums\TaskStatus;
use Karnoweb\Crm\Models\DealStageHistory;
use Karnoweb\Kanban\Data\ActionCommand;
use Karnoweb\Kanban\Data\BoardQuery;
use Karnoweb\Kanban\Enums\ActionStatus;
use Karnoweb\Kanban\Enums\ActionType;
use Karnoweb\TaskManager\Handoff\HandoffService;
use Karnoweb\TaskManager\UI\Surface;

final class UiProcessSafetyTest extends PhaseSixTestCase
{
    public function test_mixed_task_and_real_opportunity_board_keeps_sources_and_current_permissions_separate(): void
    {
        [$lead,$deal] = $this->crm();
        $r = $this->sales();
        $p = $this->pipeline($r['id']);
        $i = $this->instances()->start($this->manager(), 'crm.deal', (string) $deal->id, $r['id'], 'start', pipelineKey: $p['pipeline_key']);
        $task = $this->service()->create($this->manager(), 'Follow-up', 'task');
        $b = $this->board(new Surface('my.tasks', ['tasks', 'crm.deal'], ['crm.deal' => $p['pipeline_key']]));
        $page = $b->listCards($this->uiBoard(), new BoardQuery);
        self::assertCount(2, $page->items);
        self::assertSame(2, $b->counts($this->uiBoard(), new BoardQuery)->boardTotal);
        $deal->forceFill(['assigned_to' => 2])->save();
        $page = $b->listCards($this->uiBoard(), new BoardQuery);
        self::assertCount(1, $page->items);
        self::assertSame('tasks:'.$task['public_key'], $page->items[0]->id);
        self::assertNull($b->card($this->uiBoard(), 'crm.deal:'.$i['instance_key']));
        $this->error('not_found', fn () => $this->board(new Surface('my.tasks', ['tasks']))->card($this->uiBoard(), 'crm.deal:'.$i['instance_key']));
    }

    public function test_contract_stage_drag_calls_the_trusted_handoff_handler_once_without_direct_status_write(): void
    {
        [$lead,$deal] = $this->crm();
        $r = $this->sales();
        $p = $this->pipeline($r['id']);
        $i = $this->instances()->start($this->manager(), 'crm.deal', (string) $deal->id, $r['id'], 'start', pipelineKey: $p['pipeline_key']);
        $calls = 0;
        $destination = $this->pipelines()->create($this->manager(), 'Contract', $r['id'], ['inbox' => ['state' => 'new'], 'review' => ['state' => 'review'], 'won' => ['state' => 'won']], 'contract-pipeline');
        $surface = new Surface('my.tasks', ['crm.deal'], ['crm.deal' => $p['pipeline_key']], ['crm.deal:review' => 'contract-route-v1']);
        $handler = function ($c, $source, $key, $version, $operation, $route, $payload) use (&$calls, $destination, $r) {
            $calls++;
            self::assertSame('contract-route-v1', $route);
            self::assertSame('crm.deal', $source);

            return $this->app->make(HandoffService::class)->delegate($c, $key, $version, $destination['pipeline_key'], $r['id'], 'contract', ['reason_code' => 'contract_review', 'target_team' => 'contracts', 'returns' => ['approved' => ['transition' => 'review']]], $operation);
        };
        $b = $this->board($surface, $handler);
        $command = new ActionCommand('route-once', ActionType::MoveCard, ['cardId' => 'crm.deal:'.$i['instance_key'], 'toStageId' => 'crm.deal:review'], '1');
        self::assertSame(ActionStatus::SUCCESS, $b->execute($command, $this->uiBoard())->status);
        self::assertSame(ActionStatus::SUCCESS, $b->execute($command, $this->uiBoard())->status);
        self::assertSame(1, $calls);
        self::assertSame('waiting', $this->instances()->get($this->manager(), $i['instance_key'])['lifecycle']);
        self::assertSame(1, DB::table('tm_handoffs')->count());
        self::assertSame(0, DealStageHistory::query()->count());
    }

    public function test_calendar_sla_marker_and_virtual_recurrence_details_have_stable_read_only_identity(): void
    {
        [$lead,$deal] = $this->crm();
        $r = $this->sales();
        $p = $this->pipeline($r['id']);
        $i = $this->instances()->start($this->manager(), 'crm.deal', (string) $deal->id, $r['id'], 'start', pipelineKey: $p['pipeline_key']);
        $visit = $this->instances()->visits($this->manager(), $i['instance_key'])[0];
        DB::table('tm_stage_visits')->where('id', $visit['id'])->update(['deadline_at' => '2026-10-22 08:00:00']);
        $t = $this->service()->create($this->manager(), 'Recurring', 'task');
        $t = $this->dates()->change($this->manager(), $t['public_key'], $t['version'], null, null, '2026-10-22 09:00:00', 'UTC', false, 'reject', 'due');
        $s = $this->recurrence()->create($this->manager(), $t['public_key'], $t['version'], 'gregorian', '2026-10-22 09:00:00', 'UTC', 'FREQ=DAILY;COUNT=2', 'series');
        $q = new EventQuery(DateRange::ofLocalDays('2026-10-22', '2026-10-24', 'UTC'));
        $cal = $this->calendar();
        $page = $cal->fetch($q, $this->uiCalendar());
        self::assertCount(3, $page->events);
        foreach ($page->events as $event) {
            self::assertTrue($event->isReadOnly());
            $found = $cal->find($event->reference, $this->uiCalendar());
            self::assertNotNull($found);
            self::assertTrue($found->reference->equals($event->reference));
        }
        self::assertSame(1, DB::table('tm_tasks')->count());
    }

    public function test_completed_legacy_tasks_keep_original_history_and_subject_link_evidence(): void
    {
        [$legacy,$b,$deal] = $this->migrationTasks();
        $legacy->forceFill(['status' => TaskStatus::Done, 'completed_at' => '2026-10-20 15:00:00', 'outcome' => 'Spoke to customer'])->save();
        $snapshot = $legacy->fresh()->getAttributes();
        $this->cutover()->importBatch($this->manager(), 'crm.tasks', 1, 0, 'import');
        $shadow = $this->cutover()->shadow($this->manager(), 'crm.tasks', (string) $legacy->id);
        self::assertSame('done', $shadow['engine']['instance']['current_state_key']);
        self::assertSame('2026-10-20 15:00:00', $shadow['engine']['instance']['completed_at']);
        self::assertSame($snapshot, $legacy->fresh()->getAttributes());
        $evidence = json_decode(DB::table('tm_host_imports')->first()->source_snapshot, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('crm_deal', $evidence['subject_type']);
        self::assertSame($deal->id, $evidence['subject_id']);
        self::assertSame('Spoke to customer', $evidence['outcome']);
    }

    public function test_reimport_and_cutover_cannot_hide_missing_or_deleted_legacy_rows(): void
    {
        [$legacy,$b] = $this->migrationTasks();
        $this->cutover()->importBatch($this->manager(), 'crm.tasks', 1, 0, 'import');
        DB::table('crm_followups')->where('id',$legacy->id)->delete();
        $result = $this->cutover()->reconcile($this->manager(),'crm.tasks');
        self::assertFalse($result['consistent']);
        self::assertContains('inventory_count',$result['problems']);
        $this->error('reconciliation_required',fn () => $this->cutover()->switch($this->manager(),'crm.tasks',1,'active','cutover'));
    }
}
