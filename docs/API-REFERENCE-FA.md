# فهرست خدمات و قراردادهای عمومی

Facade (نمای سادهٔ دسترسی) با نام `Karnoweb\TaskManager\Facades\TaskManager` همان مدیر تزریق‌پذیر `Karnoweb\TaskManager\TaskManager` را برمی‌گرداند. همهٔ خدمات دامنه زمینهٔ صریح، مجوز فعلی، شناسهٔ عملیات و در تغییر رکورد، نسخهٔ مورد انتظار دارند. قراردادها با نوشتن مستقیم روی جدول جایگزین نمی‌شوند.

## نقطه‌های ورود نوع‌دار

جدول از نمایهٔ سورس واقعی مدیر در پایان فاز ۶ تهیه شده است. نوع بازگشتی، نام کلاس خدمت است؛ امضا و توضیح هر فرمان در همان فایل منبع و راهنمای مالک آن فاز قرار دارد.

| نقطهٔ ورود | نوع بازگشتی |
|---|---|
| `__call()` | `mixed` |
| `approvals()` | `Karnoweb\TaskManager\Approval\ApprovalService` |
| `assignments()` | `Karnoweb\TaskManager\Responsibility\AssignmentService` |
| `attachments()` | `Karnoweb\TaskManager\Collaboration\AttachmentService` |
| `automation()` | `Karnoweb\TaskManager\Integration\AutomationService` |
| `bulk()` | `Karnoweb\TaskManager\Planning\BulkService` |
| `cases()` | `CaseService` |
| `checklists()` | `Karnoweb\TaskManager\Collaboration\ChecklistService` |
| `collaboration()` | `Karnoweb\TaskManager\Collaboration\CollaborationService` |
| `dates()` | `Karnoweb\TaskManager\Scheduling\DateService` |
| `dependencies()` | `Karnoweb\TaskManager\TaskGraph\DependencyService` |
| `enterpriseReports()` | `Karnoweb\TaskManager\Enterprise\ReportService` |
| `events()` | `Karnoweb\TaskManager\Integration\EventPump` |
| `exports()` | `Karnoweb\TaskManager\Enterprise\ExportService` |
| `fields()` | `Karnoweb\TaskManager\Enterprise\CustomFieldService` |
| `groups()` | `Karnoweb\TaskManager\Group\GroupService` |
| `handoffs()` | `Karnoweb\TaskManager\Handoff\HandoffService` |
| `hierarchy()` | `Karnoweb\TaskManager\TaskGraph\HierarchyService` |
| `instances()` | `InstanceService` |
| `integrations()` | `Karnoweb\TaskManager\UI\IntegrationFactory` |
| `markers()` | `Karnoweb\TaskManager\Planning\MarkerService` |
| `milestones()` | `Karnoweb\TaskManager\Group\MilestoneService` |
| `pipelines()` | `PipelineService` |
| `planning()` | `Karnoweb\TaskManager\TaskGraph\TaskPlanningService` |
| `positions()` | `Karnoweb\TaskManager\Planning\PositionService` |
| `presets()` | `Karnoweb\TaskManager\Enterprise\PresetService` |
| `projects()` | `ProjectService` |
| `queries()` | `Karnoweb\TaskManager\Enterprise\QueryService` |
| `recurrences()` | `Karnoweb\TaskManager\Scheduling\RecurrenceService` |
| `reminders()` | `Karnoweb\TaskManager\Scheduling\ReminderService` |
| `reports()` | `Karnoweb\TaskManager\Planning\ReportingService` |
| `routes()` | `Karnoweb\TaskManager\Handoff\LocalRouteService` |
| `sagas()` | `Karnoweb\TaskManager\Handoff\ExternalSagaService` |
| `sla()` | `Karnoweb\TaskManager\SLA\SlaService` |
| `sprints()` | `Karnoweb\TaskManager\Planning\SprintService` |
| `stagePolicies()` | `Karnoweb\TaskManager\SLA\StagePolicyService` |
| `tasks()` | `TaskService` |
| `templates()` | `Karnoweb\TaskManager\Planning\TemplateService` |
| `time()` | `Karnoweb\TaskManager\Planning\TimeTrackingService` |
| `webhooks()` | `Karnoweb\TaskManager\Integration\WebhookService` |
| `workflows()` | `WorkflowService` |

