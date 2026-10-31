<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\Calendar\Data\DateRange;
use Karnoweb\Calendar\Data\EventQuery;
use Karnoweb\Calendar\Data\EventReference;
use Karnoweb\Calendar\Enums\CalendarAction;
use Karnoweb\Kanban\Data\ActionCommand;
use Karnoweb\Kanban\Data\BoardQuery;
use Karnoweb\Kanban\Enums\ActionStatus;
use Karnoweb\Kanban\Enums\ActionType;
use Karnoweb\TaskManager\UI\Surface;

final class UiIntegrationTest extends PhaseSixTestCase
{
    public function test_due_only_calendar_and_board_share_one_task_truth(): void
    {
        $t = $this->service()->create($this->manager(), 'Due only', 'create');
        $t = $this->dates()->change($this->manager(), $t['public_key'], $t['version'], null, null, '2026-10-22 09:00:00', 'UTC', false, 'reject', 'due');
        $q = new EventQuery(DateRange::ofLocalDays('2026-10-22', '2026-10-23', 'UTC'));
        $page = $this->calendar()->fetch($q, $this->uiCalendar());
        self::assertCount(1, $page->events);
        self::assertSame('tm.due', $page->events[0]->reference->sourceKey);
        self::assertSame($t['public_key'], $page->events[0]->reference->eventId);
        $card = $this->board()->card($this->uiBoard(), 'tasks:'.$t['public_key']);
        self::assertSame($t['public_key'], $card->metadata['task_key']);
        self::assertSame($t['due_at'], $card->dueAt);
        self::assertSame(1, DB::table('tm_tasks')->count());
    }

    public function test_board_transition_retry_conflict_and_revocation_use_real_domain_services(): void
    {
        $t = $this->service()->create($this->manager(), 'Move', 'create');
        $id = 'tasks:'.$t['public_key'];
        $board = $this->board();
        $ui = $this->uiBoard();
        $cmd = new ActionCommand('move-once', ActionType::MoveCard, ['cardId' => $id, 'toStageId' => 'tasks:done'], (string) $t['version']);
        self::assertSame(ActionStatus::SUCCESS, $board->execute($cmd, $ui)->status);
        self::assertSame(ActionStatus::SUCCESS, $board->execute($cmd, $ui)->status);
        self::assertSame('done', $this->service()->get($this->manager(), $t['public_key'])['state']);
        self::assertSame(1, DB::table('tm_instance_transitions')->count());
        self::assertSame(ActionStatus::CONFLICT, $board->execute(new ActionCommand('stale-move', ActionType::MoveCard, ['cardId' => $id, 'toStageId' => 'tasks:pending'], (string) $t['version']), $ui)->status);
        self::assertNull($board->card($this->uiBoard('2'), $id));
        self::assertNull($board->operationStatus($this->uiBoard('2'), 'move-once'));
    }

    public function test_calendar_move_resize_versions_and_read_only_sla_are_enforced(): void
    {
        $t = $this->service()->create($this->manager(), 'Span', 'create');
        $t = $this->dates()->change($this->manager(), $t['public_key'], $t['version'], '2026-10-22 09:00:00', '2026-10-22 10:00:00', null, 'UTC', false, 'reject', 'span');
        $ref = new EventReference('tm.schedule', $t['public_key']);
        $cal = $this->calendar();
        $ui = $this->uiCalendar();
        $cmd = new \Karnoweb\Calendar\Data\ActionCommand('move-once', CalendarAction::Move, $ref, ['start' => '2026-10-23 09:00:00', 'end' => '2026-10-23 10:00:00', 'timezone' => 'UTC'], (string) $t['version']);
        self::assertTrue($cal->execute($cmd, $ui)->isSuccess());
        self::assertTrue($cal->execute($cmd, $ui)->isSuccess());
        self::assertSame('2026-10-23 09:00:00.000000', $this->service()->get($this->manager(), $t['public_key'])['starts_at']);
        $readonly = new \Karnoweb\Calendar\Data\ActionCommand('sla-move-1', CalendarAction::Move, new EventReference('tm.sla', '1'), ['start' => '2026-10-23 09:00:00'], '1');
        self::assertSame(\Karnoweb\Calendar\Enums\ActionStatus::ReadOnly, $cal->execute($readonly, $ui)->status);
    }

