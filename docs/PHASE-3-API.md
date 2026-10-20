# SLA API checkpoint

TaskManager exposes stagePolicies() and sla(). StagePolicyService.publishSla requires context, pipeline key, stage key, expected pipeline version, operation ID and a policy. Policies specify positive duration, elapsed/business clock, versioned calendar reference, warning offset, inactivity duration, reentry mode, dead-candidate labeling, pause reasons/limit and an optional explicit breach transition/reason.

SlaService.get authorizes the instance/subject before returning visit clock data. pause/resume/reset require an expected SLA version, operation ID and the corresponding capability. Pause records an interval and remaining budget; resume recalculates the deadline with the selected working clock; reset deliberately starts a new epoch. These operations are distinct and audited.

SlaService.run requires explicit sla.run scope/capabilities, selects bounded currently visible due visits and deduplicates thresholds. It returns event/skipped counts, not hidden subject details. A breach alone marks/notifies; automated business changes require a configured edge, workflow.transition and current host acceptance. Denial becomes a manual-review result.

WorkingCalendar and immutable CalendarRegistry allow versioned host calendars. WeeklyWorkingCalendar uses IANA zones, explicit ISO weekdays, half-open local shifts and holiday dates. Nonexistent/ambiguous DST shift boundaries fail explicitly. It does not implement Jalali recurrence, which remains phase-five scope.

Assignment, approval, handoff and group APIs are still pending. This is not the final phase-three public API contract.
