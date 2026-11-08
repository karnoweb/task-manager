<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Schema\IdentifierCollation;

final class IdentifierCollationTest extends PackageTestCase
{
    public function test_for_connection_resolves_supported_binary_collation(): void
    {
        $collation = IdentifierCollation::forConnection(DB::connection());
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            self::assertContains($collation, ['utf8mb4_nopad_bin', 'utf8mb4_bin', 'utf8mb4_0900_bin']);
        } else {
            self::assertSame('utf8mb4_bin', $collation);
        }
    }
}
