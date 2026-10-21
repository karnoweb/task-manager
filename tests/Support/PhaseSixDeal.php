<?php

namespace Karnoweb\TaskManager\Tests\Support;

use Karnoweb\Crm\Models\Deal;
use Karnoweb\Crm\Models\PipelineStage;
use Karnoweb\Crm\Services\DealService;
use Karnoweb\Kanban\Concerns\HasKanbanCards;
use Karnoweb\Kanban\Enums\ActionType;
use Karnoweb\Kanban\Models\KanbanModelDefinition;

final class PhaseSixDeal extends Deal
{
    use HasKanbanCards;

    protected $table = 'crm_deals';

    public static function kanbanDefinition(): KanbanModelDefinition
    {
        $stages = config('phase6.crm_stages', []);
        $transitions = [];
        foreach (array_keys($stages) as $id) {
            $transitions[(string) $id] = array_values(array_map('strval', array_diff(array_keys($stages), [$id])));
        }

        return KanbanModelDefinition::make('deals', self::class)->fields(['title' => 'id', 'stage' => 'pipeline_stage_id', 'version' => 'ui_version'])->stages($stages)->transitions($transitions)->searchable(['id'])
            ->visibleQuery(fn ($q, $c) => $q->where('branch_id', $c->scopeKey)->where('assigned_to', $c->actorId))
            ->authorize(fn ($ability, $row, $c) => $row !== null && (string) $row->branch_id === $c->scopeKey && (string) $row->assigned_to === $c->actorId)
            ->hook('before', function ($row, $command, $context) {
                if ($command->type === ActionType::MoveCard) {
                    $original = $row->pipeline_stage_id;
                    $target = PipelineStage::query()->findOrFail($command->payload['toStageId']);
                    app(DealService::class)->moveStage(Deal::query()->findOrFail($row->id), $target, $context->actorId);
                    $row->refresh();
                    $row->setAttribute('pipeline_stage_id', $original);
                }
            });
    }
}
