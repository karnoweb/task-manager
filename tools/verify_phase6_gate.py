"""Verify actual phase-six SQLite evidence and retain exact local host locks."""
from pathlib import Path
import copy
import hashlib
import json
import re
import shutil
import subprocess
import xml.etree.ElementTree as ET
import zipfile

ROOT=Path(__file__).resolve().parents[1]
OWNED=[4,*range(81,89),*range(130,133),*range(143,146),209,210,211,213,219,220]
PROFILES=['task-only','kanban-only','calendar-only','all']

def digest(path):return hashlib.sha256(path.read_bytes()).hexdigest()
def junit(path):
    tree=ET.parse(path);suite=tree.getroot().find('testsuite');assert suite is not None,path
    counts={k:int(suite.get(k,'0')) for k in ['tests','assertions','errors','failures','skipped']}
    assert not counts['errors'] and not counts['failures'],(path,counts)
    cases=tree.findall('.//testcase');assert len(cases)==counts['tests'],path
    return tree,cases,counts

tree,cases,counts=junit(ROOT/'docs/phase6-all-sqlite.xml')
groups={n:[] for n in OWNED}
for case in cases:
    match=re.search(r'\\Scenarios\\TM(\d{3})Test$',case.get('class',''))
    if match and int(match[1]) in groups:groups[int(match[1])].append(case)
for n,items in groups.items():
    assert items,f'Missing TM-{n:03}'
    assert all(not any(item.find(tag) is not None for tag in ['error','failure','skipped']) for item in items),n
    assert (ROOT/f'tests/Scenarios/TM{n:03}Test.php').is_file(),n
    assert (ROOT/f'docs/TEST-SCENARIOS/TM-{n:03}.md').is_file(),n
owned=[case for items in groups.values() for case in items]
owned_assertions=sum(int(case.get('assertions','0')) for case in owned)
subset=ET.Element('testsuites');suite=ET.SubElement(subset,'testsuite',{'name':'Phase six owned cases extracted from final regression','tests':str(len(owned)),'assertions':str(owned_assertions),'errors':'0','failures':'0','skipped':'0'})
for case in owned:suite.append(copy.deepcopy(case))
ET.indent(subset);ET.ElementTree(subset).write(ROOT/'docs/phase6-owned-sqlite.xml',encoding='utf-8',xml_declaration=True)
host_e2e=[case for case in cases if case.get('class','').endswith('\\RealHostE2ETest')]
for n in range(1,7):
    selected=[case for case in host_e2e if case.get('name','').startswith(f'test_e2e_{n:02}_')]
    assert selected,f'Missing real host E2E-{n:02}'
    assert all(not any(case.find(tag) is not None for tag in ['error','failure','skipped']) for case in selected),n

lock_directory=ROOT/'docs/phase6-host-locks';lock_directory.mkdir(exist_ok=True)
profiles={}
for profile in PROFILES:
    _,_,result=junit(ROOT/f'docs/phase6-install-{profile}.xml');assert result['tests']>0 and result['skipped']==0
    lock=ROOT/f'.runtime/phase6-hosts/{profile}/composer.lock';assert lock.is_file()
    shutil.copyfile(lock,lock_directory/f'{profile}.lock')
    package_names={p['name'] for section in ['packages','packages-dev'] for p in json.loads(lock.read_text(encoding='utf8')).get(section,[])}
    assert ('karnoweb/task-manager' in package_names)==(profile in ['task-only','all'])
    assert ('karnoweb/laravel-livewire-kanban' in package_names)==(profile in ['kanban-only','all'])
    assert ('karnoweb/laravel-calendar' in package_names)==(profile in ['calendar-only','all'])
    profiles[profile]={**result,'lock_sha256':digest(lock),'lock_file':f'phase6-host-locks/{profile}.lock'}

sources={}
for directory in ['kanban-livewire','laravel-calendar','crm']:
    sibling=ROOT.parent/directory
    sha=subprocess.check_output(['git','-C',str(sibling),'rev-parse','HEAD'],text=True).strip()
    dirty=subprocess.check_output(['git','-C',str(sibling),'diff','--name-only','HEAD'],text=True).strip();assert not dirty,(directory,dirty)
    manifest=json.loads((sibling/'composer.json').read_text(encoding='utf8'))
    tag=subprocess.check_output(['git','-C',str(sibling),'describe','--tags','--always'],text=True).strip()
    sources[directory]={'commit':sha,'source_tag':tag,'declared_version':manifest.get('version'),'composer_json_sha256':digest(sibling/'composer.json'),'tracked_source_changes':False}
archive=ROOT.parent/'kanban-livewire/artifacts/kanban-k1-k5-review.zip'
with zipfile.ZipFile(archive) as z:review_hash=hashlib.sha256(z.read('kanban-livewire/docs/DESIGN-AUDIT.md')).hexdigest()
source_lock={'review_date':'2026-10-21','sources':sources,'host_profiles':profiles,'core_composer_lock_sha256':digest(ROOT/'composer.lock'),'local_manifest_aliases':json.loads((ROOT/'.runtime/phase6-manifest-aliases.json').read_text(encoding='utf8')),'historical_kanban_design_audit_sha256':review_hash,'network_installation':'Local installed path repositories; no clean internet-download claim'}
(ROOT/'docs/PHASE-6-SOURCE-LOCK.json').write_text(json.dumps(source_lock,ensure_ascii=False,indent=2)+'\n',encoding='utf8')

