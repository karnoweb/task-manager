"""Generate independent phase-six scenario bodies and Persian scenario descriptions."""
from pathlib import Path
import re

ROOT=Path(__file__).resolve().parents[1]
SOURCES={
4:('UiIntegrationTest','test_due_only_calendar_and_board_share_one_task_truth'),
81:('PhaseSixInstallationTest','test_kanban_only_host_runs_with_task_manager_absent'),
82:('PhaseSixInstallationTest','test_calendar_only_host_runs_with_task_manager_absent'),
83:('PhaseSixInstallationTest','test_task_only_host_runs_todo_project_facade_and_reporting_without_ui'),
84:('UiIntegrationTest','test_board_transition_retry_conflict_and_revocation_use_real_domain_services'),
85:('HostUiCompositionTest','test_real_calendar_ui_iso_payload_preserves_the_requested_instant'),
86:('UiIntegrationTest','test_due_only_calendar_and_board_share_one_task_truth'),
87:('PhaseSixInstallationTest','test_orders_work_in_both_independent_ui_hosts_and_the_combined_host'),
88:('CrmCutoverTest','test_followup_task_cannot_mutate_real_crm_deal_status'),
130:('HostUiCompositionTest','test_native_crm_trait_board_calls_crm_service_without_creating_engine_state'),
131:('CrmImportSafetyTest','test_scoped_followup_association_rechecks_both_sides_and_never_leaks_private_crm_content'),
132:('UiIntegrationTest','test_due_only_calendar_and_board_share_one_task_truth'),
143:('CrmCutoverTest','test_real_legacy_task_shadow_import_reconciliation_cutover_and_rollback'),
144:('CrmCutoverTest','test_real_legacy_task_shadow_import_reconciliation_cutover_and_rollback'),
145:('CrmCutoverTest','test_real_legacy_task_shadow_import_reconciliation_cutover_and_rollback'),
209:('UiProcessSafetyTest','test_mixed_task_and_real_opportunity_board_keeps_sources_and_current_permissions_separate'),
210:('UiProcessSafetyTest','test_calendar_sla_marker_and_virtual_recurrence_details_have_stable_read_only_identity'),
211:('UiProcessSafetyTest','test_contract_stage_drag_calls_the_trusted_handoff_handler_once_without_direct_status_write'),
213:('CrmCutoverTest','test_real_deal_pipeline_history_alias_import_and_writer_switch'),
219:('CrmCutoverTest','test_real_deal_pipeline_history_alias_import_and_writer_switch'),
220:('CrmCutoverTest','test_real_legacy_task_shadow_import_reconciliation_cutover_and_rollback'),
}

def generate():
    catalog=(ROOT/'dev/TASK-MANAGER-ENTERPRISE-TEST-SCENARIOS-FA.md').read_text(encoding='utf8')
    for number,(filename,method) in SOURCES.items():
        source=(ROOT/'tests'/f'{filename}.php').read_text(encoding='utf8')
        match=re.search(r'^    public function '+method+r'\([^\n]*',source,re.M)
        if not match:raise RuntimeError(method)
        rest=source[match.start():]
        end=re.search(r'^    public function |^}',rest[1:],re.M)
        body=rest[:end.start()+1] if end else rest
        body=body.rstrip().replace(method,'test_scenario',1)
        body=body.replace('dirname(__DIR__)','dirname(__DIR__,2)')
        imports='\n'.join(re.findall(r'^use [^\n]+;',source,re.M))
        test=f'<?php\n\nnamespace Karnoweb\\TaskManager\\Tests\\Scenarios;\n\nuse Karnoweb\\TaskManager\\Tests\\PhaseSixTestCase;\n{imports}\n\nfinal class TM{number:03d}Test extends PhaseSixTestCase\n{{\n{body}\n}}\n'
        (ROOT/'tests/Scenarios'/f'TM{number:03d}Test.php').write_text(test,encoding='utf8')
        line=re.search(r'^\| TM-'+f'{number:03d}'+r' \| ([^\n]+)',catalog,re.M).group(1).rstrip(' |')
        description=f'# TM-{number:03d} — فاز ۶\n\n{line}\n\nآزمون مستقل: `tests/Scenarios/TM{number:03d}Test.php`. بدنهٔ اجرایی مستقل است و آزمون دیگری را صدا نمی‌زند.\n\nمیزبان با نسخهٔ محلی قفل‌شدهٔ بسته‌های واقعی راه‌اندازی می‌شود؛ داده‌ها در SQLite (پایگاه‌دادهٔ سبک محلی) ذخیره می‌شوند. مجوزها، نتیجهٔ دامنه و نبود ثبت تکراری در گام‌های مرتبط بررسی می‌شوند.\n\nمدرک نهایی از اجرای واقعی در `phase6-all-sqlite.xml` استخراج می‌شود. رقابت چنداتصالی، شبکهٔ واقعی و پذیرش انتشار به فاز ۷ تعلق دارند.\n'
        (ROOT/'docs/TEST-SCENARIOS'/f'TM-{number:03d}.md').write_text(description,encoding='utf8')
    print(f'Generated {len(SOURCES)} independent scenarios.')

if __name__=='__main__':generate()
