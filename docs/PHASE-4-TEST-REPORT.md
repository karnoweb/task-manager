# Phase 4 test report

Final verification: SQLite only, as explicitly requested by the user. Phase-four implementation is complete within this test scope. This is not final acceptance or a release decision.

| Evidence | Observed result |
|---|---|
| Complete final regression (`phase4-all-sqlite.xml`) | 419 tests / 2086 assertions / 0 errors / 0 failures / 22 engine-specific skips |
| Independent owned scenarios | 52 IDs / 58 actual data cases / 288 assertions / no failure or skip |
| 100-task project | Depth 8, separate Bug revision, real dependency guards, sprint snapshots, 92 leaves / 92 points / 2 delivered points, raw subtree time counted once |
| Child approval safety | Pending child approval blocks direct parent completion and terminal subtree mapping; failed transaction leaves project/tasks/outbox unchanged |
| Immediate active-sprint scope reporting | New membership appears before any daily snapshot; historical committed/daily snapshots stay immutable |

The owned subset XML is extracted from the final full regression, not a second execution or a guessed status. `python tools/verify_phase4_gate.py` verifies every actual owned data case and rebuilds `PHASE-4-GATE.json`. Each ID has an independent executable PHP body and Persian Markdown document.

Implemented checks cover hierarchy progress/cycles/depth/atomic reparent, controlled subtree project moves, all four actual dependency types and signed/business lags, read-only date previews, strict missing dates, both endpoint permissions, checklist conversion retry, comment history/safe rendering/window/retention, mentions/watchers/revocation, real local-file reads and bound tickets, manual/timer time truth and overlap, stale projection rebuild, frozen sprint history/carry/dedupe/leaf velocity, exact dense ranking/version conflicts, private immutable templates, permission-filtered reporting and atomic/partial bulk.

Self-review corrected native initial in-progress dependency bypass; historical actual-clock categorization; report use of latest native main instance; unknown-point velocity; stale structural positions; marker progress after project departure; and delayed active-sprint scope reporting. The regression includes focused reproductions. Existing foundation/process tests remain part of the final suite.

## Explicit deferred evidence

No PostgreSQL, EnvKit MariaDB or Oracle MySQL test was run for phase four. The 22 skips belong to existing engine-specific tests. TM-037, TM-102, TM-103 and TM-109 currently prove SQLite sequential conflicting-client/version/invariant behavior only. Multiple physical connections/workers, graph locks, timer reservations under real contention, deadlock/retry behavior, the database/version matrix and full load remain phase-7 obligations. These are not recorded as proven races.

Coarse scope graph locking favors correctness over write concurrency and requires later throughput validation. No installed-host/browser integration or phase-five feature is claimed. Earlier PostgreSQL/MariaDB reports are historical phase-three evidence and do not verify these new migrations.

The next boundary is phase five. Per the user's instruction, stop and report here before starting it.
