# Phase-six test report

The final run used SQLite only, as requested. It is not phase-seven acceptance or a release authorization.

| Evidence | Observed result |
|---|---|
| Complete final regression | 564 tests / 4960 assertions / 0 errors / 0 failures / 22 engine-specific skips |
| Owned phase-six scenarios | 21 IDs / 21 cases / 147 assertions / no failure or skip |
| Functional host E2E | 6 IDs / 8 cases, including three approval/return outcomes |
| Actual isolated installations | Task-only, Kanban-only, Calendar-only, all; each booted and queried real persisted records |
| UI host integration | Real Livewire board render/action, actual Calendar read dispatcher/merger, native model-trait providers |
| CRM host integration | Actual Deal, Task, RecurringTask and Campaign services and tables; sample host Ticket models/routes |

The owned XML is extracted from the final full JUnit file, not fabricated or based only on file existence. The verifier also requires passing E2E-01..06 bodies, four passing standalone installation results and their real Composer dependency graphs. `PHASE-6-SOURCE-LOCK.json` records exact local baselines and the installed manifest-name reconciliation. Root package requirements retain no mandatory UI or CRM dependency.

Self-review and tests cover actual command argument order; current section permissions; source-specific Calendar provider composition; real ISO date payloads; transactional board retry journals; task-version locking; current-source visibility; due-only/stable occurrence identity; read-only SLA/milestone/recurrence projections; both-side follow-up authorization; real CRM task/series migration; custom type and terminal mapping rejection; atomic import rollback; source history/series fingerprinting; missing-row reconciliation; safe writer cutover and unchanged-data rollback; conservative rejection of rollback after new engine events. Final full regression passed after product changes.

E2E-02 uses 100 real CRM opportunities and asserts exactly 75 failed / 15 continued / 10 already terminal, 12 SLA events, five active delegated contracts and no duplicate events on processing retry. Its local transaction ledger and between-batch resumption do not prove OS process termination. E2E-04 calls the actual same-connection CRM DealService, injects failure after creation, observes rollback and retries once without a duplicate. The sample host is executable test application code; it is not every consumer's deployed Ticket application.

No phase-six PostgreSQL, MariaDB, Oracle MySQL, physical multi-connection race, real outbound network delivery, browser accessibility certification or final load run is claimed. Existing 22 engine-specific cases remain skipped. Original physical crash/separate-database fixtures must be rerun in the comprehensive phase-seven matrix. Cached pages in the external Calendar package must remain disabled unless the host wires permission-aware invalidation.

Historical visual findings in the supplied Kanban design audit are recorded as upstream/host acceptance context, not silently certified as fixed here. The actual legacy-writer guard covers model-event service writes; arbitrary privileged SQL/bulk writers must be retired or restricted by the host. Simple rollback is intentionally limited to unchanged data; post-cutover changes require a separately reviewed host reverse migration.

See `PHASE-6-IMPLEMENTATION-FA.md`, `PHASE-6-USAGE-FA.md`, `PHASE-6-MIGRATION-FA.md`, `PHASE-6-DECISIONS.md` and `PHASE-6-NEXT-GATE.md`. The agent stops before phase seven.