gate={'phase':6,'gate':'PASS_SQLITE_SCOPE','test_scope_authorization':'User requested SQLite only until phase seven and stop/report at every phase boundary.','verified_from':'phase6-all-sqlite.xml','regression':counts,'owned_ids':len(OWNED),'owned_cases':len(owned),'owned_assertions':owned_assertions,'real_host_e2e_ids':6,'real_host_e2e_cases':len(host_e2e),'host_profiles':profiles,'phase_seven_acceptance':False,'database_matrix':'DEFERRED_TO_PHASE_7','physical_concurrency_and_os_crash':'DEFERRED_TO_PHASE_7','external_network_delivery':'NOT_PERFORMED','results':[{'id':f'TM-{n:03}','result':'PASS_SQLITE_SCOPE','cases':len(items)}for n,items in groups.items()]}
(ROOT/'docs/PHASE-6-GATE.json').write_text(json.dumps(gate,ensure_ascii=False,indent=2)+'\n',encoding='utf8')
status_path=ROOT/'docs/ACCEPTANCE-STATUS.md';status=status_path.read_text(encoding='utf8')
status=status.replace('Final installed host integration and cross-module/UI acceptance remain pending.','Phase-six installed optional hosts, actual UI contracts and real-CRM/sample-host functional E2E cases passed SQLite scope. Comprehensive phase-seven acceptance remains pending.')
for n in OWNED:status=re.sub(rf'\| TM-{n:03} \| [^\n]+',f'| TM-{n:03} | PASS (SQLite phase-six real-host scope; comprehensive phase-seven acceptance pending) |',status)
status_path.write_text(status,encoding='utf8')
report=f'''# Phase-six test report

The final run used SQLite only, as requested. It is not phase-seven acceptance or a release authorization.

| Evidence | Observed result |
|---|---|
| Complete final regression | {counts['tests']} tests / {counts['assertions']} assertions / {counts['errors']} errors / {counts['failures']} failures / {counts['skipped']} engine-specific skips |
| Owned phase-six scenarios | {len(OWNED)} IDs / {len(owned)} cases / {owned_assertions} assertions / no failure or skip |
| Functional host E2E | 6 IDs / {len(host_e2e)} cases, including three approval/return outcomes |
| Actual isolated installations | Task-only, Kanban-only, Calendar-only, all; each booted and queried real persisted records |
| UI host integration | Real Livewire board render/action, actual Calendar read dispatcher/merger, native model-trait providers |
| CRM host integration | Actual Deal, Task, RecurringTask and Campaign services and tables; sample host Ticket models/routes |

The owned XML is extracted from the final full JUnit file, not fabricated or based only on file existence. The verifier also requires passing E2E-01..06 bodies, four passing standalone installation results and their real Composer dependency graphs. `PHASE-6-SOURCE-LOCK.json` records exact local baselines and the installed manifest-name reconciliation. Root package requirements retain no mandatory UI or CRM dependency.

Self-review and tests cover actual command argument order; current section permissions; source-specific Calendar provider composition; real ISO date payloads; transactional board retry journals; task-version locking; current-source visibility; due-only/stable occurrence identity; read-only SLA/milestone/recurrence projections; both-side follow-up authorization; real CRM task/series migration; custom type and terminal mapping rejection; atomic import rollback; source history/series fingerprinting; missing-row reconciliation; safe writer cutover and unchanged-data rollback; conservative rejection of rollback after new engine events. Final full regression passed after product changes.

E2E-02 uses 100 real CRM opportunities and asserts exactly 75 failed / 15 continued / 10 already terminal, 12 SLA events, five active delegated contracts and no duplicate events on processing retry. Its local transaction ledger and between-batch resumption do not prove OS process termination. E2E-04 calls the actual same-connection CRM DealService, injects failure after creation, observes rollback and retries once without a duplicate. The sample host is executable test application code; it is not every consumer's deployed Ticket application.

No phase-six PostgreSQL, MariaDB, Oracle MySQL, physical multi-connection race, real outbound network delivery, browser accessibility certification or final load run is claimed. Existing {counts['skipped']} engine-specific cases remain skipped. Original physical crash/separate-database fixtures must be rerun in the comprehensive phase-seven matrix. Cached pages in the external Calendar package must remain disabled unless the host wires permission-aware invalidation.

Historical visual findings in the supplied Kanban design audit are recorded as upstream/host acceptance context, not silently certified as fixed here. The actual legacy-writer guard covers model-event service writes; arbitrary privileged SQL/bulk writers must be retired or restricted by the host. Simple rollback is intentionally limited to unchanged data; post-cutover changes require a separately reviewed host reverse migration.

See `PHASE-6-IMPLEMENTATION-FA.md`, `PHASE-6-USAGE-FA.md`, `PHASE-6-MIGRATION-FA.md`, `PHASE-6-DECISIONS.md` and `PHASE-6-NEXT-GATE.md`. The agent stops before phase seven.
'''
(ROOT/'docs/PHASE-6-TEST-REPORT.md').write_text(report,encoding='utf8')
print(json.dumps({'gate':gate['gate'],'regression':counts,'owned_ids':len(OWNED),'owned_cases':len(owned),'owned_assertions':owned_assertions,'host_e2e_cases':len(host_e2e),'profiles':PROFILES}))
