# گزارش مرحله دوم: ساده‌سازی تجربه توسعه‌دهنده Task Manager

تاریخ: ۲۰۲۶-۱۰-۲۲ — مبنا: سورس فعلی پوشه کاری، شامل تغییرات ثبت‌نشده موجود؛ نه صرفاً ZIP یا نسخه ثبت‌شده Git.

## ۱. نتیجه و حدود بررسی

**پیشنهاد اصلی: رابط عمومی موجود حفظ شود و مسیر پیشنهادی استفاده حول `TaskManager::tasks()->inContext()` برای Todo، `projects()` برای پروژه و `instances()` برای اجرای گردش‌کار مستند شود.** ساخت Facade (درگاه ایستای Laravel) تازه یا حذف گروه‌های سرویس، برای رسیدن به شروع ساده لازم نیست.

دو فایل `TaskManager-Refactor-Roadmap-FA.md` و `TaskManager-Current-API-Map-FA.md` مطالعه شدند. در نقشه راه، «گام ۲» عنوان تنظیمات و قراردادهای میزبان دارد؛ درخواست حاضر علاوه بر آن، ممیزی و طراحی رابط ساده را می‌خواهد. این گزارش هر شش بند درخواست را پوشش می‌دهد؛ دستورهای مراحل بعد در اسناد، مجوز تغییر کد یا پاک‌سازی تلقی نشده‌اند.

منابع اصلی: تمام اعلان‌های عمومی `src/`، پیاده‌سازی سرویس‌ها و اتصال‌های `TaskManagerServiceProvider`، فایل تنظیمات، نمونه‌های `examples/`، آزمون‌های مرتبط و سورس محلی `../laravel-inventory`. برای کشف کد ابتدا گراف پروژه استفاده و پس از مشاهده اختلاف با پوشه کاری، نمایه فعلی تازه‌سازی شد. امضاهای نهایی با متن سورس تطبیق داده شدند؛ خروجی گراف به‌تنهایی قرارداد عمومی محسوب نشد.

**پوشش سطح عمومی:** یک Facade، تعداد ۴۱ دسترسی گروهی در مدیر اصلی، و ۵۹۲ اعلان صریح متد عمومی در ۱۴۸ فایل PHP زیر `src/`، با احتساب سازنده‌ها و قراردادها. متدهای ارث‌رسیده Laravel در این عدد نیستند. همه این ۵۹۲ اعلان، رابط توصیه‌شده مصرف‌کننده نیستند.

- [پیوست کامل امضاها و مسیر سورس](TaskManager-Public-Surface-Audit-FA.md)
- [فهرست ماشینی همراه اثر انگشت محتوای سورس](TaskManager-Public-Surface-Audit-FA.json)

هیچ رابط عمومی، Migration (تغییر ساختار پایگاه داده)، آزمون یا سند قبلی در این کار تغییر یا حذف نشده است. تغییرات قبلی پوشه کاری نیز حفظ شده‌اند.

## ۲. چه چیزی واقعاً از Inventory قابل اقتباس است؟

در `../laravel-inventory/src/InventoryManager.php` دوازده گروه سرویس وجود دارد. `src/Facades/Inventory.php` با `@method` و `@mixin` قابلیت کشف آن‌ها را در ویرایشگر فراهم می‌کند. شروع README با عملیات واقعی `warehouses()->create`، `stock()->receive` و `stock()->issue` پیش می‌رود و قابلیت‌های تکمیلی جدا آموزش داده می‌شوند.

Task Manager همین ساختار گروهی را دارد؛ مشکل اصلی، مسیر ورود آموزشی، تفاوت خروجی‌ها و آشکاربودن جزئیات عملیات پیچیده است. بنابراین الگوی قابل انتقال عبارت است از «شروع کوتاه + گروه‌بندی براساس کار مصرف‌کننده + نمایش قابلیت اختیاری هنگام نیاز + تکمیل خودکار ویرایشگر».

نباید مدل امنیت Inventory عیناً کپی شود. در Task Manager، `ExecutionContext` (زمینه اجرا شامل قلمرو داده و هویت انجام‌دهنده) بخشی از قرارداد امنیت و ثبت رویداد است. وجود `BranchContext` (زمینه شعبه) در Inventory دلیلی برای تبدیل زمینه اجرای Task Manager به وضعیت ضمنی یا سراسری نیست.

## ۳. نقشه کامل درگاه‌ها و سرویس‌ها

درگاه اصلی `Karnoweb\TaskManager\Facades\TaskManager` به `Karnoweb\TaskManager\TaskManager` متصل است. در حال حاضر توضیحات `@method` مشابه Inventory ندارد.

مدیر اصلی، افزون بر ۴۱ گروه زیر، `__call(string $method, array $arguments): mixed` دارد؛ متد قابل فراخوانی `TaskService` را عبور می‌دهد و برای نام ناشناخته `BadMethodCallException` می‌دهد. پس فراخوان مستقیم مانند `TaskManager::create(...)` نیز مسیر موجود است؛ برای سازگاری باید باقی بماند، ولی مسیر گروهی برای آموزش روشن‌تر است. متد صریح مدیر بر عبور پویا اولویت دارد؛ مثلاً `tasks()` دسترسی سرویس است و جایگزین `TaskService::tasks` فرضی نیست.

در جدول، خروجی غالب ذکر شده است؛ امضا و خروجی دقیق تک‌تک متدها در پیوست آمده است. همه عملیات دامنه این سرویس‌ها زمینه اجرا می‌گیرند، مستقیم یا از شیء ورودی؛ استثناهای محاسباتی و کارخانه اتصال در بخش ۶ آمده‌اند.

