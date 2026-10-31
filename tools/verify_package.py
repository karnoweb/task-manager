"""Verify a current SQLite JUnit run and the maintained scenario catalog.

Replaces historical phase gate/freeze scripts. Never reconstructs old phase passes
or writes generated reports into docs. It does not execute any database test.
"""
import argparse
import json
from pathlib import Path
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--junit', default='.runtime/simplification/full-sqlite.xml')
args = parser.parse_args()
path = ROOT / args.junit
suite = ET.parse(path).getroot()
cases = list(suite.iter('testcase'))
assert cases, 'No executed tests'
failures = [case for case in cases if case.find('failure') is not None or case.find('error') is not None]
assert not failures, [(case.get('class'), case.get('name')) for case in failures]
manifest = json.loads((ROOT/'tests/Scenarios/Complete/manifest.json').read_text(encoding='utf8'))
assert len(manifest) == 15
for scenario in manifest:
    assert (ROOT/scenario['file']).is_file()
    assert (ROOT/'tests/Scenarios/Complete'/f"{scenario['id']}.md").is_file()
    for method in scenario['methods']:
        matching = [case for case in cases if (case.get('class') or case.get('classname')) == scenario['class'] and case.get('name') == method]
        assert len(matching) == 1, (scenario['id'], method, 'missing result')
        assert matching[0].find('skipped') is None, (scenario['id'], method, 'not verified')
mapping = (ROOT/'tests/Scenarios/COVERAGE-MAPPING.md').read_text(encoding='utf8')
preserved = list((ROOT/'tests/Scenarios').glob('TM*Test.php'))
assert len(preserved) == 236
assert all(test.name in mapping for test in preserved)
report = {
    'junit': args.junit,
    'tests': len(cases),
    'assertions': sum(int(case.get('assertions', '0')) for case in cases),
    'skipped': sum(case.find('skipped') is not None for case in cases),
    'scenarios': len(manifest),
    'preserved_tm_tests': len(preserved),
    'server_databases': {'PostgreSQL': 'Not Verified', 'MySQL': 'Not Verified', 'MariaDB': 'Not Verified'},
}
output = ROOT/'.runtime/test-results/package-verification.json'
output.parent.mkdir(parents=True, exist_ok=True)
output.write_text(json.dumps(report, ensure_ascii=False, indent=2)+'\n', encoding='utf8')
print(json.dumps(report, ensure_ascii=False, indent=2))
