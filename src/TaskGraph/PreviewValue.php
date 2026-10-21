<?php

namespace Karnoweb\TaskManager\TaskGraph;

final class PreviewValue
{
    public static function ordered(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::ordered($item);
            }
        }

        return $value;
    }
}