| دسترسی واقعی | سرویس و عملیات عمومی موجود، بدون سازنده | سطح پیشنهادی آموزش / خروجی |
|---|---|---|
| `tasks()` | `TaskService`: createTodo، inContext، change، findTasks، authorizedQuery، get، resolveKey، query، count، facets، lists، createList، share، changeList، cloneTask، activity، create، mutate | شروع / TaskResult، TaskSession، آرایه، عدد، Builder |
| `projects()` | `ProjectService`: authorizedQuery، get، create، change، previewTaskMove، moveTask، previewSubtreeMove، moveSubtree، previewWorkflowMigration، migrateWorkflow | پروژه / آرایه و Builder |
| `workflows()` | `WorkflowService`: publish، revision | تنظیم گردش‌کار / آرایه |
| `pipelines()` | `PipelineService`: create، get | چیدمان مراحل اختیاری / آرایه |
| `instances()` | `InstanceService`: get، authorizedQuery، query، count، facets، visits، start، transition، executeBusinessCommand، availableTransitions، previewMove، moveToPipeline، changeBoardVisibility، leavePipeline، reconcile، lockForCommand | اجرای گردش‌کار؛ متد آخر تخصصی / آرایه، عدد و Builder |
| `entryRouting()` | `EntryRoutingService`: getDefault، setDefault، resolve، enter، retirePipeline، detachRetired | اتصال میزبان / آرایه |
| `cases()` | `CaseService`: create، timeline | پرونده فرآیندی / آرایه |
| `sla()` | `SlaService`: get، pause، resume، reset، markDeadCandidate، run | زمان تعهد خدمت / آرایه |
| `stagePolicies()` | `StagePolicyService`: publishSla | سیاست مرحله / آرایه |
| `assignments()` | `AssignmentService`: get، assign، configureInstance، configureStage، depart | مسئولیت / آرایه |
| `approvals()` | `ApprovalService`: get، active، begin، configureStage، vote، execute، cancel | تأیید / آرایه یا null |
| `handoffs()` | `HandoffService`: get، delegate، parallel، settle، recall، getGroup، manualJoin، retryReturn، closeConflict، run | واگذاری / آرایه |
| `routes()` | `LocalRouteService`: move، spawn | اتصال همان پایگاه داده / آرایه |
| `sagas()` | `ExternalSagaService`: get، begin، execute، repair، run | هماهنگی با سامانه بیرونی / آرایه |
| `groups()` | `GroupService`: get، register، publishPolicy، enroll، previewClosure، previewDelta، executeClosure، runInfo، process، report | عملیات گروهی / آرایه |
| `milestones()` | `MilestoneService`: get، verify، run | اثبات نقاط عطف / آرایه |
| `hierarchy()` | `HierarchyService`: reparent، configureDepth، tree، progress، previewDelete، executeDelete | زیرکار / آرایه |
| `dependencies()` | `DependencyService`: add، addBatch، get، links، remove | وابستگی کار / آرایه |
| `planning()` | `TaskPlanningService`: estimate، previewSchedule، executeSchedule، criticalPath | برنامه‌ریزی / آرایه |
| `collaboration()` | `CollaborationService`: comment، editComment، history، watch، notificationRecipients، restore | همکاری / آرایه |
| `checklists()` | `ChecklistService`: create، get، change، addItem، item، changeItem، convert، items، progress | چک‌لیست / آرایه |
| `attachments()` | `AttachmentService`: attach، issueTicket، download | فایل میزبان / آرایه یا mixed |
| `time()` | `TimeTrackingService`: start، timer، cancelOwn، stop، log، amend، entry، stats، rebuild | ثبت زمان / آرایه |
| `sprints()` | `SprintService`: get، create، enroll، remove، start، snapshot، close، report، backlog | دوره کاری / آرایه |
| `positions()` | `PositionService`: create، get، place، listing | ترتیب نمایش / آرایه |
| `markers()` | `MarkerService`: create، get، link، progress، change | نشانه پروژه / آرایه |
| `reports()` | `Planning\ReportingService`: totals، subtreeTime، flow | گزارش روزمره / آرایه |
| `templates()` | `TemplateService`: get، publish، instantiate | الگوی پروژه / آرایه |
| `bulk()` | `BulkService`: execute | تغییر دسته‌ای / آرایه |
| `dates()` | `DateService`: change، calendar | تاریخ کار / آرایه |
| `recurrences()` | `RecurrenceService`: get، create، preview، generate، changeRule، exception | تکرار / آرایه |
| `reminders()` | `ReminderService`: parseTime، suggestedRecipient، createGeneral، updateGeneral، changeState، query، count، activity، get، create، snooze، dispatch | یادآور / زمان، آرایه و عدد |
| `fields()` | `CustomFieldService`: create، get، archive، forms، set، value، filter، sortQuery | فیلد سفارشی / آرایه و Builder |
| `queries()` | `QueryService`: base، tasks، reindex | جست‌وجوی پیشرفته / آرایه و Builder |
| `presets()` | `PresetService`: create، get، instantiate | الگوی کار / آرایه |
| `automation()` | `AutomationService`: get، publish، toggle، run | خودکارسازی / آرایه |
| `events()` | `EventPump`: subscribe، toggle، enqueue، deliveries، run | مصرف رویداد / آرایه |
| `webhooks()` | `WebhookService`: get، create، change، queue، deliveries، run، retry، verifyIncoming | ارسال رویداد بیرونی / آرایه، عدد و bool |
| `exports()` | `ExportService`: enqueue، get، stream، build، download | خروجی داده / آرایه، Generator و mixed |
| `enterpriseReports()` | `Enterprise\ReportService`: read، rebuild | گزارش پیشرفته / آرایه |
| `integrations()` | `IntegrationFactory`: board، calendar، crm، guardLegacyCrm، followups | اتصال اختیاری / رابط اتصال یا void |

`Builder` یعنی سازنده پرس‌وجوی پایگاه داده؛ `Generator` خروجی تدریجی قابل پیمایش؛ `mixed` نوع خروجی وابسته به پیاده‌سازی است. `TaskResult` داده خروجی نوع‌دار و `TaskSession` شیء استفاده با زمینه ثابت همان درخواست است.

### سطح عمومی بیرون از این ۴۱ گروه

