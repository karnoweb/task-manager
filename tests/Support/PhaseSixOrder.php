<?php

namespace Karnoweb\TaskManager\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Karnoweb\Kanban\Concerns\HasKanbanCards;
use Karnoweb\Calendar\Concerns\HasCalendarEvents;

/** Executable example host model. Its storage belongs to the host, not either UI package. */
final class PhaseSixOrder extends Model
{
    use HasKanbanCards, HasCalendarEvents;
    protected $table='phase_six_orders'; protected $guarded=[];
    public static function kanbanDefinition(): \Karnoweb\Kanban\Models\KanbanModelDefinition
    {
        return \Karnoweb\Kanban\Models\KanbanModelDefinition::make('orders',static::class)->fields(['stage'=>'status','version'=>'version'])->stages(['new'=>'New','sent'=>'Sent'])->transitions(['new'=>['sent']])
            ->visibleQuery(fn($q,$c)=>$q->where('scope_key',$c->scopeKey)->where('owner_ref',$c->actorId))->authorize(fn($ability,$row,$c)=>$row!==null && (string)$row->scope_key===$c->scopeKey && (string)$row->owner_ref===$c->actorId);
    }
    public static function calendarDefinition(): \Karnoweb\Calendar\Data\CalendarModelDefinition
    {
        return new \Karnoweb\Calendar\Data\CalendarModelDefinition(sourceKey:'orders',startField:'delivery_at',endField:null,scopeField:'scope_key',ownerField:'owner_ref',allDayField:null,descriptionField:null,locationField:null,categoryField:null,colorField:null,movableField:null,timezoneField:null,readOnly:true);
    }
}
