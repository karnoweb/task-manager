<?php

namespace Karnoweb\TaskManager\Scheduling;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\NotificationPreferences;
use Karnoweb\TaskManager\Exceptions\DomainError;

final readonly class DailyQuietHours implements NotificationPreferences
{
    public function __construct(private string $timezone = 'UTC', private ?string $start = null, private ?string $end = null, private array $channels = ['in_app'])
    {
        LocalTime::zone($timezone); if (($start === null) !== ($end === null)) { throw new DomainError('quiet_hours_invalid'); }
        foreach ([$start, $end] as $value) { if ($value !== null && !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $value)) { throw new DomainError('quiet_hours_invalid'); } }
        if ($start !== null && $start === $end) { throw new DomainError('quiet_hours_invalid'); }
    }
    public function nextAllowed(ExecutionContext $context, string $channel, \DateTimeImmutable $at): ?\DateTimeImmutable
    {
        if (!in_array($channel, $this->channels, true)) { return null; } if ($this->start === null) { return $at; }
        $local = $at->setTimezone(LocalTime::zone($this->timezone)); $time = $local->format('H:i'); $overnight = $this->start > $this->end;
        $quiet = $overnight ? $time >= $this->start || $time < $this->end : $time >= $this->start && $time < $this->end; if (!$quiet) { return $at; }
        $day = $overnight && $time >= $this->start ? $local->modify('+1 day')->format('Y-m-d') : $local->format('Y-m-d'); return LocalTime::resolve($day.' '.$this->end.':00', $this->timezone, 'latest');
    }
}
