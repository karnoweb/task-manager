<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\PhaseSixTestCase;
use Illuminate\Support\Facades\{Schema,DB};
use Illuminate\Database\Schema\Blueprint;
use Karnoweb\Kanban\Data\{BoardQuery,ActionCommand};
use Karnoweb\Kanban\Enums\{ActionType,ActionStatus};
use Karnoweb\TaskManager\Tests\Support\{PhaseSixOrder,PhaseSixDeal,PhaseSixBoardFactory};

final class TM130Test extends PhaseSixTestCase
{
    public function test_scenario(): void
    {
        [$lead,$deal,$stages]=$this->crm();Schema::table('crm_deals',fn(Blueprint $t)=>$t->unsignedInteger('ui_version')->default(1));
        $this->app['config']->set('phase6.crm_stages',[(string)$stages[0]->id=>'Inbox',(string)$stages[1]->id=>'Review']);
        $this->app->make(\Karnoweb\Kanban\Models\KanbanModelRegistry::class)->register('deals',PhaseSixDeal::class);$this->app->make(\Karnoweb\Kanban\Support\BoardRegistry::class)->register('crm.native',PhaseSixBoardFactory::class);
        $ui=$this->uiBoard(key:'crm.native');$provider=$this->app->make(\Karnoweb\Kanban\Providers\EloquentKanbanDataProvider::class);$page=$provider->listCards($ui,new BoardQuery);self::assertCount(1,$page->items);
        $result=$this->app->make(\Karnoweb\Kanban\Actions\EloquentKanbanActionHandler::class)->execute(new ActionCommand('native-crm-move',ActionType::MoveCard,['cardId'=>'deals:'.$deal->id,'fromStageId'=>(string)$stages[0]->id,'toStageId'=>(string)$stages[1]->id],$page->items[0]->version),$ui);
        self::assertSame(ActionStatus::SUCCESS,$result->status,$result->messageCode??'');self::assertSame($stages[1]->id,$deal->fresh()->pipeline_stage_id);self::assertSame(1,\Karnoweb\Crm\Models\DealStageHistory::query()->count());self::assertSame(0,DB::table('tm_workflow_instances')->count());
    }
}
