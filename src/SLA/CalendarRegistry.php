<?php

namespace Karnoweb\TaskManager\SLA;

use Karnoweb\TaskManager\Contracts\WorkingCalendar;
use Karnoweb\TaskManager\Exceptions\DomainError;

final readonly class CalendarRegistry
{
    /** Calendar references must be versioned and immutable for historical reproducibility. */
    public function __construct(private array $calendars = [])
    {
        foreach ($calendars as $key => $calendar) { if (!is_string($key) || !$calendar instanceof WorkingCalendar) { throw new DomainError('calendar_registration_invalid'); } }
    }
    public function get(string $key): WorkingCalendar { return $this->calendars[$key] ?? throw new DomainError('calendar_unregistered'); }
}
