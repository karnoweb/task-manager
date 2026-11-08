<?php

namespace Karnoweb\TaskManager\Exceptions;

use Illuminate\Support\Facades\Lang;

final class DomainError extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct(self::translate($errorCode, $details), 0, $previous);
    }

    public static function translate(string $errorCode, array $replace = []): string
    {
        $replace = self::normalizeReplace($replace);
        $key = "task-manager::errors.{$errorCode}";
        $message = Lang::get($key, $replace);

        if ($message !== $key) {
            return $message;
        }

        $fallbackKey = "errors.{$errorCode}";
        $fallback = Lang::get($fallbackKey, $replace, 'en');

        return $fallback !== $fallbackKey ? $fallback : $errorCode;
    }

    /** @param  array<string, mixed>  $replace */
    private static function normalizeReplace(array $replace): array
    {
        $normalized = [];
        foreach ($replace as $key => $value) {
            $normalized[$key] = is_array($value) ? implode(', ', array_map(strval(...), $value)) : $value;
        }

        return $normalized;
    }
}
