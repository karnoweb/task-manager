<?php

namespace Karnoweb\TaskManager\SLA;

use Karnoweb\TaskManager\Contracts\WorkingCalendar;
use Karnoweb\TaskManager\Exceptions\DomainError;

final readonly class WeeklyWorkingCalendar implements WorkingCalendar
{
    private \DateTimeZone $zone;

    /** @param array<int,list<array{0:string,1:string}>> $shifts ISO weekdays, local half-open time windows. */
    public function __construct(string $timezone, private array $shifts, private array $holidays = [])
    {
        if (! in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new DomainError('timezone_invalid');
        }
        try {
            $this->zone = new \DateTimeZone($timezone);
        } catch (\Exception) {
            throw new DomainError('timezone_invalid');
        }
        if (! $shifts) {
            throw new DomainError('working_calendar_empty');
        }
        foreach ($shifts as $day => $windows) {
            if (! is_int($day) || $day < 1 || $day > 7 || ! is_array($windows)) {
                throw new DomainError('working_calendar_invalid');
            }
            $previous = '00:00';
            foreach ($windows as $window) {
                if (! is_array($window) || count($window) !== 2 || ! is_string($window[0]) || ! is_string($window[1])
                    || ! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $window[0]) || ! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $window[1])
                    || $window[0] >= $window[1] || $window[0] < $previous) {
                    throw new DomainError('working_calendar_invalid');
                }
                $previous = $window[1];
            }
        }
        if (! array_filter($shifts)) {
            throw new DomainError('working_calendar_empty');
        }
        foreach ($holidays as $date) {
            $parsed = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
            if (! $parsed || $parsed->format('Y-m-d') !== $date) {
                throw new DomainError('holiday_invalid');
            }
        }
    }

    private function windows(\DateTimeImmutable $day): array
    {
        $date = $day->format('Y-m-d');
        if (in_array($date, $this->holidays, true)) {
            return [];
        }
        $result = [];
        foreach ($this->shifts[(int) $day->format('N')] ?? [] as [$start, $end]) {
            $a = new \DateTimeImmutable($date.' '.$start, $this->zone);
            $b = new \DateTimeImmutable($date.' '.$end, $this->zone);
            if ($a->format('Y-m-d H:i') !== $date.' '.$start || $b->format('Y-m-d H:i') !== $date.' '.$end) {
                throw new DomainError('calendar_local_time_invalid');
            }
            // Refuse ambiguous shift boundaries rather than silently selecting a DST fold.
            foreach ([$a, $b] as $boundary) {
                $epoch = $boundary->getTimestamp();
                $transitions = $this->zone->getTransitions($epoch - 172800, $epoch + 172800) ?: [];
                $offsets = array_unique(array_column($transitions, 'offset'));
                $differences = [];
                foreach ($offsets as $aOffset) {
                    foreach ($offsets as $bOffset) {
                        if ($aOffset !== $bOffset) {
                            $differences[] = $aOffset - $bOffset;
                        }
                    }
                }
                foreach (array_unique($differences) as $offset) {
                    if ((new \DateTimeImmutable('@'.($epoch + $offset)))->setTimezone($this->zone)->format('Y-m-d H:i') === $boundary->format('Y-m-d H:i')) {
                        throw new DomainError('calendar_local_time_ambiguous');
                    }
                }
            }
            $result[] = [$a->getTimestamp(), $b->getTimestamp()];
        }

        return $result;
    }

    public function addSeconds(\DateTimeImmutable $start, int $seconds): \DateTimeImmutable
    {
        if ($seconds < 0) {
            throw new DomainError('duration_invalid');
        }
        if ($seconds === 0) {
            return $start->setTimezone(new \DateTimeZone('UTC'));
        }
        $cursor = $start->getTimestamp();
        $day = $start->setTimezone($this->zone)->setTime(0, 0);
        for ($n = 0; $n < 3660; ++$n, $day = $day->modify('+1 day')) {
            foreach ($this->windows($day) as [$a, $b]) {
                $from = max($cursor, $a);
                if ($from >= $b) {
                    continue;
                }
                if ($seconds <= $b - $from) {
                    return (new \DateTimeImmutable('@'.($from + $seconds)))->setTimezone(new \DateTimeZone('UTC'));
                }
                $seconds -= $b - $from;
            }
        }
        throw new DomainError('calendar_horizon_exceeded');
    }

    public function secondsBetween(\DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        if ($end < $start) {
            throw new DomainError('clock_moved_backwards');
        }
        $day = $start->setTimezone($this->zone)->setTime(0, 0);
        $total = 0;
        for ($n = 0; $day->getTimestamp() < $end->getTimestamp(); ++$n, $day = $day->modify('+1 day')) {
            if ($n >= 3660) {
                throw new DomainError('calendar_horizon_exceeded');
            }
            foreach ($this->windows($day) as [$a, $b]) {
                $total += max(0, min($end->getTimestamp(), $b) - max($start->getTimestamp(), $a));
            }
        }

        return $total;
    }
}
