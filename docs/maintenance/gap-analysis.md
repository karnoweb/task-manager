# Corrections audit

Baseline: Task Manager `420a84c`; Ticket Chat local source `147b208091f9f1e6dd7f5bc9bc49329f8b444b1f` (13.1.1), compared with the public reference repository. Ticket Chat is an optional host dependency, never a core dependency.

| Area | Initial finding | Action |
|---|---|---|
| Stable external aliases and host authorization | Implemented: SubjectRegistry/SubjectHandler, explicit SQL scope, host guard | Reuse; add executed Ticket Chat example |
| Instance/operational placement | Implemented: one pipeline per instance; unique active subject slot; board visibility is read-only | Reuse instance fields; add explicit detach and retirement |
| Sequential/free policy selected by pipeline | Missing: immutable edges exist, pipeline only maps states | Add explicit pipeline mode with custom default |
| Business commands | Partially implemented: required fields/guards/reasons exist, no command-only boundary | Add dedicated command entry and capability |
| Department/parent entry routing | Missing | Generic host provider and unique scoped routing key |
| Safe board removal | Missing: pipeline references intentionally restrict physical deletion | Add recoverable retirement/detach; board deletion remains host-owned |
| Department handoff and commercial conversion | Implemented: LocalRouteService, SubjectRouteHandler, CaseService, ExternalSagaService | Reuse; verify real Ticket Chat boundary |
| General reminders | Partially implemented: anchored native-task reminders only | Extend existing reminder table/service; preserve anchored API |
| Non-transition host events | Implemented by explicit subscriptions/automation; no implicit reply transition in core | Verify with real host; document host behavior conflicts |
| Outbox/current authorization/idempotency | Implemented | Extend existing EventAccess for generic reminders |
| PostgreSQL CRM adapter | Incorrect behavior: text/bigint comparison P7-D001 | Portable text comparison plus failing/passing reproduction |
| 37 Dorna criteria | Needs Verification: full story not supplied, 12 examples supplied | Ask for authoritative text; do not invent missing criteria |
| Documentation | Partial: detailed phase documents, no cohesive consumer entry | Replace README entry; runnable examples and topic guides |
| Cleanup | Needs Verification: tracked XML/logs and historical reports have incoming references | Audit references; retain evidence until official replacement exists |

Core ownership remains unchanged: Workflow Instance owns process state; external models and business validation belong to the host; Kanban owns visual views. No mandatory department or board model is introduced.