## زمینه، فرمان و نتیجه

`ExecutionContext(scope, actor, correlationId, serviceActor, capabilities, originEventId, automationDepth)` حامل هویت معتبر است. فهرست قابلیت پیش‌فرض فقط برای کار شخصی است؛ خدمات پیشرفته قابلیت‌های صریح خود را می‌خواهند. `AuthorizationProvider` هم مجوز فرمان و هم محدودیت پرس‌وجو را اعمال می‌کند. `AuthorizationRevision` برای ابطال صفحه‌ها و گزارش‌های وابسته به مجوز توسط میزبان توسعه‌پذیر است.

DTO (شیء انتقال داده)های `CreateTodo`، `MutateTask` و `TaskQuery` مسیر نوع‌دار وظیفه را می‌دهند؛ `TaskResult` نتیجهٔ همان خدمت است. شمارهٔ داخلی پایگاه‌داده جایگزین کلید عمومی یا شناسهٔ مرکب رخداد نمی‌شود. خطای `not_found` نبود رکورد را از نبود مجوز متمایز نمی‌کند؛ متن خصوصی نگهبان میزبان نباید به کاربر ارسال شود.

## اتصال‌های فاز ۶

`integrations()->board(Surface, ContextBridge, BoundCursor, ?Closure)` شیء قراردادهای واقعی کانبان را می‌سازد. `integrations()->calendar(Surface, ContextBridge, BoundCursor)` تأمین‌کننده و مجری فرمان تقویم را می‌دهد. `integrations()->followups()` خدمت پیوند مستقل وظیفه و موضوع است. `integrations()->crm()` با اتصال صریح `HostAccess` خدمت انتقال داده را می‌سازد. `integrations()->guardLegacyCrm()` نگهبان مسیرهای قدیمی CRM را ثبت می‌کند؛ میزبان باید آن را در تمام پردازش‌های نوشتن و کارهای زمان‌بندی‌شده فعال کند.

`Surface(key, sources, pipelines, handoffRoutes)` تعریف معتبر سرور است. `ContextBridge` خروجی حل‌کنندهٔ جاری میزبان را با هویت رابط تطبیق می‌دهد. `BoundCursor` امضای صفحه را با کلید خصوصی میزبان تولید می‌کند. این اشیا اطلاعات کاربر یا رکورد را در تک‌نمونهٔ مشترک ذخیره نمی‌کنند.

خدمت انتقال شامل `configure`، `get`، `inventory`، `importBatch`، `shadow`، `reconcile` و `switch` است. وضعیت‌های آن `shadow`، `active` و `rolled_back` هستند. خواندن و واردکردن و تغییر نویسنده هرگز از راه نصب خودکار اجرا نمی‌شوند.

## راهنماهای مالک قابلیت

- فازهای ۱ و ۲: [قرارداد پایه](PHASE-1-API.md)، [نقشهٔ اتصال](INTEGRATION-CONTRACT-MAP.md).
- فاز ۳: راهنماها و تصمیم‌های SLA (مهلت مرحله)، مسئولیت، تأیید، ارجاع، مسیریابی و گروه در اسناد `PHASE-3-*`.
- فاز ۴: [کار با پروژه و خدمات](PHASE-4-USAGE-FA.md).
- فاز ۵: [زمان‌بندی، یکپارچگی و گزارش](PHASE-5-USAGE-FA.md).
- فاز ۶: [مصرف اتصال‌ها](PHASE-6-USAGE-FA.md)، [انتقال و ارتقا](PHASE-6-MIGRATION-FA.md)، [مرزهای سازگاری](PHASE-6-DECISIONS.md).

API (رابط برنامه‌نویسی) در پایان فاز ۶ برای آزمون فاز ۷ تثبیت می‌شود. عیب پیدا‌شده در فاز ۷ باید به فاز مالک برگردد؛ اصلاح محصول در خود فاز ۷ مجاز نیست.
