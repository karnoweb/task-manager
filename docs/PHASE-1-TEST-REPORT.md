# Phase 1 test evidence — checkpoint

Environment: Windows, PHP 8.4.22, Laravel 13.37.0, PHPUnit 12.5.40, PostgreSQL 18.4. Dependencies are recorded in composer.lock.

Latest observed execution:

| Profile | Tests | Assertions | Outcome |
|---|---:|---:|---|
| SQLite | 51 | 209 | 49 passed; 2 PostgreSQL-only concurrency tests explicitly skipped |
| PostgreSQL | 51 | 248 | All passed; zero skipped |

Included: all 25 individually named phase-one TM scenario files and 26 supplemental foundation/review tests. PostgreSQL concurrency uses separate PHP processes and a socket barrier, not sequential calls or sleep. Four identical concurrent create requests produce exactly one task/list/instance/journal/outbox/audit. Two distinct updates at expected version 1 produce exactly one success and one version_conflict, with no failed-operation journal or event.

Also verified: fresh migrations, rollback/reinstall, outer-transaction rollback, after-commit dispatcher restriction, failed-delivery recovery, title validation, task/list/count/facet/activity isolation, share revocation, service capabilities, facade parity, fixed test clock, public UUIDs, scoped display sequence/aliases, and clone-operation key collision regression.

Reproduce SQLite: composer test. Reproduce PostgreSQL: set TM_TEST_DRIVER=pgsql and run composer test after starting the dedicated test cluster. Raw JUnit files are foundation-junit.xml and foundation-postgres-junit.xml.

Not run: MySQL 8, the complete PHP/Laravel version matrix, CRM/Kanban/Calendar integration, phase-two-through-six scenarios, or phase-seven acceptance/chaos/load/security review. The named TM tests are current foundation evidence, not an assertion that the entire PRD or release gate is complete. The full phase-one design checkpoint remains open as recorded in PHASE-1-IMPLEMENTATION.md.
