"""Verify actual final JUnit evidence; never promote deferred database/race coverage."""
from pathlib import Path
import copy
import json
import re
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
OWNED = [13, *range(27, 51), *range(65, 73), 74, 75, 90, *range(101, 112), *range(122, 126), 147]
RACE = {37, 102, 103, 109}
source = ROOT / 'docs/phase4-all-sqlite.xml'
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
gate = {'phase': 4, 'gate': 'PASS_SQLITE_SCOPE', 'owned_scenarios': len(OWNED), 'verified_from': source.name,
        'test_scope_authorization': 'User requested SQLite only until phase 7; stop/report at each phase boundary.',
        'phase_seven_acceptance': False, 'database_matrix': 'DEFERRED_TO_PHASE_7',
        'physical_concurrency': 'DEFERRED_TO_PHASE_7', 'regression': counts,
        'owned_data_cases': len(owned_cases), 'owned_assertions': owned_assertions, 'results': results}
(ROOT / 'docs/PHASE-4-GATE.json').write_text(json.dumps(gate, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
extracted = ET.Element('testsuites')
subset = ET.SubElement(extracted, 'testsuite', {'name': 'Phase 4 owned cases extracted from final SQLite regression',
    'tests': str(len(owned_cases)), 'assertions': str(owned_assertions), 'errors': '0', 'failures': '0', 'skipped': '0',
    'time': f"{sum(float(case.get('time', '0')) for case in owned_cases):.6f}"})
for case in owned_cases:
    subset.append(copy.deepcopy(case))
ET.indent(extracted)
ET.ElementTree(extracted).write(ROOT / 'docs/phase4-owned-sqlite.xml', encoding='utf-8', xml_declaration=True)
status = ROOT / 'docs/ACCEPTANCE-STATUS.md'
text = status.read_text(encoding='utf-8')
if 'Phase-four owned cases passed the user-authorized SQLite scope;' not in text:
    text = text.replace('Final installed host integration and cross-module/UI acceptance remain pending.',
        'Phase-four owned cases passed the user-authorized SQLite scope; database matrix and physical races are deferred to phase seven. Final installed host integration and cross-module/UI acceptance remain pending.')
for number in OWNED:
    text = re.sub(rf'\| TM-{number:03} \| [^\n]+', f'| TM-{number:03} | PASS (SQLite phase-four scope; comprehensive phase-seven acceptance pending) |', text)
status.write_text(text, encoding='utf-8')
report = f'''# Phase 4 test report

Final verification: SQLite only, as explicitly requested by the user. Phase-four implementation is complete within this test scope. This is not final acceptance or a release decision.

| Evidence | Observed result |
|---|---|
| Complete final regression (`phase4-all-sqlite.xml`) | {counts['tests']} tests / {counts['assertions']} assertions / {counts['errors']} errors / {counts['failures']} failures / {counts['skipped']} engine-specific skips |
| Independent owned scenarios | {len(OWNED)} IDs / {len(owned_cases)} actual data cases / {owned_assertions} assertions / no failure or skip |
| 100-task project | Depth 8, separate Bug revision, real dependency guards, sprint snapshots, 92 leaves / 92 points / 2 delivered points, raw subtree time counted once |
| Child approval safety | Pending child approval blocks direct parent completion and terminal subtree mapping; failed transaction leaves project/tasks/outbox unchanged |
| Immediate active-sprint scope reporting | New membership appears before any daily snapshot; historical committed/daily snapshots stay immutable |

The owned subset XML is extracted from the final full regression, not a second execution or a guessed status. `python tools/verify_phase4_gate.py` verifies every actual owned data case and rebuilds `PHASE-4-GATE.json`. Each ID has an independent executable PHP body and Persian Markdown document.

Implemented checks cover hierarchy progress/cycles/depth/atomic reparent, controlled subtree project moves, all four actual dependency types and signed/business lags, read-only date previews, strict missing dates, both endpoint permissions, checklist conversion retry, comment history/safe rendering/window/retention, mentions/watchers/revocation, real local-file reads and bound tickets, manual/timer time truth and overlap, stale projection rebuild, frozen sprint history/carry/dedupe/leaf velocity, exact dense ranking/version conflicts, private immutable templates, permission-filtered reporting and atomic/partial bulk.

Self-review corrected native initial in-progress dependency bypass; historical actual-clock categorization; report use of latest native main instance; unknown-point velocity; stale structural positions; marker progress after project departure; and delayed active-sprint scope reporting. The regression includes focused reproductions. Existing foundation/process tests remain part of the final suite.

## Explicit deferred evidence

No PostgreSQL, EnvKit MariaDB or Oracle MySQL test was run for phase four. The {counts['skipped']} skips belong to existing engine-specific tests. TM-037, TM-102, TM-103 and TM-109 currently prove SQLite sequential conflicting-client/version/invariant behavior only. Multiple physical connections/workers, graph locks, timer reservations under real contention, deadlock/retry behavior, the database/version matrix and full load remain phase-7 obligations. These are not recorded as proven races.

Coarse scope graph locking favors correctness over write concurrency and requires later throughput validation. No installed-host/browser integration or phase-five feature is claimed. Earlier PostgreSQL/MariaDB reports are historical phase-three evidence and do not verify these new migrations.

The next boundary is phase five. Per the user's instruction, stop and report here before starting it.
'''
(ROOT / 'docs/PHASE-4-TEST-REPORT.md').write_text(report, encoding='utf-8')
print(json.dumps({'gate': gate['gate'], 'regression': counts, 'owned_ids': len(OWNED), 'owned_cases': len(owned_cases), 'owned_assertions': owned_assertions}))
