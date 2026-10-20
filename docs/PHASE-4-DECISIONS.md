# Phase 4 schema and consistency decisions

Migrations 000013..000019 are additive and follow the existing exact-identifier collation helper. Every new independently addressable resource is scoped directly or through an owned foreign-key root. There are no mandatory foreign keys to host user/file systems.

| Migration | Authoritative records / derived structures |
|---|---|
| 000013 | Parent adjacency is authoritative; bounded closure rows index ancestors/descendants. Scope graph lock rows serialize graph-changing native commands. Project depth defaults to 3, bounded by 8. Existing adjacency is validated during backfill. |
| 000014 | Immutable workflow state categories plus nullable from/to revision/category on transition history. Legacy transitions are unknown; current definitions never fabricate old categories. |
| 000015 | Soft-deleted dependency history, portable active-edge reservations, planned dates, nullable estimates/points. Related links do not participate in blocking cycles. |
| 000016 | Checklists/items, immutable comment edits, mentions/watchers, verified-file flag, actor-bound hashed download tickets. |
| 000017 | Actor time locks, persistent timers, portable active-timer reservation, raw time entries, task time source revision and rebuildable statistics. |
| 000018 | Integer hundredths for points, sprint membership history and open reservations, active-sprint task slots, immutable dated snapshots/items, project markers, contextual fixed-point ranks. |
| 000019 | Private template heads, immutable validated revisions and project→template revision provenance. |

Commands use the existing canonical idempotency journal and transaction; audit/outbox is recorded in the same transaction. Template/subtree/bulk inner operations are bounded and receive stable hashed child operation identifiers. No direct state writes bypass lifecycle checks. Subtree guards run against the original complete hierarchy before any writes; private apply helper is shared with the leaf move API. Moving a task clears explicit task grants as in phase 2; project membership governs new visibility.

The scope graph lock is deliberately coarse: native task writes and state-changing native-main commands acquire it before graph-sensitive work. It protects cycle detection, closure changes, source estimates and snapshot boundaries. SQLite proves business invariants only. Cross-connection locking, deadlock behavior and throughput remain phase-7 obligations under the user's SQLite-only instruction.

Ranks use integer units of 1e-8, midpoint insertion and bounded rebalancing of at most 1000 rows. Context version conflicts protect competing clients. Visibility filters run before pagination/counting. Rebalancing requires current edit permission for every affected card. Structural stale positions can be pruned; merely hidden cards cannot be removed to make rebalancing succeed.

Story points are nullable integer hundredths. SQLite's decimal aggregation is not used for totals. Committed sprint points are frozen; active additions use points at join. Daily/closed snapshots reuse those frozen estimates and capture actual state/leaf membership. Snapshot membership history is retained after carry/remove. No past-day snapshot is fabricated or overwritten. Reports group authorized snapshot items in one aggregate query instead of per-snapshot queries. Unknown estimates/categories remain explicit. live_scope and scope_change_points reflect currently authorized open membership immediately; current/history and historical_scope_change_points remain frozen snapshot calculations. Closed reports retain the closed snapshot scope.

Raw time entries are authoritative. In-place entry changes advance task.time_revision; task version also invalidates projections after estimate changes. Task subtree time sums raw entry IDs once rather than summing cached ancestor totals. Timer stop uses the injected clock and rejects clock rollback. Intervals are half-open; adjacency is allowed; strict overlap rejects and warning overlap is durable. Other actors' notes require explicit host-authorized time.manage_others.

Comments are stored as plain stripped text and escaped for HTML rendering. Writer edits are limited to 15 minutes; moderators are checked by capability and authorization provider. Comment/list restoration is limited to 30 days. No destructive retention/purge worker is introduced. Event redaction hides bodies, notes and file references.

AttachmentStorage.inspect verifies opaque host references under explicit context; read is called only after fresh task/resource/ticket checks. Defaults fail closed until a host storage implementation is bound. Download tickets live 1..300 seconds, at most 10 active per actor/file. No raw token enters the command journal. Existing metadata-only attachments require a verified attachment operation before download. Permission or resource-version changes invalidate subsequent reads.

Critical path uses elapsed task estimates and versioned elapsed/business dependency lags for the permitted subgraph. It is a planning calculation including declared blocking links (including advisory constraints), not proof of actual completion. Missing estimate/anchor or an external/hidden blocking dependency yields insufficient_data. Comprehensive working-duration/calendar combinations remain in phase 5/7 as specified by the plan.

All source and migrations are available for review. This checkpoint is not a phase-seven freeze or production release.
