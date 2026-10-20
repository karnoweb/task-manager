"""Verify actual final JUnit evidence; never promote deferred database/race coverage."""
from pathlib import Path
import copy
import json
import re
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
OWNED = [*range(51, 65), 89, 93, 94, *range(112, 122), *range(133, 143), *range(214, 219)]
RACE = {60, 121}
source = ROOT / 'docs/phase5-all-sqlite.xml'
tree = ET.parse(source)
suite = tree.getroot().find('testsuite')
assert suite is not None, 'Missing final suite'
assert suite.get('errors') == '0' and suite.get('failures') == '0', 'Final regression failed'
cases = tree.findall('.//testcase')
assert len(cases) == int(suite.get('tests')), 'Incomplete JUnit test count'
groups = {number: [] for number in OWNED}
for case in cases:
    match = re.search(r'\\Scenarios\\TM(\d{3})Test$', case.get('class', ''))
    if match and int(match[1]) in groups:
        groups[int(match[1])].append(case)
results = []
for number, data in groups.items():
    assert data, f'TM-{number:03} not executed'
    assert all(not any(case.find(tag) is not None for tag in ['failure', 'error', 'skipped']) for case in data), f'TM-{number:03} has non-passing cases'
    assert (ROOT / f'tests/Scenarios/TM{number:03}Test.php').is_file()
    assert (ROOT / f'docs/TEST-SCENARIOS/TM-{number:03}.md').is_file()
    results.append({'id': f'TM-{number:03}', 'result': 'PASS_SQLITE_SCOPE', 'data_cases': len(data),
                    'physical_concurrency': 'DEFERRED_TO_PHASE_7' if number in RACE else 'NOT_CLAIMED'})
counts = {key: int(suite.get(key, '0')) for key in ['tests', 'assertions', 'errors', 'failures', 'skipped']}
owned_cases = [case for group in groups.values() for case in group]
owned_assertions = sum(int(case.get('assertions', '0')) for case in owned_cases)
gate = {'phase': 5, 'gate': 'PASS_SQLITE_SCOPE', 'owned_scenarios': len(OWNED), 'verified_from': source.name,
        'test_scope_authorization': 'User requested SQLite only until phase 7; stop/report at each phase boundary.',
        'phase_seven_acceptance': False, 'database_matrix': 'DEFERRED_TO_PHASE_7',
        'physical_concurrency': 'DEFERRED_TO_PHASE_7', 'regression': counts,
        'network_transport': 'CONTROLLED_FIXTURES_NO_EXTERNAL_DELIVERY',
        'scale': {'dataset_sizes': [10000,100000], 'query_bound_two_pages':13, 'page_size':100, 'php_allocation_delta_limit_bytes':16777216},
        'owned_data_cases': len(owned_cases), 'owned_assertions': owned_assertions, 'results': results}
