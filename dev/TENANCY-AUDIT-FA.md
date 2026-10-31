# ممیزی قلمرو داده و پیشوند جداول

تاریخ: ۲۰۲۶-۱۰-۲۴. منبع این فهرست Schema (ساختار واقعی) نصب SQLite و آزمون `test_every_package_table_has_a_scope_or_a_foreign_key_path_to_one` در [سناریوهای شعبه](../tests/MultiTenancyScenarioTest.php) است. نام‌ها با پیشوند پیش‌فرض نمایش داده شده‌اند؛ اجرای همان آزمون با custom_ نیز انجام می‌شود. دسترسی مستقیم میزبان به Connection (اتصال پایگاه داده) یک مرز اعتماد میزبان است؛ برای عملیات محصول از سرویس‌ها استفاده شود.

## جدول‌های دارای قلمرو مستقیم

| جدول | مرز |
|---|---|
| tm_active_process_slots | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_active_timers | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_actor_time_locks | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_approval_policies | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_approval_steps | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_assignment_policies | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_assignment_queues | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_attachment_tickets | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_attachments | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_audit_events | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_automation_budgets | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_automation_rules | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_checklists | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_command_journal | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_comments | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_custom_fields | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_entry_routes | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_event_offsets | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_event_subscriptions | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_exports | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_external_sagas | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_group_operations | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_handoff_groups | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_handoffs | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_host_cutovers | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_host_task_links | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_instance_assignments | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_instance_transitions | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_lists | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_milestone_intents | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_outbox | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_pipelines | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_process_cases | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_process_groups | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_process_milestones | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_project_markers | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_project_templates | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_projects | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_query_cursors | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_recurrences | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_reminders | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_report_projections | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_security_decisions | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_sequences | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_sla_events | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_sprints | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_stage_policies | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_stage_visits | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_subject_conversions | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_task_closure | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_task_dependencies | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_task_graph_locks | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_task_key_aliases | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_task_position_contexts | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_task_presets | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_task_time_entries | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_task_timers | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_tasks | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_visibility_epochs | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_webhook_endpoints | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_workflow_definitions | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_workflow_heads | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |
| tm_workflow_instances | scope_key؛ کنترل مجوز و مالکیت براساس نوع سرویس |

## جدول‌های وابسته به رابطه

| جدول | کلید خارجی به والد |
|---|---|
| tm_active_approval_steps | tm_approval_steps, tm_workflow_instances |
| tm_active_dependencies | tm_task_dependencies, tm_tasks, tm_tasks |
| tm_active_external_sagas | tm_external_sagas, tm_workflow_instances |
| tm_active_group_members | tm_workflow_instances, tm_process_groups, tm_process_group_members |
| tm_active_handoff_origins | tm_handoffs, tm_workflow_instances |
| tm_active_sprint_tasks | tm_sprint_tasks, tm_sprints, tm_tasks |
| tm_approval_votes | tm_approval_steps |
| tm_assignment_slots | tm_instance_assignments, tm_workflow_instances |
| tm_automation_rule_revisions | tm_automation_rules |
| tm_automation_runs | tm_outbox, tm_automation_rules |
| tm_automation_throttles | tm_automation_rules |
| tm_case_subjects | tm_process_cases |
| tm_checklist_items | tm_tasks, tm_checklists |
| tm_comment_mentions | tm_comments |
| tm_comment_revisions | tm_comments |
| tm_custom_selections | tm_tasks, tm_custom_fields |
| tm_custom_values | tm_tasks, tm_custom_fields |
| tm_event_deliveries | tm_outbox, tm_event_subscriptions |
| tm_event_lineage | tm_outbox, tm_outbox |
| tm_export_items | tm_workflow_instances, tm_tasks, tm_exports |
| tm_external_case_subjects | tm_external_sagas, tm_process_cases |
| tm_group_lifecycle_policies | tm_process_groups |
| tm_group_operation_items | tm_workflow_instances, tm_process_group_members, tm_group_operations |
| tm_handoff_outcomes | tm_handoffs |
| tm_handoff_repairs | tm_handoffs |
| tm_host_imports | tm_workflow_instances, tm_tasks, tm_host_cutovers |
| tm_host_series | tm_recurrences, tm_host_cutovers |
| tm_marker_tasks | tm_tasks, tm_project_markers |
| tm_pipeline_stages | tm_approval_policies, tm_stage_policies, tm_pipelines, tm_assignment_policies |
| tm_process_group_members | tm_workflow_instances, tm_process_groups |
| tm_project_members | tm_projects |
| tm_project_template_instances | tm_project_template_revisions, tm_projects |
| tm_project_template_revisions | tm_project_templates |
| tm_project_type_workflows | tm_projects, tm_workflow_definitions |
| tm_recurrence_exceptions | tm_recurrences |
| tm_recurrence_occurrences | tm_tasks, tm_recurrences |
| tm_reminder_deliveries | tm_outbox, tm_reminders |
| tm_report_projection_items | tm_workflow_instances, tm_report_projections |
| tm_sla_pauses | tm_stage_visits |
| tm_sprint_daily_snapshots | tm_sprints |
| tm_sprint_memberships | tm_sprint_tasks, tm_tasks, tm_sprints |
| tm_sprint_snapshot_items | tm_tasks, tm_sprint_tasks, tm_sprint_daily_snapshots |
| tm_sprint_tasks | tm_tasks, tm_sprints |
| tm_task_grants | tm_tasks |
| tm_task_label_keys | tm_tasks |
| tm_task_positions | tm_tasks, tm_task_position_contexts |
| tm_task_stats | tm_tasks |
| tm_task_watchers | tm_tasks |
| tm_webhook_deliveries | tm_outbox, tm_webhook_endpoints |
| tm_webhook_receipts | tm_webhook_endpoints |
| tm_workflow_states | tm_workflow_definitions |

