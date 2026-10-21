"""Prepare isolated, locally locked Composer hosts; never alter sibling packages."""
import json
import pathlib
import shutil

ROOT = pathlib.Path(__file__).resolve().parents[1]
SIBLINGS = ROOT.parent
STAGING = ROOT / '.runtime' / 'phase6-packages'
PROFILES = {
    'task-only': {'karnoweb/task-manager': 'dev-phase-six'},
    'kanban-only': {'karnoweb/laravel-livewire-kanban': '13.3.0'},
    'calendar-only': {'karnoweb/laravel-calendar': '2.0.0'},
    'all': {'karnoweb/task-manager': 'dev-phase-six', 'karnoweb/laravel-livewire-kanban': '13.3.0', 'karnoweb/laravel-calendar': '2.0.0', 'karnoweb/crm': '13.1.0'},
}
repositories = []
seen = set()
aliases = []
for name, directory, version in [('karnoweb/task-manager', ROOT, 'dev-phase-six'), ('karnoweb/laravel-livewire-kanban', SIBLINGS/'kanban-livewire', '13.3.0'), ('karnoweb/laravel-calendar', SIBLINGS/'laravel-calendar', '2.0.0'), ('karnoweb/crm', SIBLINGS/'crm', '13.1.0')]:
    repositories.append({'type': 'path', 'url': directory.as_posix(), 'options': {'symlink': True, 'versions': {name: version}}})
    seen.add(name)
for parent in [ROOT, SIBLINGS/'kanban-livewire', SIBLINGS/'laravel-calendar', SIBLINGS/'crm']:
    packages = json.loads((parent/'vendor/composer/installed.json').read_text(encoding='utf8'))['packages']
    for package in packages:
        name = package['name']
        directory = parent/'vendor'/name
        if name in seen or not (directory/'composer.json').is_file():
            continue
        seen.add(name)
        manifest = json.loads((directory/'composer.json').read_text(encoding='utf8'))
        if manifest['name'] != name:
            target = STAGING/name
            shutil.copytree(directory, target, dirs_exist_ok=True)
            aliases.append({'installed_name': name, 'manifest_name': manifest['name'], 'version': package['version']})
            manifest['name'] = name
            (target/'composer.json').write_text(json.dumps(manifest, indent=2), encoding='utf8')
            directory = target
        repositories.append({'type': 'path', 'url': directory.as_posix(), 'options': {'symlink': True, 'versions': {name: package['version']}}})
repositories.append({'packagist.org': False})
for profile, requirements in PROFILES.items():
    directory = ROOT/'.runtime'/'phase6-hosts'/profile
    directory.mkdir(parents=True, exist_ok=True)
    manifest = {'name': 'task-manager/phase-six-'+profile, 'require': {'php': '^8.3', 'laravel/framework': '13.37.0', **requirements}, 'require-dev': {'orchestra/testbench': '^11.0', 'phpunit/phpunit': '^12.0'}, 'repositories': repositories, 'minimum-stability': 'dev', 'prefer-stable': True, 'config': {'allow-plugins': False}}
    (directory/'composer.json').write_text(json.dumps(manifest, indent=2), encoding='utf8')
(ROOT/'.runtime/phase6-manifest-aliases.json').write_text(json.dumps(aliases, indent=2), encoding='utf8')
print('Prepared four local Composer hosts; run Composer update in each .runtime/phase6-hosts/<profile>.')