- `TaskSession` فقط `create(title, operation)`، `get(id)` و `complete(id, version, operation)` دارد؛ متدهایی مانند reopen یا query روی آن موجود نیستند.
- چهار DTO (شیء انتقال داده) موجود: `CreateTodo`، `MutateTask`، `TaskQuery` و `TaskResult`. افزودن متدهای فرضی به آن‌ها در این طراحی لازم نیست. ویژگی‌های واقعی TaskResult عبارت‌اند از publicKey، displayKey، title، scope، owner، state، version و completedAt.
- `ExecutionContext`، قراردادهای `src/Contracts/`، `SubjectRegistry` و ثبت‌کننده‌های تقویم، مسیریابی و منبع گروه، نقاط اتصال میزبان‌اند.
- `Definition::stateMachine/validate/key/decode` ابزار واقعی ساخت و اعتبارسنجی تعریف گردش‌کار است.
- `TaskResourceService`، `CommandExecutor`، موتورهای تأیید/مسئولیت/تعهد خدمت، ثبت رویداد، ابزارهای شاخص و مالکیت نوشتن، متد عمومی فنی دارند؛ انتخاب «داخلی» برای راهنمای آموزشی، مجوز خصوصی‌کردن یا حذف آن‌ها نیست.
- `UI/` شامل اتصال‌های برد، تقویم و مدیریت ارتباط با مشتری است؛ رابط‌های `ContextBridge`، `Surface`، `BoundCursor` و سرویس‌های حاصل از `IntegrationFactory` نیز در پیوست ثبت شده‌اند. وابستگی اختیاری آن‌ها را نباید هنگام شروع Todo بارگذاری کرد.

## ۴. ساده‌ترین استفاده واقعی

مثال‌ها نام و ترتیب ورودی موجود را نشان می‌دهند. زمینه اجرا باید قبلاً با هویت معتبر میزبان ساخته شده باشد؛ این گزارش تابع احراز هویت یا متد تازه‌ای اختراع نمی‌کند. رشته‌های عملیات در مثال، شناسه یک فرمان منطقی‌اند؛ در استفاده واقعی برای فرمان بعدی شناسه تازه و برای تلاش مجدد همان فرمان، همان شناسه و همان ورودی حفظ می‌شود.

### ۴.۱ Todo شخصی: سه عملیات اصلی

مسیر پیشنهادی: `TaskManager::tasks()->inContext($context)`؛ حاصل یک `TaskSession` تغییرناپذیر است.

۱. `create('تماس با تأمین‌کننده', 'todo-001:create')` → یک TaskResult با state برابر pending، شناسه عمومی و نسخه فعلی.
۲. `get($todo->publicKey)` → همان شکل خروجی نوع‌دار.
۳. `complete($todo->publicKey, $todo->version, 'todo-001:complete')` → state برابر done و نسخه افزایش‌یافته.

حداقل مجوزهای این زنجیره: `task.create`، `task.view` و `task.edit`؛ هر سه در فهرست پیش‌فرض زمینه انسانی وجود دارند. مجوز مشاهده در ساخت و تکمیل نیز به علت خواندن خروجی اهمیت دارد. انتشار تنظیمات، ساخت Project، تعریف Workflow، Pipeline، Kanban یا Calendar لازم نیست؛ نصب و جدول‌های پکیج لازم‌اند.

`TaskService::createRecord` خودکار Inbox، تعریف personal، نمونه اجرای اصلی، کلید نمایشی، شاخص ساختار و رویداد ممیزی را فراهم می‌کند. جزئیات این جدول‌ها باید در راهنمای مرجع بماند، نه در شروع سریع.

خطاهای واقعی منتخب: `title_invalid` برای عنوان خالی یا بیش از ۲۵۵ نویسه، `version_conflict` برای نسخه قدیمی، `not_found` برای رکورد نامرئی، `transition_invalid` برای تکمیل دوباره با فرمان جدید. تکرار همان فرمان از مسیر ثبت عملیات بررسی می‌شود.

شاهد: `src/TaskSession.php`، `src/TaskService.php` و `tests/PersonalApiTest.php`.

### ۴.۲ ویرایش و بازگشایی Task شخصی

برای بازگشایی، روش موجود `tasks()->change(new MutateTask(...))` با action برابر `reopen` یا `tasks()->mutate($context, $id, $version, $operation, 'reopen')` است. برای ویرایش، action برابر `update` و values مانند `['priority' => 'high']` می‌آید. آرگومان values فقط title، description و priority را برای update می‌پذیرد؛ تغییر دلخواه فیلد یا state مجاز نیست.

برای فهرست نوع‌دار، `findTasks(new TaskQuery($context))` وجود دارد؛ خروجی فهرستی از TaskResult است. مسیر قدیمی `query($context, 1, 30)` آرایه سطرها برمی‌گرداند. TaskQuery فعلی فقط context، page و limit دارد و نباید فیلتر دلخواه به آن نسبت داد. جست‌وجوی پیشرفته مسیر جداگانه `queries()->tasks(...)` است.

actionهای واقعی mutate: update، complete، reopen، archive، restore، delete، undelete و move_list. حذف در این مسیر با deleted_at انجام می‌شود؛ حذف فیزیکی عمومی در این فهرست معرفی نشده است.

### ۴.۳ Project و Task پروژه: کوتاه‌ترین زنجیره فعلی

۱. تعریف واقعی مانند `Definition::stateMachine(['open' => ['terminal' => 'none'], 'closed' => ['terminal' => 'success']], 'open')` را بسازید؛ این محاسبه زمینه اجرا نمی‌خواهد.
۲. `workflows()->publish($context, 'team', 0, 'team:workflow', $definition)` یک revision (نسخه تعریف) با id برمی‌گرداند. صفر، شماره نسخه قبلی برای انتشار نخست است.
۳. `projects()->create($context, 'TEAM', 'پروژه تیم', $revision['id'], 'team:project')` پروژه و عضویت manager سازنده را می‌سازد. کد پروژه باید با حرف بزرگ آغاز شود و حداکثر ۲۰ حرف بزرگ/رقم داشته باشد.
۴. با `tasks()->create($context, 'پیگیری قرارداد', 'team:task')` کار شخصی اولیه ساخته می‌شود.
۵. `projects()->previewTaskMove($context, $task['public_key'], $project['project_key'], 'task', ['pending' => 'open'])` پیش‌نمایش نسخه‌دار می‌دهد.
۶. `projects()->moveTask($context, $preview, 'team:move')` کار را به پروژه می‌برد؛ خروجی آرایه کار با public_key ثابت، کلید نمایشی پروژه و نسخه جدید است. همان نمونه اصلی گردش‌کار به تعریف پروژه منتقل می‌شود؛ نباید برای آن دوباره start در slot اصلی فراخواند.

