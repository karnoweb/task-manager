# Phase 3 checkpoint evidence — SLA, assignment, approval, handoff and route subsets

Environment: Windows, PHP 8.4.22, Laravel 13.37.0, PHPUnit 12.5.40, PostgreSQL 18.4.

| Profile | Tests | Assertions | Result |
|---|---:|---:|---|
| SQLite | 242 | 1162 | 228 passed; fourteen PostgreSQL-only concurrency cases explicitly skipped |
| PostgreSQL | 242 | 1286 | All passed; zero skipped/failed/errors |

The combined run includes all phase-one/two regressions, eight independently named SLA TM scenarios (196,197,198,200,201,202,203,204) and six assignment scenarios (024,146,188..191). It includes 16 focused SLA review tests, eight assignment review tests and real multi-process watchdog/round-robin races. Three approval scenarios (193..195), eleven focused approval tests and a real concurrent voting race are also included. Twenty-one handoff IDs (166..180,184..187,192,199), focused handoff tests and real delegation/return races are now included. Three route scenarios (181..183), owner-service rollback tests and separate-database Saga/repair/concurrency tests are also included. There are 104 named TM scenario results in total, not 242 distinct PRD scenario IDs.

The watchdog race proves exactly one SLA event and one outbox notification intent across two workers with a socket barrier. Tests use a frozen clock rather than sleeping. Calendar tests cover Iran's Friday/weekend/holiday behavior and reject ambiguous/nonexistent DST shift boundaries. Tests verify pause/resume budget and metrics, independent reset epochs, preserved policy snapshots, fresh/cumulative visits, repeated inactivity windows, host veto, absent workflow permission, authorized terminal action, terminal visit history and recovery of a pending dead-candidate marker.

Sources: phase3-all-sqlite.xml and phase3-all-postgres.xml are the complete latest reports. Focused earlier reports cover smaller subsets and must not be substituted for these totals. Reproduction uses the documented SQLite/PostgreSQL profiles.

The first combined run exposed an incorrect reentry test fixture: it republished a single shared stage from reset to cumulative, then expected the original instance's next visit to keep the old policy. Per-visit snapshots correctly preserved the current visit but new visits used the new policy. The fixture was separated into independently configured pipelines; both focused profiles and the complete combined suite were rerun successfully.

Assignment tests verify primary uniqueness, distinct/capacity-bound secondary assignees, fixed ownership, no implicit view grants, current host eligibility, empty resolver rejection, scoped round-robin fairness, reject/reassign-owner/unassigned departure policies, stale versions and native Todo complete/reopen restoration. The queue race uses two independent pipeline-stage rows with the same team queue; it proves queue serialization rather than relying on one common stage lock.

Gate: the SLA, assignment, approval, handoff and route subsets are passing. The COMPLETE PHASE-THREE GATE IS NOT PASSED: remaining milestone/campaign cases and the 75/15/10 outcome proof remain unimplemented/unexecuted. Phases four through seven have not started. MySQL/version-matrix/production load/browser-host acceptance remain unverified.


The latest checkpoint proves independent origin/destination clocks, waiting preservation, guarded success/rejection/cancellation, timeout/recall, immutable outcome evidence, idempotent replay, return conflicts after live or terminal source changes, explicit assignee return policies, scoped redacted case history and all/any/quorum joins. A test-runtime interruption initially prevented PostgreSQL connection; the dedicated cluster was recovered and the full run above passed. Concurrent return may return the existing result or an explicit version conflict followed by safe retry; both paths prove one outcome and one origin transition. Manual handoff join/repair, installed commercial host integration and campaign closure remain pending. The local owner service and separate-database Saga contracts are implemented and tested.

The latest complete PostgreSQL rerun passed 242 tests / 1286 assertions after an earlier run was interrupted by a backend process exit during DDL. The dedicated test cluster recovered, was cleanly restarted with commit timestamp tracking, and the entire suite was rerun without skipping the interrupted case. The current XML is that successful full rerun.
