<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\Crm\Models\DealStageHistory;
use Karnoweb\Kanban\Data\ActionCommand;
use Karnoweb\Kanban\Enums\ActionStatus;
use Karnoweb\Kanban\Enums\ActionType;
use Karnoweb\TaskManager\Handoff\HandoffService;
use Karnoweb\TaskManager\Tests\PhaseSixTestCase;
use Karnoweb\TaskManager\UI\Surface;

final class TM211Test extends PhaseSixTestCase
{
    public function test_scenario(): void
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
        self::assertSame(0,DealStageHistory::query()->count());
    }
}