**در سورس، `ProjectService::createTask` وجود ندارد.** در کوتاه‌ترین مسیر فعلی، ایجاد شخصی سپس انتقال انجام می‌شود. این دشواری واقعی است؛ نباید با مثال ساختگی پنهان شود.

مجوزهای زنجیره نمونه: workflow.manage، workflow.view، project.manage، project.view، project.edit، task.create، task.view و task.edit. این فهرست برای همین زنجیره است؛ پیش‌فرض Todo برای پروژه کافی نیست. previewTaskMove دسترسی edit پروژه می‌خواهد ولی اجرای moveTask علاوه بر project.edit، پروژه را با manage می‌خواند؛ داشتن نقش member برای پیش‌نمایش لزوماً برای اجرا کافی نیست.

Pipeline در این زنجیره لازم نیست. برای تغییر وضعیت، نمونه اصلی را از `instances()->authorizedQuery($context)` با subject_type برابر task، subject_id برابر public_key و slot_key برابر main بخوانید؛ سپس `instances()->transition($context, $instance->instance_key, $instance->version, 'team:close', 'move.open.closed')`. مجوزهای workflow.view و workflow.transition و دسترسی ویرایش Subject (موجودیت موضوع گردش‌کار) لازم‌اند. نسخه این فراخوان، نسخه نمونه اجراست؛ نسخه Task را جای آن نگذارید.

تکمیل از `TaskSession::complete` یا mutate با action برابر complete روی کار پروژه به `workflow_command_required` می‌رسد. این حفاظت نباید با ساده‌سازی برداشته شود.

شاهد: `src/Workflow/ProjectService.php`، `src/Workflow/Definition.php`، `examples/Console/TaskManagerExample.php` و `tests/WorkflowCoreTest.php`. مثال Console علاوه بر پروژه، Pipeline هم می‌سازد؛ برای مسیر حداقلی پروژه آن بخش ضروری نیست.

### ۴.۴ Workflow مستقل برای موجودیت بیرونی

پس از انتشار تعریف، `instances()->start($context, $type, $subjectId, $revisionId, $operation)` مسیر واقعی شروع است؛ slot پیش‌فرض main است و pipelineKey، stageKey و caseKey اختیاری‌اند. نمونه اجرا با instance_key، current_state_key و version برمی‌گردد. نبود Pipeline به معنی نبود موتور گردش‌کار نیست.

برای مدل بیرونی باید SubjectHandler واقعی میزبان ثبت شود. نمونه موجود: `examples/Ticketing/TaskManagerTicketingProvider.php`، ثبت alias برابر `support.ticket` با `TicketSubject`. این مثال به پکیج Ticket Chat، مدل‌ها، عضویت‌ها و ساختار داده میزبان وابسته است؛ شروع مستقل بدون آن وابستگی نیست.

`SubjectHandler::query` محدودیت دسترسی فعلی میزبان را می‌افزاید؛ موتور شرط scope و شناسه را اضافه می‌کند. `guard` اعتبارسنجی و هماهنگی همان اتصال پایگاه داده را انجام می‌دهد. داده تجاری Ticket نزد میزبان می‌ماند؛ موتور، وضعیت فرآیند و تاریخچه انتقال را نگه می‌دارد.

انتقال عادی با `transition` و فرمان تجاری ویژه با `executeBusinessCommand` اجرا می‌شود. در TicketWorkflow موجود، `close_result` فرمان تجاری است؛ مجوز مستقل، دلیل و داده موردنیاز را نمی‌توان با انتقال عادی دور زد. برای پیشنهاد گزینه‌ها به کاربر `availableTransitions` موجود است؛ نتیجه آن جایگزین بررسی مجدد مجوز و شروط هنگام اجرای انتقال نیست.

`Definition::stateMachine` برای هر دو وضعیت متفاوت، یال عادی می‌سازد؛ پس انتخاب آن، تصمیم واقعی درباره مسیرهای مجاز است. محدودیت مجاورت Pipeline با transitionMode برابر sequential اعمال می‌شود؛ custom پیش‌فرض، به‌تنهایی معنی «حرکت ترتیبی» نمی‌دهد. برای گردش‌کار دارای مسیرهای محدود، تعریف صریح transitions موجود را بنویسید.

شاهد: `src/Workflow/InstanceService.php`، `src/Contracts/SubjectHandler.php`، `examples/Ticketing/` و `tests/TicketWorkflowPolicyTest.php`.

## ۵. همه تنظیمات موجود و اثر واقعی

مبدأ: `config/task-manager.php` و مصرف آن‌ها در `TaskManagerServiceProvider::register`. نصب پایه از مقادیر پیش‌فرض استفاده می‌کند؛ انتشار تنظیمات با برچسب واقعی `task-manager-config` فقط هنگام تغییر مقادیر لازم است.

