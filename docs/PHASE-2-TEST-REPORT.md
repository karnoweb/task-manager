# Phase 2 gate evidence

Environment: Windows, PHP 8.4.22, Laravel 13.37.0, PHPUnit 12.5.40, PostgreSQL 18.4. Dependency versions remain fixed by composer.lock.

| Profile | Tests | Assertions | Result |
|---|---:|---:|---|
| SQLite | 113 | 486 | 108 passed, five PostgreSQL-only process-concurrency tests explicitly skipped |
| PostgreSQL | 113 | 561 | 113 passed; zero failures/errors/skips |

All 25 phase-one and 38 phase-two owned TM IDs have independent executable files. Remaining tests provide targeted review and concurrency evidence. The PostgreSQL suite ran real multi-process barriers for identical create, competing updates, active-slot uniqueness and stage capacity; TM-157 independently exercises the slot race.

Sources: phase2-all-sqlite.xml and phase2-all-postgres.xml. Focused reports also cover workflow core/security/concurrency. Commands: composer test for SQLite; set TM_TEST_DRIVER=pgsql and run composer test for the dedicated PostgreSQL test cluster. Tests recreate only the explicitly configured test database schema.

Review fixes include PostgreSQL legacy placeholder type migration, constant-query typed task reads, native primary-slot consistency, host authorization on migration previews, immutable completed/type-specific process history, validated reason catalogs, malformed definition rejection, host-field condition forgery prevention, external-only service capabilities and case-existence concealment.

Historical failing experiments are not claimed passed: the first PostgreSQL phase-two run failed on UUID-to-BIGINT conversion. The guarded placeholder migration fixed it, and the entire suite was rerun successfully. A Windows PHP wrapper also rejected a pipe-containing shell regex; the suite was rerun without that wrapper-sensitive filter.

Limits: no MySQL 8 execution, full PHP/Laravel version matrix, actual Kanban/Calendar/browser integration or cross-DB atomicity is claimed. Host models in these tests are deliberately test-owned. Phase-two source/projection contracts must be exercised again through real UI adapters in phase six and the complete phase-seven acceptance run.

Gate: PASS for the phase-one/two scope with the above explicit environmental limits. This is not a release Go decision.