    public function test_source_selection_empty_and_forged_cross_surface_ids_are_denied(): void
    {
        $t = $this->service()->create($this->manager(), 'Private', 'create');
        $empty = $this->board(new Surface('my.tasks', []));
        self::assertSame(0, $empty->listCards($this->uiBoard(), new BoardQuery)->totalCount);
        $this->error('not_found', fn () => $empty->card($this->uiBoard(), 'tasks:'.$t['public_key']));
        $this->error('not_found', fn () => $this->board()->board($this->uiBoard(key: 'other.board')));
        $q = new EventQuery(DateRange::ofLocalDays('2026-10-22', '2026-10-23', 'UTC'), filters: ['sources' => ['tm.sla']]);
        $this->error('not_found', fn () => $this->calendar(new Surface('my.calendar', ['tm.due']))->fetch($q, $this->uiCalendar()));
    }

    public function test_board_cursor_is_bound_to_actor_stage_sources_and_query(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->service()->create($this->manager(), 'Task '.$i, 'create-'.$i);
        }
        $b = $this->board();
        $q = new BoardQuery(limit: 1);
        $p = $b->listCards($this->uiBoard(), $q);
        self::assertTrue($p->hasMore);
        self::assertSame(3, $p->totalCount);
        $p2 = $b->listCards($this->uiBoard(), $q->withCursor($p->nextCursor));
        self::assertNotSame($p->items[0]->id, $p2->items[0]->id);
        $this->error('cursor_invalid', fn () => $b->listCards($this->uiBoard('2'), $q->withCursor($p->nextCursor)));
        $this->error('cursor_invalid', fn () => $b->stageCards($this->uiBoard(), 'tasks:done', $q->withCursor($p->nextCursor)));
    }

    public function test_recurring_query_is_read_only_stable_and_occurrence_safe_across_pages(): void
    {
        $t = $this->service()->create($this->manager(), 'Recurring', 'create');
        $t = $this->dates()->change($this->manager(), $t['public_key'], $t['version'], '2026-10-22 09:00:00', '2026-10-22 10:00:00', null, 'UTC', false, 'reject', 'span');
        $s = $this->recurrence()->create($this->manager(), $t['public_key'], $t['version'], 'gregorian', '2026-10-22 09:00:00', 'UTC', 'FREQ=DAILY;COUNT=3', 'series');
        $q = new EventQuery(DateRange::ofLocalDays('2026-10-22', '2026-10-26', 'UTC'), limit: 1);
        $cal = $this->calendar();
        $ui = $this->uiCalendar();
        $ids = [];
        do {
            $p = $cal->fetch($q, $ui);
            foreach ($p->events as $event) {
                $ids[] = $event->reference->occurrenceKey;
                self::assertSame($s['series_key'], $event->reference->eventId);
                self::assertTrue($event->isReadOnly());
            }$q = $q->withCursor($p->nextCursor);
        } while ($p->hasMore);
        self::assertCount(3, array_unique($ids));
        self::assertSame(1, DB::table('tm_tasks')->count());
    }

    public function test_drawer_ignores_supplied_private_card_and_rechecks_permission(): void
    {
        $t = $this->service()->create($this->manager(), 'Private', 'create');
        $b = $this->board();
        $c = $b->card($this->uiBoard(), 'tasks:'.$t['public_key']);
        self::assertCount(3, $b->sections($this->uiBoard(), $c));
        foreach (['activity', 'sla', 'handoffs'] as $section) {
            $page = $b->loadSection($this->uiBoard(), $c, $section, null, 10);
            self::assertTrue($page->canView());
            self::assertIsArray($page->items);
        }
        $this->error('not_found', fn () => $b->loadSection($this->uiBoard('2'), $c, 'activity', null, 10));
    }
}