| گزینه | پیش‌فرض | اثر و مثال واقعی تغییر | نیاز برای شروع |
|---|---|---|---|
| page_size | 30 | فعلاً خوانده نمی‌شود؛ تنظیم به 50، پیش‌فرض امضای query یا TaskQuery را تغییر نمی‌دهد. باید limit واقعی فراخوان را تغییر داد. | خیر |
| max_page_size | 100 | فعلاً خوانده نمی‌شود؛ مقدار 200 سقف اعتبارسنجی سرویس‌ها را بالا نمی‌برد. | خیر |
| export_directory | null | پیش‌فرض `storage/app/private/task-manager-exports`؛ نمونه تغییر: مسیر خصوصی `D:/private/task-manager-exports`. فقط ذخیره‌ساز خروجی محلی آن را مصرف می‌کند. | فقط خروجی فایل |
| export_max_bytes | 104857600 | سقف ذخیره فایل، حدود ۱۰۰ مگابایت؛ مقدار 52428800 سقف را به حدود ۵۰ مگابایت می‌رساند. | فقط خروجی فایل |
| webhook_allowed_hosts | [] | خالی یعنی دامنه عمومی HTTPS که بررسی نشانی و شبکه را بگذراند؛ خالی به معنی غیرفعال‌بودن نیست. نمونه محدودسازی: `['hooks.example.com']`. | فقط Webhook (ارسال رویداد HTTP) |
| notification_channels | ['in_app'] | سیاست مجازبودن کانال؛ با تغییر آن، فرستنده نصب نمی‌شود. کانال باید با پیاده‌سازی فرستنده میزبان سازگار باشد. | فقط اعلان |
| quiet_hours.timezone | UTC | منطقه زمانی ساعات سکوت؛ نمونه Asia/Tehran. زمان ذخیره‌شده را محلی نمی‌کند. | فقط سیاست اعلان |
| quiet_hours.start | null | نمونه 22:00؛ باید همراه end باشد. | اختیاری |
| quiet_hours.end | null | نمونه 07:00؛ در ساعات سکوت، زمان مجاز بعدی برگردانده می‌شود. برابر start نباشد. | اختیاری |

`DailyQuietHours` منطقه زمانی و قالب HH:MM را اعتبارسنجی می‌کند؛ تنظیم تنها یکی از start/end یا برابرکردن آن‌ها `quiet_hours_invalid` می‌دهد. کانال نامجاز از `nextAllowed` مقدار null می‌گیرد؛ مسیر مصرف‌کننده ممکن است اعلان را کنار بگذارد یا یادآور را لغو کند. این گزینه‌ها جایگزین مجوز عملیات نیستند.

**هیچ گزینه موجودی به نام workflow.enabled، todo.enabled یا project.enabled وجود ندارد.** برای کوتاه‌کردن نصب نباید چنین پرچم‌هایی در مثال معرفی شود.

## ۶. کدام عملیات زمینه اجرا می‌خواهند؟

| نوع عملیات | زمینه اجرا | دلیل / چیزی که می‌توان پنهان کرد |
|---|---|---|
| ساخت، خواندن، فهرست، شمارش و ویرایش Todo | لازم؛ در TaskSession یک بار ذخیره می‌شود | scope، مالکیت، اشتراک و مجوز؛ تکرار آرگومان را می‌توان با نشست موجود حذف کرد |
| Project، Workflow، Pipeline و Instance | لازم | مجوز، عضویت، قلمرو، نسخه و ثبت رویداد؛ تنظیم Workflow تصمیم دامنه است |
| نظر، فایل، فیلد، گزارش و خروجی داده | لازم | محدودکردن سطرهای قابل مشاهده و بررسی دسترسی فعلی |
| یادآور شخصی بدون Task | لازم | مالک و گیرنده و قلمرو همچنان مهم‌اند؛ task capability و Task لازم نیست |
| پردازش پس‌زمینه، اعلان، خودکارسازی و Webhook | لازم؛ بعضی مسیرها serviceActor می‌خواهند | مجوز صریح کارگر، صاحب عملیات، قلمرو و تبار رویداد |
| Definition::stateMachine/validate و تبدیل‌های زمان/عدد | لازم نیست | محاسبه و اعتبارسنجی داده؛ دسترسی به رکورد انجام نمی‌دهند |
| reminders()->parseTime | لازم نیست | تبدیل زمان؛ ایجاد یادآور جدا زمینه می‌خواهد |
| ساخت DTO، TaskResult::fromArray، دریافت handler از Registry | زمینه مستقیم لازم نیست | ساخت شیء/یافتن اتصال؛ اجرای عملیات handler زمینه می‌گیرد |
| دریافت گروه سرویس از TaskManager | لازم نیست | فقط انتخاب سرویس؛ عملیات بعدی زمینه دارد |
| ساخت board/calendar از IntegrationFactory | آرگومان زمینه مستقیم ندارد | ContextBridge در زمان عملیات زمینه معتبر را دریافت می‌کند؛ نبود آرگومان به معنی نبود کنترل امنیتی نیست |

ExecutionContext دارای scope، actor، correlationId، serviceActor، capabilities، originEventId و automationDepth است. مقدار capabilities صریح **جایگزین** فهرست پیش‌فرض می‌شود؛ به آن اضافه نمی‌شود. برای serviceActor، حذف فهرست مجوزها خطای `service_capabilities_required` دارد. شناسه‌های اصلی باید غیرخالی و حداکثر ۱۲۰ بایت باشند؛ تبار رویداد نیز اعتبارسنجی می‌شود.

**قابل پنهان‌سازی:** ساخت Inbox و personal، ثبت رویداد و شاخص، تزریق وابستگی‌ها، تکرار زمینه در Todo و نمایش همه ۴۱ سرویس در صفحه اول.

**نیازمند حفظ صریح یا مدیریت معتبر میزبان:** هویت و قلمرو، مجوزها، نسخه مورد انتظار، شناسه پایدار عملیات، نگاشت وضعیت هنگام جابه‌جایی، فرمان تجاری، انتخاب گیرنده دیگر و تنظیمات اتصال بیرونی. correlationId برای ردیابی درخواست است و جای operation را نمی‌گیرد.

شناسه عملیات برای هر تلاش مجدد نباید تصادفی تازه تولید شود. `CommandExecutor` نتیجه را با scope و operation ذخیره می‌کند، actor و محتوای فرمان را در اثر انگشت ورودی دخالت می‌دهد و هنگام بازپخش مجوز را دوباره می‌سنجد؛ ورودی متفاوت با همان شناسه، `idempotency_key_conflict` می‌دهد. کنترل نسخه نیز جلوی نوشتن روی داده قدیمی را می‌گیرد؛ خواندن خودکار آخرین نسخه و نوشتن بی‌خبر، جانشین امن آن نیست.

## ۷. قراردادهای میزبان: پیش‌فرض، الزام و خطای نبود اتصال

