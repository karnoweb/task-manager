<?php

namespace Karnoweb\TaskManager\Enterprise;

use Karnoweb\TaskManager\Exceptions\DomainError;

final class FieldValue
{
    public static function number(mixed $value): int
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new DomainError('custom_number_invalid');
        } $value = (string) $value;
        if (! preg_match('/^(-?)(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,6}))?$/D', $value, $parts)) {
            throw new DomainError('custom_number_invalid');
        }

        return ($parts[1] === '-' ? -1 : 1) * ((int) $parts[2] * 1000000 + (int) str_pad($parts[3] ?? '', 6, '0'));
    }

    public static function format(int $scaled): string
    {
        return ($scaled < 0 ? '-' : '').intdiv(abs($scaled), 1000000).'.'.str_pad((string) (abs($scaled) % 1000000), 6, '0', STR_PAD_LEFT);
    }
}
