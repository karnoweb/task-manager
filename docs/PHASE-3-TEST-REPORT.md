# Phase 3 checkpoint evidence — SLA and assignment subsets

Environment: Windows, PHP 8.4.22, Laravel 13.37.0, PHPUnit 12.5.40, PostgreSQL 18.4.

| Profile | Tests | Assertions | Result |
|---|---:|---:|---|
| SQLite | 153 | 685 | 144 passed; nine PostgreSQL-only concurrency cases explicitly skipped |
| PostgreSQL | 153 | 779 | All passed; zero skipped/failed/errors |

The combined run includes all phase-one/two regressions, eight independently named SLA TM scenarios (196,197,198,200,201,202,203,204) and six assignment scenarios (024,146,188..191). It includes 16 focused SLA review tests, eight assignment review tests and real multi-process watchdog/round-robin races. There are 77 named TM scenario results in total, not 153 distinct PRD scenario IDs.

The watchdog race proves exactly one SLA event and one outbox notification intent across two workers with a socket barrier. Tests use a frozen clock rather than sleeping. Calendar tests cover Iran's Friday/weekend/holiday behavior and reject ambiguous/nonexistent DST shift boundaries. Tests verify pause/resume budget and metrics, independent reset epochs, preserved policy snapshots, fresh/cumulative visits, repeated inactivity windows, host veto, absent workflow permission, authorized terminal action, terminal visit history and recovery of a pending dead-candidate marker.

Sources: phase3-all-sqlite.xml and phase3-all-postgres.xml are the complete latest reports. Focused earlier reports cover smaller subsets and must not be substituted for these totals. Reproduction uses the documented SQLite/PostgreSQL profiles.

The first combined run exposed an incorrect reentry test fixture: it republished a single shared stage from reset to cumulative, then expected the original instance's next visit to keep the old policy. Per-visit snapshots correctly preserved the current visit but new visits used the new policy. The fixture was separated into independently configured pipelines; both focused profiles and the complete combined suite were rerun successfully.

Assignment tests verify primary uniqueness, distinct/capacity-bound secondary assignees, fixed ownership, no implicit view grants, current host eligibility, empty resolver rejection, scoped round-robin fairness, reject/reassign-owner/unassigned departure policies, stale versions and native Todo complete/reopen restoration. The queue race uses two independent pipeline-stage rows with the same team queue; it proves queue serialization rather than relying on one common stage lock.

Gate: the SLA and assignment subsets are passing. The COMPLETE PHASE-THREE GATE IS NOT PASSED: remaining approval/handoff/Saga/milestone/campaign cases and the 75/15/10 outcome proof remain unimplemented/unexecuted. Phases four through seven have not started. MySQL/version-matrix/production load/browser-host acceptance remain unverified.