Provider یعنی تأمین‌کننده قرارداد و Container یعنی سامانه تزریق وابستگی Laravel. اغلب قراردادهای پیش‌فرض با bindIf ثبت می‌شوند؛ میزبان می‌تواند اتصال خودش را ارائه کند. سرویس‌های ساخته‌شده قبلی با عوض‌کردن اتصال، خودکار بازسازی نمی‌شوند؛ زمان ثبت اتصال مهم است.

| قرارداد / ثبت‌کننده | پیش‌فرض واقعی | چه زمانی تنظیم لازم است / رفتار نبود آن |
|---|---|---|
| AuthorizationProvider | ContextAuthorization | برای Todo پایه اتصال سفارشی لازم نیست؛ فقط capabilities را کنترل می‌کند و constrain آن خالی است. محدودیت owner/scope پکیج برقرار است. سیاست‌های اضافی میزبان باید در authorize و constrain پیاده شوند. |
| AuthorizationRevision | PackageAuthorizationRevision با مقدار ثابت package | میزبان دارای مجوزهای پویا برای اعتبار گزارش/خروجی، شناسه پایدار نسخه مجوز خود را بدهد؛ مقدار ثابت را ردیاب تغییر مجوز بیرونی فرض نکنید. |
| SubjectRegistry / SubjectHandler | فقط task با TaskSubjectHandler | برای مدل بیرونی لازم؛ alias ثبت‌نشده: subject_type_unregistered. Registry تغییرناپذیر است؛ متد register ندارد. |
| EntryRoutingProvider | UnavailableEntryRoutingProvider | برای مسیریابی مبتنی بر ساختار میزبان لازم؛ entry_routing_provider_required. start مستقیم بدون routing به آن نیاز ندارد. |
| ReminderRecipients | SelfReminderRecipients | گیرنده پیش‌فرض actor فعلی؛ گیرنده دیگر: reminder_recipient_denied مگر میزبان سیاست معتبر بدهد. |
| NotificationPreferences | DailyQuietHours | پیش‌فرض برای کانال و ساعات سکوت کافی است؛ سیاست متفاوت هر کاربر/قلمرو به اتصال میزبان نیاز دارد. |
| NotificationTransport | UnavailableNotificationTransport | برای ارسال واقعی لازم؛ notification_transport_unavailable. ثبت یادآور به معنی نصب فرستنده نیست. |
| AttachmentStorage | UnavailableAttachmentStorage | برای بازرسی/خواندن فایل لازم؛ attachment_storage_unregistered. پکیج محل عمومی بارگذاری فایل فراهم نمی‌کند. |
| ExportStorage | LocalExportStorage | خروجی محلی پیش‌فرض دارد؛ مسیر خصوصی، مجوز فایل و سقف حجم باید معتبر باشند. |
| SecretVault | UnavailableSecretVault | هنگام نیاز به راز امضای Webhook لازم؛ webhook_secret_unavailable. |
| DnsResolver / WebhookTransport | NativeDnsResolver / CurlWebhookTransport | پیش‌فرض واقعی دارند؛ ارسال پیش‌فرض به افزونه curl نیاز دارد و HTTPS/بررسی نشانی را حفظ می‌کند. |
| ResponsibilityResolver | ContextResponsibilityResolver | فقط self و actor فعلی؛ تیم/نقش/مسئول دیگر به تأمین‌کننده میزبان نیاز دارد. |
| CalendarRegistry / WorkingCalendar | ثبت‌کننده خالی | برای تقویم کاری نام‌دار لازم؛ calendar_unregistered. این با پکیج اختیاری نمایش Calendar فرق دارد. |
| StageLifecycle | StageLifecycleChain شامل NativeTaskRules، HandoffLifecycle، ApprovalEngine، AssignmentEngine و SlaEngine | پیش‌فرض حفاظتی موجود است؛ حذف زنجیره برای ساده‌سازی مجاز نیست. جایگزینی میزبان باید حفاظت‌های لازم را حفظ کند. |
| SubjectRouteRegistry | local و remote خالی | برای مسیرهای move/spawn و عملیات بیرونی لازم؛ route_unregistered. |
| GroupSourceRegistry | خالی | برای منبع بیرونی گروه لازم؛ group_source_unregistered. |
| CommitEvidenceProvider | PostgresCommitEvidence | اثبات زمان ثبت نهایی به PostgreSQL و track_commit_timestamp معتبر وابسته است؛ نبود شواهد null و در مسیر الزام اثبات، commit_evidence_unavailable می‌دهد. همه پایگاه‌ها چنین تضمینی ندارند. |
| Clock | SystemClock | برای شروع کافی؛ ساعت ثابت/قابل کنترل در آزمون‌ها قابل تزریق است. |
| ConsumerRegistry | singleton با notifications، automation و webhooks | مصرف‌کننده‌ها ثبت‌اند؛ اشتراک و اجرای پردازش پس‌زمینه خودکار از این ثبت نتیجه نمی‌شود. |
| IntegrationFactory | ساخت رابط با انتخاب صریح | نبود Kanban: kanban_package_required؛ نبود Calendar: calendar_package_required؛ CRM بدون پکیج/HostAccess: crm_host_binding_required. |

این جدول به معنی «هر سرویس بدون Provider ساخته نمی‌شود» نیست. Provider اصلی وابستگی‌های پیش‌فرض را متصل می‌کند؛ بعضی پیاده‌سازی‌ها عمداً هنگام استفاده از قابلیت، خطای نبود پشتیبانی می‌دهند.

### نمونه‌های واقعی اتصال، بدون معرفی پیاده‌سازی فرضی

