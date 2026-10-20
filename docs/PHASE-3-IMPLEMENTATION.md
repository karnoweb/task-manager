# Phase 3 progress — SLA subset, not a completed phase

Completed in this checkpoint: immutable stage SLA policy revisions; per-visit policy snapshots; elapsed/business clocks; versioned calendar registry; Iranian working-week/holiday handling; exact warning/breach boundaries; reset/cumulative reentry; explicit pause/resume with remaining budget and pause intervals; independent reset epochs; inactivity/stale/dead-candidate labels; guarded optional automatic transitions; scoped bounded watchdog; persistent threshold deduplication; notification intents via the outbox; recovery of a pending dead-candidate threshold after earlier markers committed.

StageLifecycle integrates stage entry/exit/activity with the process engine. Terminal pipeline stages now receive a closed visit of their own, preserving complete visit history without leaving a terminal stage active. Legacy Todo completion refuses to bypass a custom workflow or pipeline.

Owned scenarios implemented here: TM-196, TM-197, TM-198, TM-200, TM-201, TM-202, TM-203, TM-204. They have independent PHP/Markdown files. Supplemental SLA tests cover pause/reset distinctions, immutable policy snapshots, clock rollback, reason/version/capability guards, authorized and vetoed automated transitions, DST boundary rejection, repeated stale epochs and watchdog crash-boundary recovery.

Still required before the full phase-three gate: configurable assignment/resolvers/round-robin/member departure, multiple assignees, sequential/parallel/quorum approvals, handoff modes and versioned return policies, joins/lineage/timeout/recall, real host spawn/Saga confirmation/compensation, trusted committed milestones, campaign preview/enrollment/cutoff policies and resumable leased/chunked group execution. The 75/15/10 campaign acceptance scenario has NOT been implemented or run. No phase-four work is declared started.

This file explicitly does not certify all 58 phase-three scenarios, E2E flows or release readiness.
