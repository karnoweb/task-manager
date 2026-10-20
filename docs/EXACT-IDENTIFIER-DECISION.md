# Exact identifier comparisons — phase-one defect found during phase-three database review

A real MariaDB 11.8.2 fixture using the application's usual utf8mb4_unicode_ci database default returned a private task from scope Tenant to scope tenant. The reproduction failed at the SQL-filtered list assertion; this was a scope disclosure, not just a uniqueness mismatch. The retained failing report is defect-mariadb-identifiers-before.xml.

Correction belongs to the foundation schema: package tables explicitly use binary, NO PAD comparison semantics (MariaDB utf8mb4_nopad_bin; Oracle MySQL 8 utf8mb4_0900_bin). PostgreSQL and SQLite retain their native exact comparisons. Case and trailing padding differences remain distinct for scope/actor/operation identifiers. Host-owned tables/subject handlers must independently implement the same identifier semantics; the package does not alter host tables.

Fresh table definitions set this explicitly. Migration 000012 upgrades only the 52 explicitly owned package tables whose default or column collation differs, preserving records. Its schema queries respect connection table prefixes. It restores the session foreign-key-check setting even on failure, is repeatable and never downgrades to insensitive comparisons. MySQL-family ALTER TABLE is not transactionally atomic: back up before an installed upgrade, plan its table locks, and rerun migration on interruption; already-converted tables are skipped. No release/installed application upgrade has been performed here.

A populated legacy MariaDB task table is deliberately restored to insensitive comparison in the upgrade regression; the test proves the leak, applies migration twice, then proves isolation, preserved title/row/audit and restored foreign-key enforcement. After-fix focused evidence: two tests / 21 assertions, no skips. PostgreSQL runs the generic isolation test; the MariaDB-specific legacy upgrade is an explicit skip there.

A second test-harness defect was removed: FoundationTest duplicated database selection and treated an unknown/MariaDB profile as SQLite. It now inherits the shared test profile. Unknown profiles fail before migration instead of silently falling back. Any earlier partial MariaDB candidate is not accepted combined evidence. Complete post-fix profiles must be rerun before the phase-three report is finalized.

MariaDB's driver name is also accepted by the group SKIP LOCKED claim path. Its independent real two-worker and crash-after-40/leased-60 recovery tests passed: two tests / 15 assertions, zero skips, in phase3-group-concurrency-mariadb.xml. This does not substitute for Oracle MySQL 8 coverage.

Reproduction and post-fix verification commands use the dedicated profile: TM_TEST_DRIVER=mariadb; php vendor/bin/phpunit tests/IdentifierIsolationTest.php. PostgreSQL runs with TM_TEST_DRIVER=pgsql. The complete post-fix SQLite run passed 298 tests / 1512 assertions with 22 explicit engine-specific skips. Full release compatibility is not inferred from these focused checks.

Final post-fix evidence: complete PostgreSQL 298 tests / 1709 assertions (one MariaDB-only upgrade skip), complete SQLite 298 / 1512 (22 engine-specific skips), targeted MariaDB 30 / 236 (one PostgreSQL-only E2E skip). All have zero failures/errors; full reports are linked by PHASE-3-TEST-REPORT.md.