- **دسترسی:** قرارداد واقعی `Contracts/AuthorizationProvider.php` و پیش‌فرض `ContextAuthorization.php`؛ نمونه سفارشی واقعی در `tests/FoundationTest.php` و `tests/EnterpriseHardeningTest.php` وجود دارد. این‌ها نمونه آزمون‌اند، نه پیاده‌سازی احراز هویت محصول. تبدیل سیاست واقعی میزبان به authorize/constrain نیاز به کد میزبان دارد؛ انتشار config کافی نیست.
- **موجودیت بیرونی:** `examples/Ticketing/TaskManagerTicketingProvider.php` ابتدا `SubjectRegistry::all()` را می‌گیرد و با حفظ handlerهای قبلی، یک `SubjectRegistry` تازه همراه `support.ticket` در Container قرار می‌دهد. حذف task از این مجموعه، مسیر کار بومی را مختل می‌کند.
- **مسیریابی:** همان Provider، EntryRoutingProvider را به DepartmentRouting متصل می‌کند. `DepartmentRouting.php` عضویت مفهومی قلمرو را از meta.scope_key و مجوز ticket.route بررسی می‌کند؛ نام department یا این سیاست، جزء هسته پکیج نیست.
- **گیرنده یادآور:** همان Provider، ReminderRecipients را به TicketReminderRecipients متصل می‌کند؛ گیرنده دیگر به reminder.assign و عضویت معتبر بخش محدود است.
- **اعلان:** قرارداد NotificationTransport یک deliver با context، eventId، recipient، channel و safeEnvelope دارد. نمونه موجود `tests/Support/RecordingNotificationTransport.php` داده را با کلید eventId:recipient:channel ثبت می‌کند؛ فرستنده محصول نیست. ارسال واقعی نیازمند پیاده‌سازی میزبان، اتصال آن در Container و جلوگیری از ارسال تکراری با همین سه شناسه است. API آماده‌ای برای ارسال از یک پکیج اعلان خاص در این سورس ادعا نمی‌شود.

زنجیره واقعی یادآور/اعلان: ثبت یادآور ← `reminders()->dispatch` ← رویداد reminder_due در خروجی رویدادها ← اشتراک notifications و اجرای `events()->run` ← NotificationConsumer ← NotificationTransport. dispatch خروجی queued می‌دهد و sent روی یادآور می‌گذارد؛ این به‌تنهایی اثبات تحویل بیرونی نیست. پردازش dispatch و EventPump به serviceActor معتبر و اجرای بیرون تراکنش باز نیاز دارند؛ زمینه انسانی شروع سریع را نمی‌توان بی‌تغییر به کارگر پس‌زمینه داد.

## ۸. مشکلات موجود و پیشنهاد دقیق اصلاح

| مشکل ثابت‌شده از سورس | پیشنهاد | نوع تغییر آینده |
|---|---|---|
| سه شکل استفاده Task: آرایه، DTO و نشست؛ شکل خروجی متفاوت | نشست برای سه عملیات آغازین، DTO برای عملیات نوع‌دار بیشتر، مسیر آرایه در مرجع سازگاری؛ جدول تبدیل public_key/publicKey و state/current_state_key | مستندسازی |
| نشست فقط create/get/complete دارد | محدودیت را صریح بگوییم؛ برای reopen/change/query همان TaskService موجود را آموزش دهیم | مستندسازی؛ گسترش نشست تنها با تصمیم جداگانه و آزمون سازگاری |
| Facade توضیحات نوعی مشابه Inventory ندارد | افزودن @method/@mixin مطابق امضاهای واقعی و آزمون عبور پویای متدها | تغییر فایل PHP برای راهنمای ویرایشگر؛ بدون نیاز به تغییر رفتار |
| Project متد ساخت مستقیم Task ندارد | زنجیره create → previewTaskMove → moveTask را واقعی نشان دهیم؛ اگر بعدها عملیات ترکیبی لازم شد، اتمی‌بودن، شناسه عملیات و بازپخش و حفاظت‌های دو سرویس طراحی و آزموده شود | اکنون مستندسازی؛ کوتاه‌کردن واقعی زنجیره نیازمند کد است، در این گزارش متد تازه تعریف نمی‌شود |
| حد مجوز پیش‌نمایش و اجرای انتقال پروژه یکسان نیست | تفاوت edit/manage صریح؛ آزمون نقش member برای پیش‌نمایش و اجرا | مستندسازی؛ تغییر سیاست مجوز تصمیم کدی/امنیتی جداگانه است |
| page_size و max_page_size تعریف شده ولی مصرف نمی‌شوند | صریحاً بی‌اثر بودن فعلی را مستند کنیم؛ سپس در کار جدا، اعمال یکنواخت با حفظ پیش‌فرض و سقف سازگار بررسی شود | مستندسازی اکنون؛ اعمال واقعی نیازمند کد و آزمون |
| شمارش ساده سرویس‌ها، ابزار کارگر و کار روزمره را کنار هم نشان می‌دهد | فهرست آموزشی سه‌سطحی: شروع، قابلیت تکمیلی، اتصال/عملیات نگهداری؛ هیچ متد حذف یا private نشود | مستندسازی |
| defaults برای پروژه و Workflow کافی نیستند | جدول مجوز هر زنجیره، شامل خواندن خروجی و بازپخش؛ ساخت زمینه از هویت معتبر میزبان | مستندسازی + کد میزبان برای اتصال واقعی هویت |
| تنظیم notification_channels با فرستنده اشتباه گرفته می‌شود | ثبت، صف‌کردن و تحویل را جدا توضیح دهیم و نمونه آزمایشی را فرستنده واقعی ننامیم | مستندسازی؛ ارسال واقعی کد میزبان می‌خواهد |
| Registryها API تغییر تدریجی ندارند | ساخت مجموعه جدید با حفظ اتصال‌های قبلی و ثبت قبل از ساخت سرویس مصرف‌کننده، مانند نمونه Ticketing | مستندسازی/اتصال میزبان |
| guard نام‌دار در تعریف Workflow نیاز به callable متناظر دارد ولی Provider اصلی guards را به InstanceService نمی‌دهد | این محدودیت در مرجع پیشرفته بیاید؛ تعریف guards بدون اتصال سفارشی، guard_unregistered می‌دهد | مستندسازی؛ فراهم‌کردن راه اتصال استاندارد تازه نیازمند کد جدا است |
| reports و enterpriseReports کاربرد متفاوت دارند | اول totals/subtreeTime/flow؛ سپس read/rebuild با اعتبارسنجی مجوز و داده | مستندسازی |
| Builder و lockForCommand جزئیات فنی را بیرون می‌دهند | در مرجع پیشرفته باقی بمانند؛ lockForCommand تراکنش فعال می‌خواهد و در شروع سریع نیاید | مستندسازی؛ خصوصی‌کردن آن‌ها ناسازگار است |

