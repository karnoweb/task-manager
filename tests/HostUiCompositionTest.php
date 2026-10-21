<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Karnoweb\Calendar\Actions\ReadDispatcher;
use Karnoweb\Calendar\Data\DateRange;
use Karnoweb\Calendar\Data\EventQuery;
use Karnoweb\Calendar\Data\EventReference;
use Karnoweb\Calendar\Data\ReadRequest;
use Karnoweb\Calendar\Enums\CalendarAction;
use Karnoweb\Calendar\Providers\EloquentEventProvider;
use Karnoweb\Calendar\Support\CalendarBuilder;
use Karnoweb\Calendar\Support\CalendarModelRegistry;
use Karnoweb\Crm\Models\DealStageHistory;
use Karnoweb\Kanban\Actions\EloquentKanbanActionHandler;
use Karnoweb\Kanban\Contracts\ScopeResolver;
use Karnoweb\Kanban\Data\ActionCommand;
use Karnoweb\Kanban\Data\BoardContext;
use Karnoweb\Kanban\Data\BoardQuery;
use Karnoweb\Kanban\Enums\ActionStatus;
use Karnoweb\Kanban\Enums\ActionType;
use Karnoweb\Kanban\Livewire\Board;
use Karnoweb\Kanban\Models\KanbanModelRegistry;
use Karnoweb\Kanban\Providers\EloquentKanbanDataProvider;
use Karnoweb\Kanban\Support\BoardRegistry;
use Karnoweb\TaskManager\Tests\Support\PhaseSixBoardFactory;
use Karnoweb\TaskManager\Tests\Support\PhaseSixDeal;
use Karnoweb\TaskManager\Tests\Support\PhaseSixOrder;
use Karnoweb\TaskManager\UI\Calendar\DueEvents;
use Karnoweb\TaskManager\UI\Calendar\RecurringEvents;
use Karnoweb\TaskManager\UI\Calendar\ScheduleEvents;
use Karnoweb\TaskManager\UI\Calendar\TaskCalendar;
use Karnoweb\TaskManager\UI\Kanban\TaskBoard;
use Livewire\Livewire;

final class HostUiCompositionTest extends PhaseSixTestCase
{
    public function test_real_livewire_board_renders_and_moves_the_same_backend_task(): void
    {
        $t = $this->service()->create($this->manager(), 'Visible in Livewire', 'task');
        $board = $this->board();
        $this->app->bind(TaskBoard::class, fn () => $board);
        $this->app->instance(ScopeResolver::class, new class implements ScopeResolver
        {
            public function resolve(string $boardKey, ?string $instanceId = null): BoardContext
            {
                return new BoardContext($boardKey, '1', '1', $instanceId ?? 'host-board');
            }
        });
        $this->app->make(BoardRegistry::class)->register('my.tasks', PhaseSixBoardFactory::class);
        $component = Livewire::test(Board::class, ['boardKey' => 'my.tasks'])->assertSee('Visible in Livewire');
        $component->call('moveCardToStage', 'tasks:'.$t['public_key'], 'tasks:done');
        self::assertSame('done', $this->service()->get($this->manager(), $t['public_key'])['state']);
        self::assertSame(1, DB::table('tm_tasks')->count());
    }

    public function test_actual_calendar_dispatcher_composes_sources_without_cross_source_loss(): void
    {
        $t = $this->service()->create($this->manager(), 'Due marker', 'task');
        $this->dates()->change($this->manager(), $t['public_key'], $t['version'], null, null, '2026-10-22 09:00:00', 'UTC', false, 'reject', 'due');
        $calendar = $this->calendar();
        $this->app->bind(TaskCalendar::class, fn () => $calendar);
        $def = (new CalendarBuilder)->key('my.calendar')->sources(['tm.due' => DueEvents::class, 'tm.schedule' => ScheduleEvents::class, 'tm.recurrence' => RecurringEvents::class])->actions(TaskCalendar::class)->build();
        $q = new ReadRequest(DateRange::ofLocalDays('2026-10-22', '2026-10-23', 'UTC'));
        $result = $this->app->make(ReadDispatcher::class)->read($def, $this->uiCalendar(), $q);
        self::assertCount(1, $result->events);
        self::assertSame($t['public_key'], $result->events[0]->reference->eventId);
    }

