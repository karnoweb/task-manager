<?php

namespace Karnoweb\TaskManager\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Karnoweb\Calendar\Concerns\HasCalendarEvents;
use Karnoweb\Calendar\Data\CalendarModelDefinition;
use Karnoweb\Kanban\Concerns\HasKanbanCards;
use Karnoweb\Kanban\Models\KanbanModelDefinition;

/** Executable example host model. Its storage belongs to the host, not either UI package. */
final class PhaseSixOrder extends Model
{
    use HasCalendarEvents, HasKanbanCards;

    protected $table = 'phase_six_orders';

    protected $guarded = [];

    public static function kanbanDefinition(): KanbanModelDefinition
    {
        return KanbanModelDefinition::make('orders', self::class)->fields(['stage' => 'status', 'version' => 'version'])->stages(['new' => 'New', 'sent' => 'Sent'])->transitions(['new' => ['sent']])
            ->visibleQuery(fn ($q, $c) => $q->where('scope_key', $c->scopeKey)->where('owner_ref', $c->actorId))->authorize(fn ($ability, $row, $c) => $row !== null && (string) $row->scope_key === $c->scopeKey && (string) $row->owner_ref === $c->actorId);
    }

    public static function calendarDefinition(): CalendarModelDefinition
    {
        return new CalendarModelDefinition(sourceKey: 'orders', startField: 'delivery_at', endField: null, scopeField: 'scope_key', ownerField: 'owner_ref', allDayField: null, descriptionField: null, locationField: null, categoryField: null, colorField: null, movableField: null, timezoneField: null, readOnly: true);
    }
}