## ۹. طراحی پیشنهادی رابط عمومی، با نام‌های موجود

طراحی پیشنهادی در این مرحله «انتخاب مسیر رسمی و قرارداد آموزشی» است؛ امضای جدیدی پیشنهاد نمی‌شود:

| نیاز مصرف‌کننده | مسیر توصیه‌شده |
|---|---|
| شروع Todo | TaskManager → tasks → inContext → create/get/complete |
| تغییر، بازگشایی و فهرست نوع‌دار | tasks → change(MutateTask) / findTasks(TaskQuery) |
| ساخت پروژه | workflows → publish، سپس projects → create |
| قرار دادن کار در پروژه | tasks → create، سپس projects → previewTaskMove / moveTask |
| تغییر وضعیت کار تیمی یا موجودیت بیرونی | instances → get / availableTransitions / transition |
| فرمان خاص دامنه | instances → executeBusinessCommand با مجوز و دلیل واقعی |
| Pipeline و برد | pipelines و integrations فقط هنگام نیاز عملیاتی/نمایشی |
| مسیریابی مبتنی بر ساختار میزبان | entryRouting با EntryRoutingProvider واقعی |
| قابلیت تکمیلی | سرویس موجود مربوط به همان نیاز؛ بدون تنظیم مصنوعی برای فعال‌کردن هسته |

در صفحه آغازین فقط Todo، سپس مسیر پروژه و گردش‌کار معرفی شود. مرجع کامل همان ۴۱ گروه باقی بماند. روش‌های مستقیم عبوری Facade و روش‌های آرایه‌ای در بخش سازگاری با نمونه واقعی حفظ شوند. یکسان‌کردن خروجی همه سرویس‌ها به TaskResult تغییر ناسازگار است و در این طراحی انجام نمی‌شود.

## ۱۰. سازگاری و کنترل‌های امنیتی لازم برای هر اصلاح بعدی

۱. امضا، نام پارامتر، مقدار پیش‌فرض، نوع خروجی، alias اتصال و عبور __call حفظ شود؛ نام پارامترها برای فراخوان با آرگومان نام‌دار نیز قرارداد است.
۲. زمینه اجرا در Facade یا متغیر سراسری ذخیره نشود؛ TaskSession همان شیء مستقل و تغییرناپذیر هر درخواست بماند. نمونه تست جدایی زمینه‌ها در PersonalApiTest موجود است.
۳. کنترل scope (قلمرو داده)، owner (مالک)، grant (دسترسی اشتراکی)، عضویت پروژه و constrain میزبان در خواندن، شمارش، جست‌وجو و بازپخش حفظ شود؛ دانستن شناسه نباید مجوز دسترسی ایجاد کند.
۴. شناسه عملیات و نسخه مورد انتظار حذف یا خودکار با مقدار تازه جایگزین نشود. نسخه Task، Instance، Project و Pipeline با هم اشتباه نشود؛ پیش‌نمایش قدیمی باید دوباره اعتبارسنجی شود.
۵. زنجیره StageLifecycle و SubjectHandler::guard، سیاست انتقال و فرمان تجاری مستقل حفظ شوند. برد صاحب حقیقت وضعیت نیست؛ جابه‌جایی نمایشی نباید انتقال فرآیندی غیرمجاز بسازد.
۶. جایگزینی AuthorizationProvider به معنی واگذاری همه محدودیت‌های هسته نیست؛ authorize و constrain میزبان باید با حفاظت‌های داخلی ترکیب شوند.
۷. ارسال بیرونی پس از ثبت نهایی تراکنش، ثبت رویداد قابل پردازش مجدد، محدودیت‌های کارگر و جلوگیری از ارسال تکراری حفظ شود. دسترسی گیرنده و مجوز فعلی هنگام مصرف رویداد اهمیت دارند.
۸. فایل خروجی خصوصی، کنترل دسترسی مجدد دانلود، HTTPS، جلوگیری از دسترسی شبکه خصوصی از Webhook و امضای رویداد به بهانه شروع کوتاه حذف نشوند.

## ۱۱. اعتبارسنجی انجام‌شده و محدودیت شواهد

در این بررسی، مجموعه موجود شامل PersonalApiTest، DocumentationExamplesTest، WorkflowCoreTest، TicketWorkflowPolicyTest، EntryRoutingTest و GeneralReminderTest با PHPUnit 12.5.40 روی PHP 8.4.22 اجرا شد: **۳۰ آزمون، ۲۰۶ بررسی، همگی موفق**.

این آزمون‌ها مسیر نشست Todo، نمونه‌های مستندشده، پروژه و نسخه گردش‌کار، سیاست Ticket، مسیریابی ورود و یادآور عمومی را تأیید می‌کنند. هیچ تستی تغییر نکرد. این نتیجه ادعای اجرای کل مجموعه، ماتریس چند پایگاه داده یا تأیید یک میزبان واقعی احراز هویت/ارسال اعلان نیست. تست‌های هم‌زمانی و پکیج‌های بیرونی در این اجرای انتخابی گواهی نشده‌اند.

پیوست، موجودی کامل اعلان‌های عمومی است؛ تحلیل رفتاری این گزارش روی مسیرهای مصرف‌کننده، تنظیمات، نقاط اتصال و حفاظت‌های مؤثر بر ساده‌سازی تمرکز دارد و گواه ممیزی امنیتی جامع تمام پیاده‌سازی‌های داخلی نیست.

**تصمیم قابل اجرا برای مرحله بعد:** ابتدا مسیرهای موجود و وابستگی‌های واقعی مستند شوند؛ اصلاح توضیحات نوعی Facade و تصمیم درباره تنظیمات بی‌اثر به‌عنوان کارهای کدی مستقل ثبت شود. کوتاه‌سازی زنجیره ساخت Task پروژه تنها پس از طراحی تراکنش و آزمون سازگاری بررسی شود. برای خروجی این مرحله هیچ تغییر رفتاری در پکیج لازم نبود.