    public function test_real_calendar_ui_iso_payload_preserves_the_requested_instant(): void
    {
        $t = $this->service()->create($this->manager(), 'ISO move', 'create');
        $t = $this->dates()->change($this->manager(), $t['public_key'], $t['version'], '2026-10-22 09:00:00', '2026-10-22 10:00:00', null, 'UTC', false, 'reject', 'span');
        $cmd = new \Karnoweb\Calendar\Data\ActionCommand('iso-move-1', CalendarAction::Move, new EventReference('tm.schedule', $t['public_key']), ['mode' => 'time', 'start' => '2026-10-23T12:30:00+03:30', 'end' => '2026-10-23T13:30:00+03:30', 'allDay' => false, 'timezone' => 'Asia/Tehran'], (string) $t['version']);
        self::assertTrue($this->calendar()->execute($cmd, $this->uiCalendar())->isSuccess());
        self::assertSame('2026-10-23 09:00:00.000000', $this->service()->get($this->manager(), $t['public_key'])['starts_at']);
    }

    public function test_native_crm_trait_board_calls_crm_service_without_creating_engine_state(): void
    {
        [$lead,$deal,$stages] = $this->crm();
        Schema::table('crm_deals', fn (Blueprint $t) => $t->unsignedInteger('ui_version')->default(1));
        $this->app['config']->set('phase6.crm_stages', [(string) $stages[0]->id => 'Inbox', (string) $stages[1]->id => 'Review']);
        $this->app->make(KanbanModelRegistry::class)->register('deals', PhaseSixDeal::class);
        $this->app->make(BoardRegistry::class)->register('crm.native', PhaseSixBoardFactory::class);
        $ui = $this->uiBoard(key: 'crm.native');
        $provider = $this->app->make(EloquentKanbanDataProvider::class);
        $page = $provider->listCards($ui, new BoardQuery);
        self::assertCount(1, $page->items);
        $result = $this->app->make(EloquentKanbanActionHandler::class)->execute(new ActionCommand('native-crm-move', ActionType::MoveCard, ['cardId' => 'deals:'.$deal->id, 'fromStageId' => (string) $stages[0]->id, 'toStageId' => (string) $stages[1]->id], $page->items[0]->version), $ui);
        self::assertSame(ActionStatus::SUCCESS, $result->status, $result->messageCode ?? '');
        self::assertSame($stages[1]->id, $deal->fresh()->pipeline_stage_id);
        self::assertSame(1, DealStageHistory::query()->count());
        self::assertSame(0, DB::table('tm_workflow_instances')->count());
    }

    public function test_order_uses_actual_model_trait_in_both_ui_packages_without_task_records(): void
    {
        Schema::create('phase_six_orders', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->string('status');
            $t->string('scope_key');
            $t->string('owner_ref');
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('delivery_at');
            $t->timestamps();
        });
        $order = PhaseSixOrder::create(['title' => 'Shipment', 'status' => 'new', 'scope_key' => '1', 'owner_ref' => '1', 'delivery_at' => '2026-10-22 09:00:00']);
        $this->app->make(KanbanModelRegistry::class)->register('orders', PhaseSixOrder::class);
        $this->app->make(BoardRegistry::class)->register('host.orders', PhaseSixBoardFactory::class);
        $cards = $this->app->make(EloquentKanbanDataProvider::class)->listCards($this->uiBoard(key: 'host.orders'), new BoardQuery);
        self::assertCount(1, $cards->items);
        self::assertSame('orders:'.$order->id, $cards->items[0]->id);
        $this->app->make(CalendarModelRegistry::class)->register('orders', PhaseSixOrder::class);
        $provider = $this->app->make(EloquentEventProvider::class, ['sourceKey' => 'orders']);
        $q = new EventQuery(DateRange::ofLocalDays('2026-10-22','2026-10-23','UTC'));
        $page = $provider->fetch($q,$this->uiCalendar());
        self::assertCount(1,$page->events);
        self::assertTrue($page->events[0]->isReadOnly());
        self::assertSame(0,DB::table('tm_tasks')->count());
    }
}
