<?php

namespace Karnoweb\TaskManager\Tests\Support;

use Karnoweb\Kanban\Concerns\HasKanbanCards;

final class PhaseSixDeal extends \Karnoweb\Crm\Models\Deal
{
    use HasKanbanCards;
    protected $table='crm_deals';
    public static function kanbanDefinition(): \Karnoweb\Kanban\Models\KanbanModelDefinition
    {
        $stages=config('phase6.crm_stages',[]);$transitions=[];foreach(array_keys($stages) as $id){$transitions[(string)$id]=array_values(array_map('strval',array_diff(array_keys($stages),[$id])));}
        return \Karnoweb\Kanban\Models\KanbanModelDefinition::make('deals',static::class)->fields(['title'=>'id','stage'=>'pipeline_stage_id','version'=>'ui_version'])->stages($stages)->transitions($transitions)->searchable(['id'])
            ->visibleQuery(fn($q,$c)=>$q->where('branch_id',$c->scopeKey)->where('assigned_to',$c->actorId))
            ->authorize(fn($ability,$row,$c)=>$row!==null && (string)$row->branch_id===$c->scopeKey && (string)$row->assigned_to===$c->actorId)
            ->hook('before',function($row,$command,$context){if($command->type===\Karnoweb\Kanban\Enums\ActionType::MoveCard){$original=$row->pipeline_stage_id;$target=\Karnoweb\Crm\Models\PipelineStage::query()->findOrFail($command->payload['toStageId']);app(\Karnoweb\Crm\Services\DealService::class)->moveStage(\Karnoweb\Crm\Models\Deal::query()->findOrFail($row->id),$target,$context->actorId);$row->refresh();$row->setAttribute('pipeline_stage_id',$original);}});
    }
}
