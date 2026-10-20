# Stage SLA schema checkpoint

Migration 000005 adds immutable tm_stage_policies, a nullable stage policy pointer, per-visit policy/time/flag/version/budget/epoch fields, tm_sla_pauses and deduplicated tm_sla_events. No commercial host schema is added. Policies referenced by a visit are never rewritten by a later publication.

Dead-candidate policy eligibility is a typed boolean used by recovery queries. Due/warning/inactivity timestamps are indexed by scope. Threshold uniqueness is (visit, policy, generation, event key). Inactivity cycles have their own generation, so a meaningful action can begin another inactivity window without repeating an already-issued breach. Explicit reset starts a new deadline epoch; resume preserves remaining budget.

Writes lock the authorized host/native subject and instance before the visit. Watchdogs recheck current lifecycle, visit exit/pause and policy/epoch under those locks. Domain transitions use the existing workflow engine; a denied automated transition records manual_review rather than directly writing a failed business state. Outbox/event records share the write transaction.

Downgrade refuses to remove populated policy/visit/pause/event data without an export path. Pipeline/stage/visit foreign keys remain internal. MySQL compatibility and production-scale watchdog query plans are still unverified and must be included in final acceptance.
