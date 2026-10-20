# Phase 2 decisions

1. Subject aliases are configuration-owned stable strings. Class names are never persisted as external identities. SubjectHandler supplies a current authorized SQL query and a same-connection guard. The engine adds its own scope predicate.
2. Workflow revisions are never silently replaced on instances. Project/type map changes affect subsequent conversions; existing instances retain their snapshot until a previewed, version-checked migration.
3. Pipelines map explicit stages to revision states. If two stages map the same state, a unique explicit stage is required. Stage rows serialize WIP capacity; capacity counts are internal and never returned as unauthorized aggregates.
4. Context capabilities are a hard upper bound even when a host authorization provider is bound. Source handlers without a particular view capability are excluded from combined instance reads, allowing an external-only service actor without native task privileges.
5. Project ownership does not bypass membership checks. Converting a personal task clears personal grants. Existing display aliases resolve only through current task visibility. Target project mapping changes require a manager, not a viewer.
6. Board visibility is a read-only domain lookup; actual board membership remains UI-owned. Real Kanban/Calendar adapters are still phase-six work. TM-163/164 here verify the source/identity/permission boundary, not a browser UI.
7. TM-096 needs the foundational parent field before phase-four closure tables; TM-150 needs real audited comment/attachment metadata writes. These minimal prerequisites are implemented now rather than marking simulated operations passed. Complete advanced hierarchy and collaboration features remain phase four.
8. Sensitive resource fields are redacted recursively in events. Task title changes are represented by hashes, field paths, actor, versions and correlation, preserving evidence without copying private titles into raw event logs.
9. The old host writer is detected by host guard/reconciliation contracts. The package cannot prevent arbitrary out-of-band SQL in another application. Phase six must wire actual host write APIs and migration/reconciliation to these contracts.
10. No public release, cross-DB atomicity, full MySQL/version matrix, advanced SLA/assignment or completed UI integration is claimed by this phase.
