<?php

namespace Karnoweb\TaskManager\UI\Calendar;

use Karnoweb\Calendar\Contracts\EventProvider;
use Karnoweb\Calendar\Data\{CalendarContext,EventQuery,EventPage,EventReference,CalendarEventData};

/** Calendar resolves a separate fixed provider per declared source. */
abstract class SourceEvents implements EventProvider
{
    protected const SOURCE='';
    public function __construct(private readonly TaskCalendar $calendar) {}
    public function fetch(EventQuery $query,CalendarContext $context): EventPage
    {
        return $this->calendar->fetch(new EventQuery($query->range,min(100,$query->limit),$query->cursor,$query->search,['sources'=>[static::SOURCE]],$query->requestKey),$context);
    }
    public function find(EventReference $reference,CalendarContext $context): ?CalendarEventData
    {
        return $reference->sourceKey===static::SOURCE?$this->calendar->find($reference,$context):null;
    }
}
