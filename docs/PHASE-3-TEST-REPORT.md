# Phase 3 checkpoint evidence — SLA subset only

Environment: Windows, PHP 8.4.22, Laravel 13.37.0, PHPUnit 12.5.40, PostgreSQL 18.4.

| Profile | Tests | Assertions | Result |
|---|---:|---:|---|
| SQLite | 138 | 608 | 131 passed; seven PostgreSQL-only concurrency cases explicitly skipped |
| PostgreSQL | 138 | 693 | All passed; zero skipped/failed/errors |

The combined run includes all phase-one/two regressions and eight independently named SLA TM scenarios: 196,197,198,200,201,202,203,204. It also includes 16 focused SLA review tests and a real multi-process watchdog race. There are 71 named TM scenario results in total, not 138 distinct PRD scenario IDs.

The watchdog race proves exactly one SLA event and one outbox notification intent across two workers with a socket barrier. Tests use a frozen clock rather than sleeping. Calendar tests cover Iran's Friday/weekend/holiday behavior and reject ambiguous/nonexistent DST shift boundaries. Tests verify pause/resume budget and metrics, independent reset epochs, preserved policy snapshots, fresh/cumulative visits, repeated inactivity windows, host veto, absent workflow permission, authorized terminal action, terminal visit history and recovery of a pending dead-candidate marker.

Sources: phase3-all-sqlite.xml and phase3-all-postgres.xml are the complete latest reports. Focused earlier reports cover smaller subsets and must not be substituted for these totals. Reproduction uses the documented SQLite/PostgreSQL profiles.

The first combined run exposed an incorrect reentry test fixture: it republished a single shared stage from reset to cumulative, then expected the original instance's next visit to keep the old policy. Per-visit snapshots correctly preserved the current visit but new visits used the new policy. The fixture was separated into independently configured pipelines; both focused profiles and the complete combined suite were rerun successfully.

Gate: the SLA subset is passing. The COMPLETE PHASE-THREE GATE IS NOT PASSED: remaining assignment/approval/handoff/Saga/milestone/campaign cases and the 75/15/10 outcome proof remain unimplemented/unexecuted. Phases four through seven have not started. MySQL/version-matrix/production load/browser-host acceptance remain unverified.
