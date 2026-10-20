<?php

namespace Karnoweb\TaskManager;

final class AuditRedactor
{
    public static function redact(array $values): array
    {
        $redacted = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match('/body|description|private|secret|token|password|note|content|file_ref|filename/i', $key)) { $redacted[$key] = '[redacted]'; }
            elseif (is_array($value)) { $redacted[$key] = self::redact($value); }
            else { $redacted[$key] = $value; }
        }
        return $redacted;
    }
}
