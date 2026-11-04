<?php

namespace Karnoweb\TaskManager\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;

final class IdentifierCollation
{
    public static function forConnection(Connection $connection): string
    {
        if (! $connection instanceof MySqlConnection) {
            return 'utf8mb4_bin';
        }

        return $connection->isMaria() ? 'utf8mb4_nopad_bin' : 'utf8mb4_0900_bin';
    }

    /** Blueprint collation is not applied to char/uuid columns on MariaDB, so foreign keys to outbox.event_id fail. */
    public static function align(Connection $connection, string $table, string $column, bool $nullable = false): void
    {
        if (! $connection instanceof MySqlConnection) {
            return;
        }

        $connection->statement(sprintf(
            'ALTER TABLE `%s` MODIFY `%s` CHAR(36) CHARACTER SET utf8mb4 COLLATE %s %s',
            str_replace('`', '``', $table),
            str_replace('`', '``', $column),
            self::forConnection($connection),
            $nullable ? 'NULL' : 'NOT NULL',
        ));
    }
}
