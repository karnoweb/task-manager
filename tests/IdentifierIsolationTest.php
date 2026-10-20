<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;

final class IdentifierIsolationTest extends PackageTestCase
{
    public function test_case_distinct_scope_and_actor_identifiers_do_not_share_visibility_or_operations(): void
    {
        $service = $this->service(); $owner = $this->context('Owner', 'Tenant');
        $task = $service->create($owner, 'private', 'create');
        foreach ([$this->context('Owner', 'tenant'), $this->context('owner', 'Tenant'), $this->context('Owner', 'Tenant '), $this->context('Owner ', 'Tenant')] as $other) {
            self::assertSame([], $service->query($other)); self::assertSame(0, $service->count($other));
            $this->error('not_found', fn () => $service->get($other, $task['public_key']));
        }
        $otherTask = $service->create($this->context('Owner', 'tenant'), 'other scope', 'create');
        self::assertNotSame($task['public_key'], $otherTask['public_key']);
        $third = $service->create($owner, 'second operation', 'CREATE');
        self::assertNotSame($task['public_key'], $third['public_key']);
        self::assertSame(3, DB::table('tm_tasks')->count());
    }

    public function test_populated_legacy_mariadb_collation_upgrade_preserves_records_and_restores_isolation(): void
    {
        if (DB::connection()->getDriverName() !== 'mariadb') { $this->markTestSkipped('Requires a real legacy MariaDB table collation upgrade.'); }
        $task = $this->service()->create($this->context('Owner', 'Tenant'), 'preserved', 'create');
        DB::statement('ALTER TABLE tm_tasks CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::assertSame(1, $this->service()->count($this->context('Owner', 'tenant')));
        $migration = require __DIR__.'/../database/migrations/2026_10_20_000012_enforce_exact_identifier_collation.php';
        $migration->up(); $migration->up();
        self::assertSame(0, $this->service()->count($this->context('Owner', 'tenant')));
        self::assertSame('preserved', $this->service()->get($this->context('Owner', 'Tenant'), $task['public_key'])['title']);
        self::assertSame(1, DB::table('tm_tasks')->count()); self::assertSame(1, DB::table('tm_audit_events')->count());
        self::assertSame(1, (int) DB::selectOne('SELECT @@SESSION.foreign_key_checks AS enabled')->enabled);
    }
}
