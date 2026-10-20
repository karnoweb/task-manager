# Phase 3 decisions checkpoint

- SLA labels and workflow business state remain separate. Stale/breached/dead-candidate never imply automatic failure without an explicit guarded transition.
- Deadline policy is immutable per visit; the next visit reads the then-current stage policy. Reentry reset/cumulative comparisons therefore use two independently configured pipelines in the acceptance fixture. Republishing one shared stage policy intentionally changes subsequent visits for both instances using that stage.
- Clock pause/resume keeps the remaining budget; reset changes the anchor and epoch. Metrics preserve actual paused intervals and full visit history.
- All times are UTC in storage and tests use a controlled clock. Business windows are defined in an IANA timezone; weekend/holiday handling is explicit. Unsupported or ambiguous calendar input fails closed.
- Threshold generation and durable flags allow duplicate workers/retries without duplicate notifications or domain actions. A typed dead-candidate eligibility column ensures recovery still selects the visit after breach/stale markers committed but before the final label committed.
- Terminal stage visits are explicitly closed at entry. This corrects a process-history omission found while implementing guarded terminal SLA actions; it does not alter the state-source-of-truth rule.
- The checkpoint also covers TM-024, TM-146 and TM-188..191 for responsibility. The host eligibility resolver is explicit; no organization/user schema or global auth state is inferred. Assignment never grants visibility. Fixed instance overrides retain actual primary/secondary targets across reopen, while stage policies may resolve teams or a locked scoped round-robin queue.
- Approval votes are durable decisions; guarded business execution can remain pending/manual-review. Current source versions and host permissions are rechecked. Sequential, parallel and quorum policies remain immutable; pending approvals block bypass commands.
- Handoff/Saga/milestone/campaign features remain openly incomplete. No structural placeholders are counted as passing those scenarios.

