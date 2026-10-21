<?php

namespace Karnoweb\TaskManager\TaskGraph;

use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\SLA\CalendarRegistry;

final class CalendarLag
{
    public static function shift(\DateTimeImmutable $anchor, int $minutes, string $clock, ?string $reference, CalendarRegistry $registry): \DateTimeImmutable
    {
        if ($clock === 'elapsed') {
            return $anchor->modify(($minutes >= 0 ? '+' : '').$minutes.' minutes');
        }
        $calendar = $registry->get($reference ?? '');
        if ($minutes >= 0) {
            return $calendar->addSeconds($anchor, $minutes * 60);
        }
        $seconds = -$minutes * 60;
        $distance = 86400;
        $high = $anchor->getTimestamp();
        while (true) {
            if ($distance > 3660 * 86400) {
                throw new DomainError('business_lag_limit');
            }
            $low = $high - $distance;
            if ($calendar->secondsBetween($anchor->setTimestamp($low), $anchor) >= $seconds) {
                break;
            }
            $distance *= 2;
        }
        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);
            if ($calendar->secondsBetween($anchor->setTimestamp($middle), $anchor) >= $seconds) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        return $anchor->setTimestamp($low);
    }
}
