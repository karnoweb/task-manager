<?php

namespace Karnoweb\TaskManager\Scheduling;

use Karnoweb\TaskManager\Exceptions\DomainError;

final class LocalTime
{
    public static function zone(string $name): \DateTimeZone
    {
        if (! in_array($name, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new DomainError('timezone_invalid');
        }

        return new \DateTimeZone($name);
    }

    public static function date(string $value): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new DomainError('date_invalid');
        }
    }

    public static function resolve(string $wall, string $timezone, string $policy = 'reject'): \DateTimeImmutable
    {
        if (! in_array($policy, ['reject', 'earliest', 'latest'], true)) {
            throw new DomainError('dst_policy_invalid');
        }
        $zone = self::zone($timezone);
        $naive = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $wall, new \DateTimeZone('UTC'));
        if (! $naive || $naive->format('Y-m-d H:i:s') !== $wall) {
            throw new DomainError('date_invalid');
        }
        $epoch = $naive->getTimestamp();
        $transitions = $zone->getTransitions($epoch - 172800, $epoch + 172800) ?: [];
        $matches = [];
        foreach (array_unique(array_column($transitions, 'offset')) as $offset) {
            $candidate = (new \DateTimeImmutable('@'.($epoch - $offset)))->setTimezone($zone);
            if ($candidate->format('Y-m-d H:i:s') === $wall) {
                $matches[$candidate->getTimestamp()] = $candidate;
            }
        }
        if (! $matches) {
            throw new DomainError('local_time_nonexistent');
        }
        ksort($matches);
        if (count($matches) > 1 && $policy === 'reject') {
            throw new DomainError('local_time_ambiguous');
        }

        return ($policy === 'latest' ? end($matches) : reset($matches))->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function stamp(?\DateTimeImmutable $time): ?string
    {
        return $time?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
