<?php

namespace Karnoweb\TaskManager\Enterprise;

final class TextNormalizer
{
    public const VERSION = 2;

    public static function normalize(string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_KC) ?: $value;
        }
        $value = strtr(mb_strtolower($value), ['ي' => 'ی', 'ك' => 'ک', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);

        return trim(preg_replace('/[\p{Z}\s\x{200C}\x{200D}]+/u', ' ', $value) ?? $value);
    }
}
