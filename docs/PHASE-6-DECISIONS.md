# Phase-six integration decisions and source review

## Actual sources, not guessed contracts

The local Kanban v13.3.0, Calendar 2.0.0 and CRM 13.1.0 sources were read. Exact repository commits, source hashes and installed dependency locks are captured in `PHASE-6-SOURCE-LOCK.json` and `phase6-host-locks/`. Sibling sources were not edited. The pre-existing untracked `crm.zip` is unrelated to this change.

Reviewed Kanban contracts: `DataProvider`, `ActionHandler`, `CardDetailsProvider`, command/result/card/stage DTOs, model registry and handler, `MoveCoordinator`, the real Livewire move payload, and Drawer documentation. Reviewed Calendar contracts: `EventProvider`, `ActionHandler`, reference/query/page/event DTOs, fixed source registry, read dispatcher/aggregator, model registry/provider, authorization guard and real ISO move payload. Reviewed CRM models, services, morph aliases, migrations and stage/recurrence history. The archived Kanban `docs/DESIGN-AUDIT.md` inside `artifacts/kanban-k1-k5-review.zip` was also read. It contains historical visual findings, including open host-theme/accessibility/form items; this phase makes no accessibility certification or browser pixel-comparison claim. Calendar's current upgrade/security/source documentation was reviewed; no separate historical Calendar audit artifact was supplied.

| Component | Verified baseline | Integration surface |
|---|---|---|
| Task Manager | PHP 8.4.22, Laravel 13.37.0, SQLite | Existing core plus explicit integration factory |
| Kanban | 13.3.0 | Actual interfaces, native trait provider, real Livewire render/action |
| Calendar | 2.0.0 | Actual interfaces, source dispatcher/merger and native model provider |
| CRM | 13.1.0 | Actual tables, models, Deal/Task/Recurrence/Campaign services |
| Livewire | 4.4.7 | Actual package in isolated host locks |

Only these exact local baselines are verified. A broader compatible-version range is not inferred from one successful build.

## Independence and installation

Core Composer requirements are unchanged. Integration classes use optional namespaces only when explicitly selected; the core provider does not register UI routes, models, assets or CRM observers. Four real Composer dependency graphs are installed separately: task-only, kanban-only, calendar-only and all. Absence is checked by autoloading in separate PHP processes, not by pretending a loaded class is absent. Each standalone UI profile persists and reads a host-owned Order; the Kanban profile also moves it. The task-only profile exercises Todo, project, facade and reporting.

Network repository requests timed out. The reproducible preparation tool builds path repositories from installed local package metadata with network access disabled. One installed dependency has metadata name `morilog/jalali` but its on-disk manifest says `moree-dev/jalali`; a private staging copy corrects only that manifest name to the installed metadata identity. The code is unchanged, and this reconciliation is recorded in `PHASE-6-SOURCE-LOCK.json`. This is local compatibility evidence, not a claim that a clean internet download was verified.

## Authority, commands and cursors

Surface definitions and context resolvers are trusted server code. Context actor/scope must equal the UI context; no UI capability array is trusted. SQL authorization precedes filtering/counting/paging. Board identifiers include source; Calendar references retain separate source, record and occurrence components. Opaque cursors are HMAC-bound to actor, scope, surface and query, with a 15-minute lifetime. Calendar cursors also bind the outbox revision; recurrence cursors bind the current permitted occurrence projection. Board keyset pages re-evaluate permissions on every read.

Board commands have a transactional adapter journal around domain journals. The original raw command, expected version and surface are fingerprinted. Retries authorize the current card and cannot repeat the handoff callback. Task version checks for workflow moves lock the editable task before delegating to the instance service. Host callbacks must own real domain validation and use the same database connection; remote effects belong to the existing outbox/Saga APIs.

The adapter intentionally advertises view/move only on cards, no manual rank support, and read-only details. Task creation/editing/archive and advanced recurrence commands remain available through the core services. Host board settings must expose only implemented card actions; the usage guide supplies those settings. Calendar capabilities advertise only move/resize on ordinary schedules and move on due markers. SLA/milestone/recurrence projections advertise no write actions. An unsupported UI action is never reported as success.

The default Calendar provider cache is off. Keep it off for these adapters unless the host implements permission-aware invalidation: the UI package's TTL cache cannot discover arbitrary external ACL revocations on its own. Core page reads and export/download checks always use current authorization.

## Migration and ownership

Imports require `migration.manage`, current host authorization, a private owner-controlled configuration and explicit mappings. No boot-time backfill or cutover occurs. Source snapshots include real Deal stage histories or recurrence definitions, so history/series edits also invalidate reconciliation. Target state and version are fingerprinted. Reconciliation streams in bounded chunks; missing source records and divergent counts are visible failures. Failed imports roll back task, workflow, link and outbox writes together.

CRM's `crm_lead` / `crm_deal` morph aliases are explicitly mapped to engine aliases `crm.lead` / `crm.deal`. Terminal stage mappings must preserve open/success/failure meaning. Task status mappings preserve open/done/cancelled meaning, and a non-null custom task type requires a declared mapping to an existing project type. Historical transitions retain source times/categories; historical SLA policies are unknown unless the host has actual evidence. Imported stage intervals therefore do not fabricate policy snapshots or old deadlines. Configure an explicit current SLA policy/reset as part of the host's operational cutover where needed.

Shadow/rolled-back imported tasks cannot be edited by core subject queries; imported Deals use the same ownership gate in their current host query. The explicit CRM observer rejects old Deal/Followup/RecurringTask service saves/deletes/restores, including new legacy records after cutover. Raw SQL and Eloquent bulk updates bypass model events: the host must retire legacy jobs/routes/bulk writers and enforce database privileges. Package observers do not claim to intercept arbitrary SQL from a privileged database client.

Rollback is conservative: unchanged imported data can return to legacy ownership with the original rows intact. Any new scoped engine event after cutover blocks simple rollback, even if unrelated, and requires a host-reviewed reverse-sync/reconciliation procedure. This prevents losing newly created recurrence children, follow-ups or other domain changes. No automatic destructive reverse migration exists.

## Test boundaries

Host E2E-01..06 run against actual CRM services and persisted sample-host domain models on SQLite. E2E-02 asserts exactly 75 failed / 15 continued / 10 already terminal, 12 SLA events, five active delegated contracts and idempotent resumption between batches. Its commit ledger attests only completed local fixture transactions. It is not physical process termination or cross-database commit evidence. E2E-04 injects a real same-connection CRM failure and checks atomic rollback/retry. Original separate-database Saga fixtures remain regression coverage; real remote transport and OS crash tests belong to phase seven.

The gate is `PASS_SQLITE_SCOPE`, not a release authorization. Phase seven must rerun the complete frozen candidate and database/failure matrix.
