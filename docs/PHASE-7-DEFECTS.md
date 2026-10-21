# Phase-seven defects — cycle 1

## P7-D001 — PostgreSQL CRM writes and cutover fail on incompatible identifier types

- **Status:** OPEN; **severity:** High (blocks a required CRM integration on a mandatory database).
- **Owner:** phase six, optional CRM adapter. No product fix was made during phase seven.
- **Candidate:** `05f0829fd409f3289e561420180cda29748d8a12`; exact Composer lock fingerprint is in `PHASE-7-GATE.json`; optional package locks/commits are in `PHASE-6-SOURCE-LOCK.json` and `phase6-host-locks/`.
- **Environment:** Windows, PHP 8.4.22, PostgreSQL 18.4, Laravel 13.37.0; actual locked CRM/Kanban/Calendar host packages, not substituted interfaces.
- **Related requirements:** TM-213, TM-219, TM-220, NET-034, REG-076. This relationship is not a claim that those complete scenarios were executed.
- **Reproducing test IDs:** `CrmCandidateReproductionTest::test_followup_task_cannot_mutate_real_crm_deal_status` and `::test_real_deal_pipeline_history_alias_import_and_writer_switch`.

### Steps

1. Use the dedicated PostgreSQL fixture database `tm_phase7_repro_20261021` at `127.0.0.1:55439`, owned by the existing test user `tm_test`. It contains synthetic data only. Do not run against a business database: these fixtures use `migrate:fresh`.
2. Set `TM_TEST_DRIVER=pgsql` and `TM_PHASE6_HOST_AUTOLOAD` to the locked all-host Composer autoloader (`.runtime/phase6-hosts/all/vendor/autoload.php` in this workspace).
3. Run the native PHP executable with `vendor/phpunit/phpunit/phpunit qa/phase7/CrmCandidateReproductionTest.php --log-junit docs/phase7-crm-reproduction-postgres.xml`.
4. The first two controls pass: legacy follow-up import/reconciliation/rollback and source-drift protection.
5. Creating an engine follow-up for a real CRM Deal fails. Importing real Deal pipeline/history also fails at the same query predicate.

### Expected / observed / impact

Expected: an authorized follow-up can be created without modifying the CRM Deal status; mapped Deal history imports into the engine and the writer switch succeeds with one source of truth.

Observed: PostgreSQL raises `SQLSTATE[42883]: operator does not exist: character varying = bigint`. The predicate in `src/UI/Crm/DealSubject.php` compares `tm_host_imports.legacy_id` (text identifier) directly with `crm_deals.id` (bigint). PostgreSQL rejects the query even when no matching import row exists. Calls fail through `FollowupService::authorize()` and `CutoverService`.

Impact: required CRM follow-up and migration paths are unusable on PostgreSQL; SQLite success did not establish cross-engine compatibility. A related root-suite run also showed failures, but its interrupted console output is not treated as a completed or isolated result. No claim of data loss or authorization leakage is made from this finding.

### Evidence and disposition

The isolated four-test run completed twice with **4 tests, 19 assertions, 2 errors, 0 failures, 0 skips**. The final XML and console log are `phase7-crm-reproduction-postgres.xml` and `phase7-logs/crm-reproduction-postgres.txt`. The final run used the formatted QA file at its delivered path.

Return this defect to phase six. Normalize the comparison using the package's portable identifier strategy, preserve opaque identifier semantics, and test against PostgreSQL, MySQL and SQLite. After the owner-phase fix, freeze a new candidate and repeat all phase-seven gates. The current candidate remains NO-GO.