(ROOT / 'docs/PHASE-5-GATE.json').write_text(json.dumps(gate, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
extracted = ET.Element('testsuites')
subset = ET.SubElement(extracted, 'testsuite', {'name': 'Phase 5 owned cases extracted from final SQLite regression',
    'tests': str(len(owned_cases)), 'assertions': str(owned_assertions), 'errors': '0', 'failures': '0', 'skipped': '0',
    'time': f"{sum(float(case.get('time', '0')) for case in owned_cases):.6f}"})
for case in owned_cases:
    subset.append(copy.deepcopy(case))
ET.indent(extracted)
ET.ElementTree(extracted).write(ROOT / 'docs/phase5-owned-sqlite.xml', encoding='utf-8', xml_declaration=True)
status = ROOT / 'docs/ACCEPTANCE-STATUS.md'
text = status.read_text(encoding='utf-8')
if 'Phase-five owned cases passed the user-authorized SQLite scope;' not in text:
    text = text.replace('Final installed host integration and cross-module/UI acceptance remain pending.',
        'Phase-five owned cases passed the user-authorized SQLite scope; physical generators and real transport remain later evidence. Final installed host integration and cross-module/UI acceptance remain pending.')
for number in OWNED:
    text = re.sub(rf'\| TM-{number:03} \| [^\n]+', f'| TM-{number:03} | PASS (SQLite phase-five scope; comprehensive phase-seven acceptance pending) |', text)
status.write_text(text, encoding='utf-8')
report = f'''# Phase 5 test report

Final verification is SQLite-only, as explicitly requested by the user. This checkpoint is not a release decision or phase-seven acceptance.

| Evidence | Actual result |
|---|---|
| Full final regression (`phase5-all-sqlite.xml`) | {counts['tests']} tests / {counts['assertions']} assertions / {counts['errors']} errors / {counts['failures']} failures / {counts['skipped']} engine-specific skips |
| All owned phase-five scenarios | {len(OWNED)} IDs / {len(owned_cases)} actual data cases / {owned_assertions} assertions / no failure or skip |
| SQL paging scale | Real SQLite datasets of 10k and 100k native tasks, workflow instances and stage visits; 80% visible, 10% foreign actor, 10% foreign scope |
| Bounded read checks | Two disjoint 100-row pages; authorized count; at most 13 SQL queries including cursor bookkeeping; PHP allocation delta below 16 MiB |
| Transport boundary | Controlled DNS/vault/HTTP/notification fixtures; real HMAC computation and real private export file reads; no external messages sent |

The owned subset XML is extracted from the final full JUnit report. `python tools/verify_phase5_gate.py` verifies every actual owned case, checks its PHP/Persian Markdown artifact and regenerates the gate. The 13-query bound includes permission fingerprint, count, page, cursor validation/storage and bounded expired-cursor cleanup; it does not grow with dataset size. An earlier 12-query budget failed after cleanup was added; inspecting the SQL showed 13 fixed queries, and the invariant was corrected before the final complete rerun.

Executed feature checks cover half-open spans/due-only/all-day, DST gaps/folds, weekly local hours across DST, Gregorian missing days, real Persian months/leap Esfand/30th/31st, UNTIL/stop, distinct exception identity and range, completed occurrence history, after-completion generation, reminder generation/dedupe/snooze/quiet preferences and old queued-intent suppression, safe checklist presets, typed numeric/date filters/sort/multiselect/archive/read capabilities, normalized search and permission-bound cursors, action whitelist/guarded execution/idempotency/depth/budget/throttle, immutable rule toggle, transaction rollback/after-commit recovery, consumer retries/revocation/schema failures, signed scoped redacted webhook bodies, private/mixed DNS/metadata/redirect rejection and post-DNS revocation, bounded HTTP retry/dead-letter/manual retry, streaming formula-safe exports and fresh private-file authorization, current/historical process/SLA/group metrics, projection rebuild and replay revocation.

Actual handoff return triggers a guarded automation transition once. Its webhook is checked through the controlled transport. Group closure export applies current external subject permissions before rows and denies an earlier artifact after host permission is revoked. The advanced critical-path fixture shares a versioned work calendar including a working weekend and a holiday. Planning dates, calendar spans and start reminders now update together while actual transition clocks stay unchanged.

Self-review corrected an equivalent-search cursor mismatch; legacy Task producer aggregate/lineage metadata; stale reminder generations at delivery; resend after an unchanged absolute deadline; quiet-hour retry-budget consumption; unknown-schema visibility; moved-exception range listing; host field read-policy filtering; role revocation during DNS resolution; cached/replayed report visibility after external ACL changes; and the planning/calendar/reminder bridge. Focused reproductions and the complete final rerun pass.

## Explicit deferred evidence

No phase-five PostgreSQL, MariaDB or Oracle MySQL test ran. The existing {counts['skipped']} engine-specific cases remain skipped. TM-060 and TM-121 establish sequential independent-generator/idempotency/unique-key behavior on SQLite; they do not establish a physical two-connection race. Multi-process leases/crash timing, network TLS/DNS/HTTP integration, installed-host adapters and release load/version matrices remain phase-seven obligations under the user's current test instruction. The scale fixtures prove bounded functional reads, not final throughput or total database RSS.

Core Todo has no required CRM/Kanban/Calendar dependency. Jalali requires ext-intl; default HTTP webhook transport requires ext-curl. The default secret vault/notification transport deliberately require host bindings. Gregorian recurrence is the documented bounded RFC5545 profile, not an assertion of every possible rule part.

Phase six has not started. Stop and report at this boundary, as requested.
'''
(ROOT / 'docs/PHASE-5-TEST-REPORT.md').write_text(report, encoding='utf-8')
print(json.dumps({'gate': gate['gate'], 'regression': counts, 'owned_ids': len(OWNED), 'owned_cases': len(owned_cases), 'owned_assertions': owned_assertions}))
