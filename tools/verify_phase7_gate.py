"""Record a fail-fast QA cycle without treating incomplete matrix runs as passes."""
from pathlib import Path
import hashlib
import json
import re
import subprocess
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
DOCS = ROOT / 'docs'

def git(*args):
    return subprocess.check_output(['git', '-C', str(ROOT), *args])

freeze = json.loads((DOCS / 'PHASE-6-RC-FREEZE.json').read_text(encoding='utf8'))
candidate = freeze['candidate_commit']
protected = ['src', 'config', 'database', 'composer.json', 'composer.lock',
             'phpunit.xml', 'pint.json', 'phpstan.neon', 'rector.php']
assert not git('diff', candidate, '--name-only', '--', *protected).strip(), 'Candidate product changed'
assert not git('ls-files', '--others', '--exclude-standard', '--', *protected).strip(), 'Unfrozen product file'

def read_run(path):
    root = ET.parse(DOCS / path).getroot()
    suite = root.find('testsuite')
    assert suite is not None
    cases = []
    for case in root.iter('testcase'):
        status = next((tag.upper() for tag in ['error', 'failure', 'skipped'] if case.find(tag) is not None), 'PASS')
        cases.append({'class': case.get('class') or case.get('classname'), 'name': case.get('name'),
                      'status': status, 'assertions': int(case.get('assertions', '0'))})
    return {'file': path, 'sha256': hashlib.sha256((DOCS / path).read_bytes()).hexdigest(),
            'totals': {key: suite.get(key) for key in ['tests', 'assertions', 'errors', 'failures', 'skipped', 'time']},
            'cases': cases}

sqlite = read_run('phase7-sqlite.xml')
repro = read_run('phase7-crm-reproduction-postgres.xml')
assert sqlite['totals']['errors'] == '0' and sqlite['totals']['failures'] == '0'
assert repro['totals']['errors'] == '2', 'Reproduction changed: review the defect before updating this gate'
source = (ROOT / 'dev/TASK-MANAGER-ENTERPRISE-TEST-SCENARIOS-FA.md').read_text(encoding='utf8')
titles = {}
for line in source.splitlines():
    match = re.match(r'\| ((?:TM|REG)-\d{3}) \| (.*?) \|', line) or re.match(r'### ((?:NET-\d{3}|E2E-\d{2})) — (.*)', line)
    if match:
        titles.setdefault(match[1], match[2])
requirements = []
for prefix, count, width in [('TM', 236, 3), ('E2E', 6, 2), ('NET', 40, 3), ('REG', 80, 3)]:
    for number in range(1, count + 1):
        identity = f'{prefix}-{number:0{width}d}'
        matches = []
        if prefix == 'TM':
            matches = [case for case in sqlite['cases'] if re.search(rf'(?:\\|\.)TM{number:03}Test$', case['class'] or '')]
        elif prefix == 'E2E':
            matches = [case for case in sqlite['cases'] if re.search(rf'(?:\\|\.)E2E{number:02}Test$', case['class'] or '') or case['name'].startswith(f'test_e2e_{number:02}_')]
        observed = ('BASELINE_PASS' if all(case['status'] == 'PASS' for case in matches) else 'BASELINE_INCOMPLETE') if matches else 'NOT_RUN_AS_COMPLETE_SCENARIO'
        requirements.append({'id': identity, 'requirement': titles.get(identity, ''), 'sqlite_observation': observed,
                             'tests': matches, 'acceptance': 'NOT_ACCEPTED',
                             'reason': 'Mandatory cross-engine and complete scenario gates have not passed; cycle stopped after confirmed phase-six blocker.'})
assert len(requirements) == 362 and all(item['requirement'] for item in requirements)
gate = {'phase': 7, 'cycle': 1, 'decision': 'NO-GO', 'cycle_status': 'STOPPED_AFTER_CONFIRMED_BLOCKER',
        'candidate_commit': candidate, 'candidate_product_unchanged': True,
        'composer_lock_sha256': hashlib.sha256(git('show', candidate + ':composer.lock')).hexdigest(),
        'complete_runs': [sqlite, repro],
        'interrupted_runs': ['PostgreSQL full matrix', 'MariaDB full matrix'],
        'interruption_reason': 'Fail-fast after isolated PostgreSQL CRM blocker; partial console logs are diagnostic only, not completed test results.',
        'open_defects': ['P7-D001'], 'requirement_count': 362, 'accepted_requirements': 0,
        'oracle_mysql8': 'NOT_AVAILABLE_OR_RUN; MariaDB is not Oracle MySQL',
        'remaining_gates': ['236 TM cross-engine acceptance', '6 complete E2E across engines', '40 NET independent full scenarios',
                            '80 REG independent full scenarios', 'MySQL 8 matrix', 'full chaos and external HTTP transport',
                            'complete 100k campaign and 1k process load', 'browser host and accessibility',
                            'PHP/Laravel compatibility matrix', 'operational migration/upgrade recovery'],
        'next_action': 'Return P7-D001 to phase six; fix outside phase seven, freeze a fresh candidate, and rerun the whole phase-seven cycle.'}
(DOCS / 'PHASE-7-GATE.json').write_text(json.dumps(gate, ensure_ascii=False, indent=2) + '\n', encoding='utf8')
(DOCS / 'PHASE-7-REQUIREMENTS.json').write_text(json.dumps(requirements, ensure_ascii=False, indent=2) + '\n', encoding='utf8')
lines = ['# Phase-seven requirements → tests → observations', '',
         'Cycle 1 is NO-GO. BASELINE_PASS only describes the SQLite test body; it is not full acceptance.', '',
         '| ID | SQLite observation | Completed test methods | Acceptance |', '|---|---|---|---|']
for item in requirements:
    tests = '<br>'.join(case['class'] + '::' + case['name'] for case in item['tests']) or '—'
    lines.append(f"| {item['id']} | {item['sqlite_observation']} | {tests} | NOT_ACCEPTED |")
(DOCS / 'PHASE-7-REQUIREMENTS.md').write_text('\n'.join(lines) + '\n', encoding='utf8')
print(json.dumps({'decision': gate['decision'], 'candidate': candidate, 'requirements': 362,
                  'sqlite': sqlite['totals'], 'postgres_reproduction': repro['totals']}))
