# Responsibility implementation checkpoint

Migration 000006 adds immutable assignment policy rows, stage/instance/visit policy references, assignment snapshots, append-only assignment intervals, real active primary/secondary slots and scope/reference queue positions. Slot keys enforce one primary per instance and actor uniqueness; no NULL-based active-row uniqueness is assumed. There are no host-user foreign keys.

ResponsibilityResolver supplies current scoped host eligibility and team candidates. The default resolver recognizes only the current actor, so deployments assigning other people must bind a real host resolver. Round-robin locks a shared scope/reference queue row and resolves a deterministic sorted eligible candidate list. Empty/revoked candidates abort activation with assignment_unresolvable, preserving the original stage and transaction.

Fixed/manual/follow-owner/team/round-robin policies are versioned. Explicit instance overrides take precedence and preserve actual fixed assignees across stage changes/reopen. Stage policy publication affects new visits. Assignment does not modify owner_ref or grant host/task query visibility. Explicit allow_unassigned is required to remove the last primary actor.

AssignmentService mutations require assignment.manage and the current process version. Native task aggregate versions also advance. Departures explicitly reject, unassign or reassign to the owner under the selected policy; invalid/unavailable replacements fail closed. History intervals close on stage exit or native completion and restore as new intervals on reopen. Stage assignment events share the parent operation/correlation and transaction.

The assignment foundation scenarios are 024,146,188..191. TM-192 now verifies all three explicit handoff return policies (restore origin, keep destination assignment, resolve return stage), current eligibility, primary uniqueness and preserved ownership. Approval eligibility and guarded sequential/parallel/quorum execution are implemented separately; see PHASE-3-APPROVALS.md. These subsets do not complete phase three.
