<?php

namespace Karnoweb\TaskManager\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;

final class IdentifierCollation
{
    public static function forConnection(Connection $connection): string
    {
        if (!$connection instanceof MySqlConnection) { return 'utf8mb4_bin'; }
        return $connection->isMaria() ? 'utf8mb4_nopad_bin' : 'utf8mb4_0900_bin';
    }
}
