"""Extend the real phase-six all-host Composer fixture with local Ticket Chat source."""
from pathlib import Path
import argparse
import json

ROOT = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('--ticketing', type=Path, default=ROOT.parent / 'laravel-ticket-chat')
args = parser.parse_args()
source = args.ticketing.resolve()
manifest = json.loads((source / 'composer.json').read_text(encoding='utf8'))
assert manifest['name'] == 'karnoweb/laravel-ticket-chat'
base = ROOT / '.runtime/phase6-hosts/all/composer.json'
if not base.is_file():
    raise SystemExit('First prepare the four real optional host fixtures with tools/prepare_phase6_hosts.py.')
data = json.loads(base.read_text(encoding='utf8'))
data['name'] = 'task-manager/ticketing-integration'
data['require'][manifest['name']] = manifest['version']
data['repositories'].insert(0, {'type': 'path', 'url': source.as_posix(), 'options': {'symlink': True}})
target = ROOT / '.runtime/ticketing-host'
target.mkdir(parents=True, exist_ok=True)
(target / 'composer.json').write_text(json.dumps(data, indent=2) + '\n', encoding='utf8')
print('Prepared', target, 'with real source', source)
print('Run composer update --working-dir=.runtime/ticketing-host --no-interaction')
