<?php

namespace Karnoweb\TaskManager\Planning;

use Karnoweb\TaskManager\Exceptions\DomainError;

final class PointValue
{
    public static function minor(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', $value)) {
            throw new DomainError('story_points_invalid');
        }
        $parts = explode('.', $value);

        return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    public static function format(int $minor): string
    {
        return ($minor < 0 ? '-' : '').intdiv(abs($minor), 100).'.'.str_pad((string) (abs($minor) % 100), 2, '0', STR_PAD_LEFT);
    }
}
