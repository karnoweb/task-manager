<?php

namespace Karnoweb\TaskManager\Enterprise;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\Context\ExecutionContext;

final class VisibilityEpoch
{
    public static function bump(Connection $db, string $scope): void
    {
        $db->table('tm_visibility_epochs')->insertOrIgnore(['scope_key' => $scope]); $db->table('tm_visibility_epochs')->where('scope_key', $scope)->increment('version');
    }
    public static function fingerprint(Connection $db, ExecutionContext $context, string $hostRevision): string
    {
        $caps = $context->capabilities; sort($caps); return hash('sha256', json_encode([$context->scope,$context->actor,$caps,$hostRevision,$db->table('tm_visibility_epochs')->where('scope_key', $context->scope)->value('version') ?? 1], JSON_THROW_ON_ERROR));
    }
}
