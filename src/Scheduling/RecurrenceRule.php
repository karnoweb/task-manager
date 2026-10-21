<?php

namespace Karnoweb\TaskManager\Scheduling;

use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\SLA\CalendarRegistry;

/** Bounded RFC5545 profile plus a separate Persian month/year engine. Unsupported parts fail explicitly. */
final class RecurrenceRule
{
    private const DAYS = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];

    public static function parse(array|string $input): array
    {
        if (is_string($input)) {
            $parts = [];
            foreach (explode(';', preg_replace('/^RRULE:/', '', $input)) as $item) {
                $pair = explode('=', $item, 2);
                if (count($pair) !== 2 || isset($parts[$pair[0]])) {
                    throw new DomainError('recurrence_rule_invalid');
                } $parts[$pair[0]] = $pair[1];
            }
            if (array_diff(array_keys($parts), ['FREQ', 'INTERVAL', 'BYDAY', 'BYMONTHDAY', 'BYMONTH', 'COUNT', 'UNTIL', 'WKST'])) {
                throw new DomainError('recurrence_part_unsupported');
            }
            if (isset($parts['INTERVAL']) && ! ctype_digit($parts['INTERVAL'])) {
                throw new DomainError('recurrence_rule_invalid');
            }
            $input = ['frequency' => strtolower($parts['FREQ'] ?? ''), 'interval' => isset($parts['INTERVAL']) && ctype_digit($parts['INTERVAL']) ? (int) $parts['INTERVAL'] : 1,
                'weekdays' => isset($parts['BYDAY']) ? explode(',', $parts['BYDAY']) : [], 'week_start' => $parts['WKST'] ?? 'SA'];
            foreach (['BYMONTHDAY' => 'month_day', 'BYMONTH' => 'month', 'COUNT' => 'count'] as $a => $b) {
                if (isset($parts[$a])) {
                    if (! ctype_digit($parts[$a])) {
                        throw new DomainError('recurrence_rule_invalid');
                    } $input[$b] = (int) $parts[$a];
                }
            }
            if (isset($parts['UNTIL'])) {
                if (! preg_match('/^\d{8}T\d{6}Z$/D', $parts['UNTIL'])) {
                    throw new DomainError('recurrence_until_invalid');
                } $date = \DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $parts['UNTIL'], new \DateTimeZone('UTC'));
                if (! $date || $date->format('Ymd\THis\Z') !== $parts['UNTIL']) {
                    throw new DomainError('recurrence_until_invalid');
                } $input['until'] = $date->format(DATE_ATOM);
            }
        }
        if (array_diff(array_keys($input), ['frequency', 'interval', 'weekdays', 'week_start', 'month_day', 'month', 'count', 'until', 'missing_day', 'dst_policy', 'after_completion', 'calendar_ref', 'holiday_policy'])) {
            throw new DomainError('recurrence_rule_invalid');
        }
        $rule = ['frequency' => $input['frequency'] ?? '', 'interval' => $input['interval'] ?? 1, 'weekdays' => $input['weekdays'] ?? [], 'week_start' => $input['week_start'] ?? 'SA',
            'month_day' => $input['month_day'] ?? null, 'month' => $input['month'] ?? null, 'count' => $input['count'] ?? null, 'until' => $input['until'] ?? null,
            'missing_day' => $input['missing_day'] ?? 'skip', 'dst_policy' => $input['dst_policy'] ?? 'reject', 'after_completion' => $input['after_completion'] ?? false,
            'calendar_ref' => $input['calendar_ref'] ?? null, 'holiday_policy' => $input['holiday_policy'] ?? 'include'];
        if (! in_array($rule['frequency'], ['daily', 'weekly', 'monthly', 'yearly'], true) || ! is_int($rule['interval']) || $rule['interval'] < 1 || $rule['interval'] > 365 || ! is_array($rule['weekdays']) || ! array_is_list($rule['weekdays']) || count($rule['weekdays']) > 7 || array_filter($rule['weekdays'], fn ($day) => ! is_string($day)) || array_diff($rule['weekdays'], array_keys(self::DAYS)) || ! is_string($rule['week_start']) || ! isset(self::DAYS[$rule['week_start']])
            || ($rule['month_day'] !== null && (! is_int($rule['month_day']) || $rule['month_day'] < 1 || $rule['month_day'] > 31)) || ($rule['month'] !== null && (! is_int($rule['month']) || $rule['month'] < 1 || $rule['month'] > 12))
            || ($rule['count'] !== null && (! is_int($rule['count']) || $rule['count'] < 1 || $rule['count'] > 10000)) || ! in_array($rule['missing_day'], ['skip', 'last_day'], true) || ! in_array($rule['dst_policy'], ['reject', 'earliest', 'latest'], true) || ! is_bool($rule['after_completion']) || ! in_array($rule['holiday_policy'], ['include', 'skip'], true)
            || ($rule['calendar_ref'] !== null && (! is_string($rule['calendar_ref']) || strlen($rule['calendar_ref']) > 120 || trim($rule['calendar_ref']) === '')) || ($rule['holiday_policy'] === 'skip' && ! is_string($rule['calendar_ref']))) {
            throw new DomainError('recurrence_rule_invalid');
        }
        if (($rule['weekdays'] && $rule['frequency'] !== 'weekly') || ($rule['month_day'] !== null && ! in_array($rule['frequency'], ['monthly', 'yearly'], true)) || ($rule['month'] !== null && $rule['frequency'] !== 'yearly')) {
            throw new DomainError('recurrence_part_unsupported');
        }
        if ($rule['until'] !== null) {
            if (! is_string($rule['until']) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $rule['until'])) {
                throw new DomainError('recurrence_until_invalid');
            } LocalTime::date(substr($rule['until'], 0, 10));
            try {
                $rule['until'] = (new \DateTimeImmutable($rule['until']))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
            } catch (\Exception) {
                throw new DomainError('recurrence_until_invalid');
            }
        }
        if ($rule['count'] !== null && $rule['until'] !== null) {
            throw new DomainError('recurrence_count_until_conflict');
        }
        $rule['weekdays'] = array_values(array_unique($rule['weekdays']));

        return $rule;
    }

    public static function expand(string $system, string $anchor, string $timezone, array $rule, \DateTimeImmutable $from, \DateTimeImmutable $until, CalendarRegistry $calendars, int $limit = 100): array
    {
        if (! in_array($system, ['gregorian', 'jalali'], true) || $until <= $from || $until > $from->modify('+366 days') || $limit < 1 || $limit > 1000) {
            throw new DomainError('recurrence_horizon_invalid');
        }
        LocalTime::resolve($anchor, $timezone, $rule['dst_policy']);
        $zone = LocalTime::zone($timezone);
        $date = substr($anchor, 0, 10);
        $time = substr($anchor, 11);
        $start = new \DateTimeImmutable($date.'T12:00:00Z');
        $anchorParts = $system === 'jalali' ? PersianCalendar::parts($date) : [(int) $start->format('Y'), (int) $start->format('n'), (int) $start->format('j')];
        $weekStart = $start->modify('-'.(((int) $start->format('N') - self::DAYS[$rule['week_start']] + 7) % 7).' days');
        $count = 0;
        $result = [];
        $lastDate = $until->setTimezone($zone)->format('Y-m-d');
        $elapsed = 0;
        for ($day = $start; $day->format('Y-m-d') <= $lastDate; $day = $day->modify('+1 day'), ++$elapsed) {
            if ($elapsed > 36600) {
                throw new DomainError('recurrence_scan_limit');
            }
            $parts = $system === 'jalali' ? PersianCalendar::parts($day->format('Y-m-d')) : [(int) $day->format('Y'), (int) $day->format('n'), (int) $day->format('j')];
            $months = ($parts[0] - $anchorParts[0]) * 12 + $parts[1] - $anchorParts[1];
            $monthDays = $system === 'jalali' ? PersianCalendar::days($parts[0], $parts[1]) : (int) $day->format('t');
            $wantedDay = $rule['month_day'] ?? $anchorParts[2];
            if ($rule['missing_day'] === 'last_day') {
                $wantedDay = min($wantedDay, $monthDays);
            }
            $matches = match ($rule['frequency']) {
                'daily' => $elapsed % $rule['interval'] === 0,
                'weekly' => intdiv((int) $weekStart->diff($day)->days, 7) % $rule['interval'] === 0 && in_array((int) $day->format('N'), $rule['weekdays'] ? array_map(fn ($d) => self::DAYS[$d], $rule['weekdays']) : [(int) $start->format('N')], true),
                'monthly' => $months % $rule['interval'] === 0 && $parts[2] === $wantedDay,
                'yearly' => ($parts[0] - $anchorParts[0]) % $rule['interval'] === 0 && $parts[1] === ($rule['month'] ?? $anchorParts[1]) && $parts[2] === $wantedDay,
            };
            if (! $matches) {
                continue;
            }
            // A nonexistent Gregorian wall time is skipped by the RFC profile; explicit input dates still reject.
            try {
                $utc = LocalTime::resolve($day->format('Y-m-d').' '.$time, $timezone, $rule['dst_policy']);
            } catch (DomainError $error) {
                if ($error->getMessage() === 'local_time_nonexistent') {
                    continue;
                } throw $error;
            }
            if ($rule['until'] !== null && $utc > new \DateTimeImmutable($rule['until'])) {
                break;
            }
            if (++$count > ($rule['count'] ?? PHP_INT_MAX)) {
                break;
            }
            if ($utc < $from || $utc >= $until) {
                continue;
            }
            if ($rule['holiday_policy'] === 'skip') {
                $calendar = $calendars->get($rule['calendar_ref']);
                $a = LocalTime::resolve($day->format('Y-m-d').' 00:00:00', $timezone, 'earliest');
                $b = LocalTime::resolve($day->modify('+1 day')->format('Y-m-d').' 00:00:00', $timezone, 'earliest');
                if ($calendar->secondsBetween($a, $b) === 0) {
                    continue;
                }
            }
            $localKey = $system === 'jalali' ? sprintf('%04d-%02d-%02d', ...$parts) : $day->format('Y-m-d');
            $result[] = ['occurrence_key' => $system.':'.$localKey.'T'.$time, 'scheduled_at' => LocalTime::stamp($utc), 'local_at' => $day->format('Y-m-d').' '.$time];
            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }
}
