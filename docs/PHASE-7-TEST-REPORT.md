# Phase seven — cycle 1 — NO-GO

**Candidate:** `05f0829fd409f3289e561420180cda29748d8a12`. Product source, configuration, contracts, migrations and dependency locks remain unchanged. This is a completed fail-fast assessment of this candidate, **not completion or acceptance of all 362 scenarios**.

| Run | Result |
|---|---|
| Full existing SQLite suite, actual optional all-host dependencies | 564 tests; 4,960 assertions; 0 errors/failures; 22 existing engine-specific skips; 3m28s; 114 MB |
| Isolated PostgreSQL CRM reproduction | 4 tests; 19 assertions; 2 errors; 0 failures/skips |
| Full PostgreSQL suite | Interrupted after the isolated blocker was confirmed; console showed additional errors/failures; no completed totals claimed |
| Full MariaDB 11.8.2 suite | Interrupted at the same fail-fast decision; no completed totals or pass claimed |
| Oracle MySQL 8 | Not available or executed; MariaDB is not counted as Oracle MySQL |

The full matrix runs used separate existing test databases. A third, separate PostgreSQL database isolated the CRM reproduction from those runs. Only the two explicitly identified QA PHP processes were stopped; the PostgreSQL query from the interrupted fixture connection was cancelled. Existing application services and business databases were not stopped or modified. Historical phase-six installation logs are preserved; fresh complete SQLite evidence includes those installation tests.

## Blocking finding

**P7-D001 (High, phase-six owner):** the real CRM Deal adapter compares a text legacy identifier directly to a bigint Deal ID. PostgreSQL rejects follow-up creation and Deal history import with SQLSTATE 42883. Two control tests pass, and two relevant operations fail in the isolated reproduction. See `PHASE-7-DEFECTS.md` for exact steps, expected/observed behavior and disposition.

The implementation plan requires NO-GO when a principal gate fails and requires product defects to return to their owner phase. This cycle stopped at that mandatory compatibility blocker. No product change, architecture change or migration fix was hidden in QA.

## Coverage and unexecuted work

`PHASE-7-REQUIREMENTS.json` and `.md` enumerate all **236 TM + 6 E2E + 40 NET + 80 REG = 362 requirements** and link actual completed SQLite test methods where present. A baseline test pass does not establish the entire prose scenario, database matrix or release acceptance. All 362 retain NOT_ACCEPTED in this failed cycle.

The 40 NET and 80 REG scenarios have not been executed as complete independent acceptance scenarios in this cycle. Related existing tests are not relabelled as 120 extra passes. Complete PostgreSQL/MySQL concurrency and worker chaos, external network transport, full 100k campaign and 1k process load, browser/accessibility, PHP/Laravel matrix and operational upgrade/reconciliation remain unaccepted. Existing SQLite skips remain explicit and do not waive any release requirement.

No release performance threshold was approved, and no p95/p99 claim is made. The interrupted PostgreSQL 100k report query ran for over two minutes under concurrent QA workload; that is a diagnostic observation, not an isolated benchmark or a second confirmed product defect. The isolated CRM blocker reproduced on consecutive runs; broad flakiness acceptance was not completed.

## Reproducibility and next gate

- `PHASE-7-GATE.json`: candidate/lock hashes, precise decision, completed run totals and interrupted-run disposition.
- `phase7-environment.json`: actual PHP extensions and connected database versions.
- `phase7-sqlite.xml`, `phase7-crm-reproduction-postgres.xml`: completed machine-readable results.
- `phase7-logs/`: preserved console output, with interrupted runs explicitly named.
- `qa/phase7/CrmCandidateReproductionTest.php`: independent database reproduction with genuine domain services.
- `tools/verify_phase7_gate.py`: checks the frozen product, reads evidence and generates the 362-row matrix; it does not turn partial runs into passes.

**Next gate:** return P7-D001 to phase six, repair outside phase seven, verify identifier semantics and database compatibility, freeze a new candidate, and rerun phase seven from the beginning. Publication is not approved.
