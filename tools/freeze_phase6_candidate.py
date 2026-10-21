"""Freeze a committed phase-six candidate using canonical Git blob hashes."""
from pathlib import Path
import hashlib
import json
import subprocess
import xml.etree.ElementTree as ET

ROOT=Path(__file__).resolve().parents[1]
def git(*args):return subprocess.check_output(['git','-C',str(ROOT),*args])
candidate=git('rev-parse','HEAD').decode().strip()
paths=git('ls-tree','-r','--name-only',candidate).decode().splitlines()
def is_code(path):return path in ['composer.json','composer.lock','phpunit.xml'] or path.startswith(('src/','config/','database/','tests/','tools/'))
code_paths=[p for p in paths if is_code(p)]
assert not git('diff',candidate,'--name-only','--','src','config','database','tests','tools','composer.json','composer.lock','phpunit.xml').strip(),'Code differs from candidate'
untracked=git('ls-files','--others','--exclude-standard').decode().splitlines()
assert not any(is_code(p) for p in untracked),'Untracked code is outside candidate'
manifest={p:hashlib.sha256(git('show',candidate+':'+p)).hexdigest() for p in code_paths}
root=ET.parse(ROOT/'docs/phase6-all-sqlite.xml').getroot().find('testsuite')
assert root is not None and root.get('errors')=='0' and root.get('failures')=='0'
gate=json.loads((ROOT/'docs/PHASE-6-GATE.json').read_text(encoding='utf8'));assert gate['gate']=='PASS_SQLITE_SCOPE'
freeze={'phase':6,'candidate_commit':candidate,'candidate_tree':git('rev-parse',candidate+'^{tree}').decode().strip(),'freeze_date':'2026-10-21','hash_definition':'SHA-256 of canonical committed Git blob bytes; not checkout newline variants','code_file_count':len(manifest),'code_files':manifest,'final_junit_sha256':hashlib.sha256(git('show',candidate+':docs/phase6-all-sqlite.xml')).hexdigest(),'source_lock_sha256':hashlib.sha256(git('show',candidate+':docs/PHASE-6-SOURCE-LOCK.json')).hexdigest(),'gate':'PASS_SQLITE_SCOPE','regression':gate['regression'],'phase_seven_started':False,'next_action':'Stop and report. Start phase seven only when the user instructs it.','metadata_commit_policy':'A following documentation-only commit may add this freeze manifest; product/test/tool code must remain identical to candidate.'}
(ROOT/'docs/PHASE-6-RC-FREEZE.json').write_text(json.dumps(freeze,ensure_ascii=False,indent=2)+'\n',encoding='utf8')
print(json.dumps({'candidate_commit':candidate,'code_files':len(manifest),'gate':freeze['gate'],'regression':freeze['regression']}))