هیچ جدول پکیج بدون scope_key و بدون مسیر کلید خارجی به جدول دارای قلمرو در این نصب یافت نشد. این آزمون امکان اثبات تعلق رابطه‌ای را بررسی می‌کند؛ جای بررسی مجوز هر پرس‌وجو نیست.

## مسیرهای بررسی‌شده

| حوزه | کنترل |
|---|---|
| هویت | ContextFactory فقط UserResolver میزبان را می‌خواند؛ بدون کاربر رد می‌شود. هیچ Auth، Request یا مدل ثابت میزبان در هسته وجود ندارد. |
| زمینه | TenantBoundary در ورودی سرویس‌های عمومی و ExecutionContext::require شعبه را بررسی می‌کند؛ زمینه صریح قدیمی با شعبه فعال رد می‌شود. |
| قلمرو | شعبه فعال فقط branch:<شناسه> را می‌پذیرد؛ ScopeResolver متناقض رد می‌شود و شعبه غایب جایگزین عمومی ندارد. |
| Task و Project | پرس‌وجوی مجاز به scope_key و مالک/عضویت محدود است. جابه‌جایی، والد و وابستگی هر دو طرف را از پرس‌وجوی مجاز می‌گیرند. |
| گردش‌کار و مسیر | تعریف، نسخه، نمونه، مسیر و پرونده پیش از اتصال از همان زمینه خوانده می‌شوند؛ فرزندان با شناسه والد اعتبارسنجی‌شده خوانده می‌شوند. |
| اشتراک و مسئولیت | دریافت‌کننده اشتراک، عضو پروژه، مسئول، تأییدکننده و دریافت‌کننده یادآور باید عضو شعبه باشند. حذف مجوز قبلی برای کاربر خارج‌شده مجاز می‌ماند. |
| گزارش و خروجی | QueryService، ReportService و ExportService قلمرو و مجوز را در منابع و تلاش مجدد نگه می‌دارند؛ کش مجوز و موقعیت پرس‌وجو به actor و scope متصل‌اند. |
| صف و تلاش مجدد | دفتر فرمان، رویداد، صف و اشتراک به قلمرو متصل‌اند؛ زمینه خدمت و مجوزهای صریح لازم‌اند؛ عضویت در ورود بعدی دوباره بررسی می‌شود. |
| پیشوند | Migration، SQL خام، ستون‌های واجد نام جدول و نام قیدهای صریح از Tables استفاده می‌کنند؛ نام و ترتیب پیش‌فرض حفظ شده است. |

## داده قبلی و محدودیت

انتقال داده قبلی و تغییر پیشوند نصب موجود ابزار خودکار ندارند. نگاشت و بازگشت باید طبق [راهنمای پایگاه داده](../docs/advanced/database.md) در میزبان طراحی و بازبینی شوند. تشخیص تغییر از پیشوند tm_ موجود به پیشوند نصب‌نشده خطای روشن دارد؛ شناسایی همه پیشوندهای سفارشی تاریخی پشتیبانی نمی‌شود. قیدهای خودکار Schema Builder و رفتار رقابت روی PostgreSQL، MySQL و MariaDB در این مأموریت Not Verified (تأییدنشده) هستند. حل‌کننده میزبان مرجع صحت عضویت است؛ نمونه سراسری آن نباید عضویت یا هویت قدیمی را نگه دارد.

نتایج واقعی آزمون‌ها در [گزارش آزمون](../docs/testing.md) ثبت می‌شود؛ تست‌های قبلی حفظ شده‌اند.
