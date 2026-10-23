# نقشه فنی اولیه Task Manager برای توسعه‌دهنده

**وضعیت:** استخراج اولیه از سورس ZIP؛ شامل توصیف قراردادهای موجود، نه وعده API جدید.  
**هدف:** پیش از بازنویسی مستندات، بفهمیم توسعه‌دهنده در هر سطح باید سراغ چه چیزی برود.

## سه لایه استفاده

### ۱. استفاده ساده (Personal Todo)

- ورودی رسمی: `Karnoweb\TaskManager\Facades\TaskManager` و `TaskManager::tasks()`.
- سرویس: `src/TaskService.php`.
- مسیر نوع‌دار: `createTodo(CreateTodo)`، `change(MutateTask)`، `findTasks(TaskQuery)`.
- مسیر روان‌تر موجود: `inContext(ExecutionContext)` ← `TaskSession::create/get/complete`.
- داده خروجی: `TaskResult`، شامل `publicKey`، `displayKey`، `state` و `version`.
- Todo ساده بدون Project ایجاد می‌شود؛ موتور `personal` و Inbox داخلی پکیج آن را پشتیبانی می‌کنند.
- هنگام تکمیل نسخه فعلی رکورد و شناسه عملیات لازم است؛ باید در راهنمای استفاده روشن شود چرا.

### ۲. مدیریت پروژه

- `projects()` برای پروژه و تغییرات و جابه‌جایی Task.
- `workflows()` برای تعریف و انتشار نسخه گردش‌کار.
- `pipelines()` برای مسیر عملیاتی/نگاشت مراحل.
- `instances()` برای شروع و تغییر وضعیت اجرای واقعی، Preview Move و جابه‌جایی بین Pipelineها.
- `hierarchy()` و `dependencies()` برای ساختار چندسطحی و وابستگی.
- `assignments()`، `approvals()`، `collaboration()`، `checklists()`، `attachments()` برای تعامل تیم.
- `planning()`، `sprints()`، `time()`، `dates()`، `recurrences()` و `reminders()` برای برنامه‌ریزی.

### ۳. Process Engine برای مدل خارجی

- Subject خارجی مدل خودش را نزد اپلیکیشن میزبان حفظ می‌کند.
- قرارداد `SubjectHandler` و ثبت در `SubjectRegistry` اتصال دامنه را ایجاد می‌کند.
- `instances()` مالک «وضعیت فرآیند» است، نه صاحب داده فروش یا Ticket.
- `entryRouting()` برای تصمیم ورود عملیاتی بر اساس Provider میزبان است.
- `handoffs()`، `routes()`، `sagas()` و `cases()` برای ارجاع، بازگشت و ارتباط چند موجودیت.
- `sla()`، `stagePolicies()`، `groups()`، `bulk()` و `automation()` برای قواعد پیشرفته.
- `integrations()` اتصال‌های اختیاری UI را ارائه می‌کند.

## مفهوم‌های حیاتی

| مفهوم | مالک یا محل مسئولیت | نکته مصرف‌کننده |
|---|---|---|
| `Task` | هسته Task Manager | کار شخصی می‌تواند بدون Project باشد |
| `Project` | Task Manager | سازمان‌دهی کار تیمی و Workflow نوع Task |
| `Workflow` | Task Manager | تعریف وضعیت‌ها و انتقال‌ها، نسخه‌دار |
| `Pipeline` | Task Manager | چیدمان مراحل عملیاتی و سیاست حرکت |
| `Stage` | Task Manager در Pipeline | مرحله مرتبط با وضعیت |
| `Workflow Instance` | Task Manager | حقیقت فعلی وضعیت فرآیند یک Subject |
| `Subject` خارجی | پروژه میزبان | هویت و اطلاعات تجاری در میزبان می‌ماند |
| `Board` | لایه Kanban/میزبان | نمایش است، نه منبع حقیقت وضعیت |
| `ExecutionContext` | ساخته‌شده با هویت قابل اعتماد میزبان | Scope، Actor و مجوز باید در هر عملیات رعایت شوند |
| `Operation ID` | فراخوان عملیات | جلوگیری از اجرای دوباره؛ در فراخوان‌های تلاش مجدد حفظ شود |
| `Version` | داده جاری | جلوگیری از نوشتن روی نسخه قدیمی |

## تنظیمات و اتصال‌های قابل تنظیم موجود

**Config واقعی (`config/task-manager.php`):** اندازه پیش‌فرض/حداکثر صفحه، مسیر/سقف خروجی، دامنه‌های مجاز Webhook، کانال‌های اعلان و ساعات سکوت. برخی اندازه‌های صفحه صرفاً تعریف شده‌اند و سرویس‌ها حد را مستقل کنترل می‌کنند؛ نباید اثر Config را بیش از پیاده‌سازی ادعا کنیم.

**قراردادهای Container:** `AuthorizationProvider`، `EntryRoutingProvider`، `SubjectRegistry`، `ReminderRecipients`، `NotificationPreferences`، `NotificationTransport`، `AttachmentStorage`، `StageLifecycle` و سایر قراردادهای `src/Contracts`. بعضی پیاده‌سازی پیش‌فرض دارند، بعضی عمداً به صورت Unavailable هستند؛ جدول تنظیم کامل باید از Service Provider استخراج شود.

## نکات اجرایی برای بازطراحی

۱. به‌جای ساخت Facade جدید، اول مسیر موجود `tasks()->inContext()` را در Quick Start آموزش دهیم؛ برای استفاده با DTO نیز بخش مرجع بنویسیم.
۲. چنانچه به Helper جدید نیاز بود، Context امن همچنان ورودی آشکار/استخراج‌شده از Request معتبر باشد؛ Scope و Actor را از سمت کلاینت اعتماد نکنیم.
۳. ۴۱ گروه سرویس فعلی را با یک فهرست کاربردی دسته‌بندی کنیم، نه آنکه همه در صفحه اول نمایش دهیم.
۴. قابلیت‌ها را با گزینه Config ساختگی خاموش/روشن نکنیم؛ تفاوت تنظیم اختیاری، Service Binding و خطای عدم پشتیبانی را مشخص کنیم.
۵. مستندات امنیتی و نمونه Ticketing را برای `executeBusinessCommand`، `transition`، `moveToPipeline` و مسیریابی اجباری کنیم.
۶. برای هر مثال، آزمون `DocumentationExamplesTest` یا آزمون یکپارچه متناظر داشته باشیم.

## شواهد سورس

- `src/Facades/TaskManager.php`
- `src/TaskManager.php`
- `src/TaskService.php`
- `src/TaskSession.php`
- `src/Context/ExecutionContext.php`
- `src/DTO/{CreateTodo,MutateTask,TaskQuery,TaskResult}.php`
- `src/TaskManagerServiceProvider.php`
- `src/Workflow/{WorkflowService,PipelineService,InstanceService,ProjectService,EntryRoutingService}.php`
- `src/SubjectRegistry.php` و `src/Contracts/SubjectHandler.php`
- `config/task-manager.php`
- `tests/{PersonalApiTest,DocumentationExamplesTest,TicketWorkflowPolicyTest}.php`
- `dev/KARNOWEB-TASK-MANAGER-ENTERPRISE-PRD-v2.0-FA.md`
