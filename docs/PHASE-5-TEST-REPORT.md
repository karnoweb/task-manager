# Phase 5 test report

Final verification is SQLite-only, as explicitly requested by the user. This checkpoint is not a release decision or phase-seven acceptance.

| Evidence | Actual result |
|---|---|
| Full final regression (`phase5-all-sqlite.xml`) | 506 tests / 4602 assertions / 0 errors / 0 failures / 22 engine-specific skips |
| All owned phase-five scenarios | 42 IDs / 42 actual data cases / 1469 assertions / no failure or skip |
| SQL paging scale | Real SQLite datasets of 10k and 100k native tasks, workflow instances and stage visits; 80% visible, 10% foreign actor, 10% foreign scope |
| Bounded read checks | Two disjoint 100-row pages; authorized count; at most 13 SQL queries including cursor bookkeeping; PHP allocation delta below 16 MiB |
| Transport boundary | Controlled DNS/vault/HTTP/notification fixtures; real HMAC computation and real private export file reads; no external messages sent |

The owned subset XML is extracted from the final full JUnit report. `python tools/verify_phase5_gate.py` verifies every actual owned case, checks its PHP/Persian Markdown artifact and regenerates the gate. The 13-query bound includes permission fingerprint, count, page, cursor validation/storage and bounded expired-cursor cleanup; it does not grow with dataset size. An earlier 12-query budget failed after cleanup was added; inspecting the SQL showed 13 fixed queries, and the invariant was corrected before the final complete rerun.

Executed feature checks cover half-open spans/due-only/all-day, DST gaps/folds, weekly local hours across DST, Gregorian missing days, real Persian months/leap Esfand/30th/31st, UNTIL/stop, distinct exception identity and range, completed occurrence history, after-completion generation, reminder generation/dedupe/snooze/quiet preferences and old queued-intent suppression, safe checklist presets, typed numeric/date filters/sort/multiselect/archive/read capabilities, normalized search and permission-bound cursors, action whitelist/guarded execution/idempotency/depth/budget/throttle, immutable rule toggle, transaction rollback/after-commit recovery, consumer retries/revocation/schema failures, signed scoped redacted webhook bodies, private/mixed DNS/metadata/redirect rejection and post-DNS revocation, bounded HTTP retry/dead-letter/manual retry, streaming formula-safe exports and fresh private-file authorization, current/historical process/SLA/group metrics, projection rebuild and replay revocation.

Actual handoff return triggers a guarded automation transition once. Its webhook is checked through the controlled transport. Group closure export applies current external subject permissions before rows and denies an earlier artifact after host permission is revoked. The advanced critical-path fixture shares a versioned work calendar including a working weekend and a holiday. Planning dates, calendar spans and start reminders now update together while actual transition clocks stay unchanged.

Self-review corrected an equivalent-search cursor mismatch; legacy Task producer aggregate/lineage metadata; stale reminder generations at delivery; resend after an unchanged absolute deadline; quiet-hour retry-budget consumption; unknown-schema visibility; moved-exception range listing; host field read-policy filtering; role revocation during DNS resolution; cached/replayed report visibility after external ACL changes; and the planning/calendar/reminder bridge. Focused reproductions and the complete final rerun pass.

## Explicit deferred evidence

No phase-five PostgreSQL, MariaDB or Oracle MySQL test ran. The existing 22 engine-specific cases remain skipped. TM-060 and TM-121 establish sequential independent-generator/idempotency/unique-key behavior on SQLite; they do not establish a physical two-connection race. Multi-process leases/crash timing, network TLS/DNS/HTTP integration, installed-host adapters and release load/version matrices remain phase-seven obligations under the user's current test instruction. The scale fixtures prove bounded functional reads, not final throughput or total database RSS.

Core Todo has no required CRM/Kanban/Calendar dependency. Jalali requires ext-intl; default HTTP webhook transport requires ext-curl. The default secret vault/notification transport deliberately require host bindings. Gregorian recurrence is the documented bounded RFC5545 profile, not an assertion of every possible rule part.

Phase six has not started. Stop and report at this boundary, as requested.
