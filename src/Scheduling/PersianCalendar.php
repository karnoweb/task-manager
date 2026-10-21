<?php

namespace Karnoweb\TaskManager\Scheduling;

use Karnoweb\TaskManager\Exceptions\DomainError;

/** Persian calendar truth is delegated to ICU, never to formatted Gregorian month arithmetic. */
final class PersianCalendar
{
    private static function calendar(): \IntlCalendar
    {
        if (! class_exists(\IntlCalendar::class)) {
            throw new DomainError('jalali_intl_required');
        }
        $calendar = \IntlCalendar::createInstance('UTC', 'en_US@calendar=persian');
        if (! $calendar || $calendar->getType() !== 'persian') {
            throw new DomainError('jalali_calendar_unavailable');
        }
        $calendar->setLenient(false);
        $calendar->clear();

        return $calendar;
    }

    public static function parts(string $gregorian): array
    {
        LocalTime::date($gregorian);
        $cal = self::calendar();
        $cal->setTime((new \DateTimeImmutable($gregorian.'T12:00:00Z'))->getTimestamp() * 1000);

        return [$cal->get(\IntlCalendar::FIELD_YEAR), $cal->get(\IntlCalendar::FIELD_MONTH) + 1, $cal->get(\IntlCalendar::FIELD_DAY_OF_MONTH)];
    }

    public static function date(int $year, int $month, int $day): string
    {
        if ($year < 1200 || $year > 1600 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
            throw new DomainError('jalali_date_invalid');
        }
        $cal = self::calendar();
        $cal->set($year, $month - 1, $day, 12, 0, 0);
        $millis = $cal->getTime();
        if ($millis === false) {
            throw new DomainError('jalali_date_invalid');
        }

return (new \DateTimeImmutable('@'.(int) ($millis / 1000)))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
    }

    public static function days(int $year, int $month): int
    {
        $cal = self::calendar();
        $cal->set($year, $month - 1, 1, 12, 0, 0);
        if ($cal->getTime() === false) {
            throw new DomainError('jalali_date_invalid');
        }

return $cal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_MONTH);
    }
}
