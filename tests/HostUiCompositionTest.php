<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\{Schema,DB};
use Illuminate\Database\Schema\Blueprint;
use Karnoweb\Kanban\Data\{BoardQuery,ActionCommand};
use Karnoweb\Kanban\Enums\{ActionType,ActionStatus};
use Karnoweb\TaskManager\Tests\Support\{PhaseSixOrder,PhaseSixDeal,PhaseSixBoardFactory};

final class HostUiCompositionTest extends PhaseSixTestCase
{
    public function test_real_livewire_board_renders_and_moves_the_same_backend_task(): void
    {
        $t=$this->service()->create($this->manager(),'Visible in Livewire','task');$board=$this->board();$this->app->bind(\Karnoweb\TaskManager\UI\Kanban\TaskBoard::class,fn()=>$board);
        $this->app->instance(\Karnoweb\Kanban\Contracts\ScopeResolver::class,new class implements \Karnoweb\Kanban\Contracts\ScopeResolver{public function resolve(string $boardKey,?string $instanceId=null):\Karnoweb\Kanban\Data\BoardContext{return new \Karnoweb\Kanban\Data\BoardContext($boardKey,'1','1',$instanceId??'host-board');}});
        $this->app->make(\Karnoweb\Kanban\Support\BoardRegistry::class)->register('my.tasks',PhaseSixBoardFactory::class);
        $component=\Livewire\Livewire::test(\Karnoweb\Kanban\Livewire\Board::class,['boardKey'=>'my.tasks'])->assertSee('Visible in Livewire');
        $component->call('moveCardToStage','tasks:'.$t['public_key'],'tasks:done');self::assertSame('done',$this->service()->get($this->manager(),$t['public_key'])['state']);self::assertSame(1,DB::table('tm_tasks')->count());
    }
    public function test_actual_calendar_dispatcher_composes_sources_without_cross_source_loss(): void
    {
        $t=$this->service()->create($this->manager(),'Due marker','task');$this->dates()->change($this->manager(),$t['public_key'],$t['version'],null,null,'2026-10-22 09:00:00','UTC',false,'reject','due');
        $calendar=$this->calendar();$this->app->bind(\Karnoweb\TaskManager\UI\Calendar\TaskCalendar::class,fn()=>$calendar);
        $def=(new \Karnoweb\Calendar\Support\CalendarBuilder)->key('my.calendar')->sources(['tm.due'=>\Karnoweb\TaskManager\UI\Calendar\DueEvents::class,'tm.schedule'=>\Karnoweb\TaskManager\UI\Calendar\ScheduleEvents::class,'tm.recurrence'=>\Karnoweb\TaskManager\UI\Calendar\RecurringEvents::class])->actions(\Karnoweb\TaskManager\UI\Calendar\TaskCalendar::class)->build();
        $q=new \Karnoweb\Calendar\Data\ReadRequest(\Karnoweb\Calendar\Data\DateRange::ofLocalDays('2026-10-22','2026-10-23','UTC'));
        $result=$this->app->make(\Karnoweb\Calendar\Actions\ReadDispatcher::class)->read($def,$this->uiCalendar(),$q);self::assertCount(1,$result->events);self::assertSame($t['public_key'],$result->events[0]->reference->eventId);
    }
    public function test_real_calendar_ui_iso_payload_preserves_the_requested_instant(): void
    {
        $t=$this->service()->create($this->manager(),'ISO move','create');$t=$this->dates()->change($this->manager(),$t['public_key'],$t['version'],'2026-10-22 09:00:00','2026-10-22 10:00:00',null,'UTC',false,'reject','span');
        $cmd=new \Karnoweb\Calendar\Data\ActionCommand('iso-move-1',\Karnoweb\Calendar\Enums\CalendarAction::Move,new \Karnoweb\Calendar\Data\EventReference('tm.schedule',$t['public_key']),['mode'=>'time','start'=>'2026-10-23T12:30:00+03:30','end'=>'2026-10-23T13:30:00+03:30','allDay'=>false,'timezone'=>'Asia/Tehran'],(string)$t['version']);
        self::assertTrue($this->calendar()->execute($cmd,$this->uiCalendar())->isSuccess());self::assertSame('2026-10-23 09:00:00.000000',$this->service()->get($this->manager(),$t['public_key'])['starts_at']);
    }
    public function test_native_crm_trait_board_calls_crm_service_without_creating_engine_state(): void
    {
        [$lead,$deal,$stages]=$this->crm();Schema::table('crm_deals',fn(Blueprint $t)=>$t->unsignedInteger('ui_version')->default(1));
        $this->app['config']->set('phase6.crm_stages',[(string)$stages[0]->id=>'Inbox',(string)$stages[1]->id=>'Review']);
        $this->app->make(\Karnoweb\Kanban\Models\KanbanModelRegistry::class)->register('deals',PhaseSixDeal::class);$this->app->make(\Karnoweb\Kanban\Support\BoardRegistry::class)->register('crm.native',PhaseSixBoardFactory::class);
        $ui=$this->uiBoard(key:'crm.native');$provider=$this->app->make(\Karnoweb\Kanban\Providers\EloquentKanbanDataProvider::class);$page=$provider->listCards($ui,new BoardQuery);self::assertCount(1,$page->items);
        $result=$this->app->make(\Karnoweb\Kanban\Actions\EloquentKanbanActionHandler::class)->execute(new ActionCommand('native-crm-move',ActionType::MoveCard,['cardId'=>'deals:'.$deal->id,'fromStageId'=>(string)$stages[0]->id,'toStageId'=>(string)$stages[1]->id],$page->items[0]->version),$ui);
        self::assertSame(ActionStatus::SUCCESS,$result->status,$result->messageCode??'');self::assertSame($stages[1]->id,$deal->fresh()->pipeline_stage_id);self::assertSame(1,\Karnoweb\Crm\Models\DealStageHistory::query()->count());self::assertSame(0,DB::table('tm_workflow_instances')->count());
    }
    public function test_order_uses_actual_model_trait_in_both_ui_packages_without_task_records(): void
    {
        Schema::create('phase_six_orders',function(Blueprint $t){$t->id();$t->string('title');$t->string('status');$t->string('scope_key');$t->string('owner_ref');$t->unsignedInteger('version')->default(1);$t->timestamp('delivery_at');$t->timestamps();});
        $order=PhaseSixOrder::create(['title'=>'Shipment','status'=>'new','scope_key'=>'1','owner_ref'=>'1','delivery_at'=>'2026-10-22 09:00:00']);
        $this->app->make(\Karnoweb\Kanban\Models\KanbanModelRegistry::class)->register('orders',PhaseSixOrder::class);$this->app->make(\Karnoweb\Kanban\Support\BoardRegistry::class)->register('host.orders',PhaseSixBoardFactory::class);
        $cards=$this->app->make(\Karnoweb\Kanban\Providers\EloquentKanbanDataProvider::class)->listCards($this->uiBoard(key:'host.orders'),new BoardQuery);self::assertCount(1,$cards->items);self::assertSame('orders:'.$order->id,$cards->items[0]->id);
        $this->app->make(\Karnoweb\Calendar\Support\CalendarModelRegistry::class)->register('orders',PhaseSixOrder::class);
        $provider=$this->app->make(\Karnoweb\Calendar\Providers\EloquentEventProvider::class,['sourceKey'=>'orders']);$q=new \Karnoweb\Calendar\Data\EventQuery(\Karnoweb\Calendar\Data\DateRange::ofLocalDays('2026-10-22','2026-10-23','UTC'));$page=$provider->fetch($q,$this->uiCalendar());self::assertCount(1,$page->events);self::assertTrue($page->events[0]->isReadOnly());self::assertSame(0,DB::table('tm_tasks')->count());
    }
}
