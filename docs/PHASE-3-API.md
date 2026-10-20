# Phase-three API

TaskManager exposes stagePolicies() and sla(). StagePolicyService.publishSla requires context, pipeline key, stage key, expected pipeline version, operation ID and a policy. Policies specify positive duration, elapsed/business clock, versioned calendar reference, warning offset, inactivity duration, reentry mode, dead-candidate labeling, pause reasons/limit and an optional explicit breach transition/reason.

SlaService.get authorizes the instance/subject before returning visit clock data. pause/resume/reset require an expected SLA version, operation ID and the corresponding capability. Pause records an interval and remaining budget; resume recalculates the deadline with the selected working clock; reset deliberately starts a new epoch. These operations are distinct and audited.

SlaService.run requires explicit sla.run scope/capabilities, selects bounded currently visible due visits and deduplicates thresholds. It returns event/skipped counts, not hidden subject details. A breach alone marks/notifies; automated business changes require a configured edge, workflow.transition and current host acceptance. Denial becomes a manual-review result.

WorkingCalendar and immutable CalendarRegistry allow versioned host calendars. WeeklyWorkingCalendar uses IANA zones, explicit ISO weekdays, half-open local shifts and holiday dates. Nonexistent/ambiguous DST shift boundaries fail explicitly. It does not implement Jalali recurrence, which remains phase-five scope.

TaskManager.assignments() exposes assignment get/assign, explicit instance policy configuration, stage policy publication and member-departure policies. Every mutation requires assignment.manage, the current process version and a unique operation. ResponsibilityResolver supplies current scoped eligibility/candidates; the default resolver permits only the context actor and infers no host organization. Instance overrides are explicit and persist across stage changes; stage policies otherwise apply to new visits. Active primary uniqueness is enforced in the database. Ownership and read permissions are independent of assignment.

TaskManager.approvals() exposes immutable stage policy configuration, manual begin, active/get, vote, explicit execute and administrative cancel. Voting requires approval.vote, current view permission and host eligibility; it does not grant task edit. State exit requires the policy's resolved outcome transition. A pending executor or host veto is represented explicitly rather than reported as completed business state.

TaskManager.handoffs() exposes get/delegate/parallel/settle/recall/run. Delegation requires an expected source version, independent target slot, accepted pipeline/revision and explicit immutable return policy. Settle reads terminal destination evidence. Recall/timeout require a configured guarded cancellation transition. Parallel groups declare qualifying outcomes, priority and all/any/quorum at creation; remaining independent destinations are explicitly detached after the join. Bounded run recovers committed destination outcomes after a worker restart. See PHASE-3-HANDOFFS.md for limitations and capabilities.

TaskManager.routes() exposes same-connection owner-service move/spawn. TaskManager.sagas() exposes remote pending/confirm/compensate, durable receipt lookup, bounded recovery and explicit versioned repair. See PHASE-3-SUBJECT-ROUTES.md.

TaskManager.groups() exposes register/publishPolicy/enroll/previewClosure/previewDelta/executeClosure/process/runInfo/report. TaskManager.milestones() exposes committed-intent get/verify/run. Source campaign closure and commit evidence require trusted registered providers; there is no browser timestamp setter. Reports use current parent SQL authorization. See PHASE-3-GROUPS.md.

Handoff getGroup reauthorizes current member visibility. manualJoin/retryReturn/closeConflict require a separate handoff.repair capability, expected versions and an append-only repair ledger. Original policy/source snapshots are retained. Installed commercial host integration, full database/scale matrix and phase-seven acceptance still require later evidence.
