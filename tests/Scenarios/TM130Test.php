<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Karnoweb\Crm\Models\DealStageHistory;
use Karnoweb\Kanban\Actions\EloquentKanbanActionHandler;
use Karnoweb\Kanban\Data\ActionCommand;
use Karnoweb\Kanban\Data\BoardQuery;
use Karnoweb\Kanban\Enums\ActionStatus;
use Karnoweb\Kanban\Enums\ActionType;
use Karnoweb\Kanban\Models\KanbanModelRegistry;
use Karnoweb\Kanban\Providers\EloquentKanbanDataProvider;
use Karnoweb\Kanban\Support\BoardRegistry;
use Karnoweb\TaskManager\Tests\PhaseSixTestCase;
use Karnoweb\TaskManager\Tests\Support\PhaseSixBoardFactory;
use Karnoweb\TaskManager\Tests\Support\PhaseSixDeal;

final class TM130Test extends PhaseSixTestCase
{
    public function test_scenario(): void
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
}
