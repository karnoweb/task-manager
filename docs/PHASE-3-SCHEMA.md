# Stage SLA schema checkpoint

Migration 000005 adds immutable tm_stage_policies, a nullable stage policy pointer, per-visit policy/time/flag/version/budget/epoch fields, tm_sla_pauses and deduplicated tm_sla_events. No commercial host schema is added. Policies referenced by a visit are never rewritten by a later publication.

Dead-candidate policy eligibility is a typed boolean used by recovery queries. Due/warning/inactivity timestamps are indexed by scope. Threshold uniqueness is (visit, policy, generation, event key). Inactivity cycles have their own generation, so a meaningful action can begin another inactivity window without repeating an already-issued breach. Explicit reset starts a new deadline epoch; resume preserves remaining budget.

Writes lock the authorized host/native subject and instance before the visit. Watchdogs recheck current lifecycle, visit exit/pause and policy/epoch under those locks. Domain transitions use the existing workflow engine; a denied automated transition records manual_review rather than directly writing a failed business state. Outbox/event records share the write transaction.

Downgrade refuses to remove populated policy/visit/pause/event data without an export path. Pipeline/stage/visit foreign keys remain internal. MySQL compatibility and production-scale watchdog query plans are still unverified and must be included in final acceptance.

## Additional phase-three migrations

- 000006: responsibility policy snapshots, assignment history, active assignment slots and scoped round-robin queues.
- 000007: immutable approval policies, requests, eligible steps/votes, durable decisions and pending guarded execution.
- 000008: handoffs, parallel groups, origin reservations and append-only terminal outcome evidence.
- 000009: local subject-route lineage, external Saga state and external case references.
- 000010: process groups, immutable lifecycle policy revisions, temporal enrollment/active reservations, committed milestone intents/evidence, operation runs and per-item claim/result history.
- 000011: accepted repair version separate from the original handoff snapshot and append-only repair actions.

Internal foreign keys restrict deletion. Populated phase-three downgrade requires an explicit export/recovery path; it cannot silently discard process evidence. The group claim index covers run/state/lease expiry/member ordering. Active group and assignment reservations enforce portable uniqueness without relying on nullable unique columns.

Milestone timestamps use microsecond UTC storage. The native PostgreSQL provider needs retained commit tracking; other engines need a trusted host evidence provider and otherwise remain pending/manual_review. MariaDB validation is recorded separately from Oracle MySQL.

The actual EnvKit MariaDB endpoint is 127.0.0.1:3306. Tests use only tm_acceptance_p3_72bd61 and a localhost account granted privileges solely on that database; application database credentials are not embedded in the profile. The profile intentionally retains an insensitive database default to verify that owned table migrations enforce exact comparisons independently. EnvKit service data and settings were not changed.
