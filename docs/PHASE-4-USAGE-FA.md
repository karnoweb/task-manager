# راهنمای استفادهٔ فاز ۴

این نمونه‌ها برای برنامهٔ Laravel (چارچوب میزبان PHP) پس از نصب بسته و اجرای migration (تغییر ساختار پایگاه‌داده) هستند. `ExecutionContext` هویت، قلمرو و مجوزهای تأییدشدهٔ میزبان را می‌گیرد؛ مقدار خام مرورگر نباید مبنای مجوز باشد. `operation_id` شناسهٔ درخواست است: برای همان درخواست نگهش دارید و هنگام تکرار، شناسهٔ تازه نسازید. نسخهٔ مورد انتظار باید از آخرین خواندنِ مجاز بیاید.

## Todo (کار شخصی)

```php
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Facades\TaskManager;

$context = new ExecutionContext('company-1', 'user-17', 'request-001');
$task = TaskManager::tasks()->create($context, 'تماس با مشتری', 'request-001-create');
$task = TaskManager::tasks()->mutate(
    $context, $task['public_key'], $task['version'], 'request-002-complete', 'complete'
);
```

کار شخصی در فهرست ورودی خود کاربر ساخته می‌شود. یک کاربر دیگر صرفاً با دانستن کلید آن نمی‌تواند بخواند یا تغییرش دهد. تکمیل مستقیمِ کار دارای گردش‌کار سفارشی مجاز نیست؛ انتقال معتبر گردش‌کار را اجرا کنید.

## پروژه و دورهٔ کاری

نمونهٔ زیر قلمرو تازه و کد پروژهٔ استفاده‌نشده را فرض می‌کند. آرایهٔ مجوزها در برنامهٔ واقعی باید از سیاست میزبان ساخته شود.

```php
$context = new ExecutionContext('company-1', 'user-17', 'project-request', capabilities: [
    'task.create', 'task.view', 'task.edit', 'list.create', 'list.view', 'activity.view',
    'workflow.manage', 'workflow.view', 'workflow.transition',
    'project.manage', 'project.edit', 'project.view',
    'schedule.update', 'sprint.manage', 'report.view',
]);
$definition = [
    'initial' => 'todo',
    'states' => [
        'todo' => ['terminal' => 'none', 'category' => 'todo'],
        'doing' => ['terminal' => 'none', 'category' => 'in_progress'],
        'done' => ['terminal' => 'success', 'category' => 'done'],
    ],
    'transitions' => [
        'start' => ['from' => 'todo', 'to' => 'doing'],
        'finish' => ['from' => 'doing', 'to' => 'done'],
    ],
];
$revision = TaskManager::workflows()->publish($context, 'delivery', 0, 'workflow-publish', $definition);
$project = TaskManager::projects()->create($context, 'APP', 'برنامه', $revision['id'], 'project-create');
$task = TaskManager::tasks()->create($context, 'ساخت صفحه', 'task-create');
$preview = TaskManager::projects()->previewTaskMove(
    $context, $task['public_key'], $project['project_key'], 'story', ['pending' => 'todo']
);
$task = TaskManager::projects()->moveTask($context, $preview, 'task-convert');
$task = TaskManager::planning()->estimate($context, $task['public_key'], $task['version'], 120, '3', 'estimate');
$sprint = TaskManager::sprints()->create($context, $project['project_key'], 'دورهٔ اول', null, null, 'sprint-create');
$sprint = TaskManager::sprints()->enroll($context, $sprint['sprint_key'], $sprint['version'], [
    ['task_key' => $task['public_key'], 'version' => $task['version']],
], 'sprint-enroll');
$sprint = TaskManager::sprints()->start($context, $sprint['sprint_key'], $sprint['version'], 'sprint-start');
$report = TaskManager::sprints()->report($context, $sprint['sprint_key']);
```

`live_scope` وضعیت زندهٔ اعضای مجاز و `scope_change_points` تغییر دامنهٔ فوری را نشان می‌دهند. `current` آخرین تصویر ثبت‌شده و `historical_scope_change_points` تغییر دامنه در همان تصویر است. `committed` تصویر تعهد هنگام شروع است. تغییر امتیاز فعلی، آن را عوض نمی‌کند. برای ثبت روز جدید، `snapshot($context, $key, $version, $operation)` را همان روز فراخوانی کنید؛ درخواست تکراری تصویر اضافه نمی‌سازد. گزارش تاریخی به ثبت واقعی این تصاویر وابسته است؛ روزهای اجرا‌نشده بازسازی حدسی نمی‌شوند.

برای اتمام کار پروژه، شناسه و نسخهٔ نمونهٔ اصلی گردش‌کار را از خواندن مجاز بگیرید و `instances()->transition(...)` را با کلید انتقال تعریف‌شده اجرا کنید. برای بستن دوره از `sprints()->close(...)` با سیاست `backlog`، `carry` یا `cancel` استفاده کنید. حالت انتقال، کلید و نسخهٔ دورهٔ مقصد را می‌خواهد. سرعت تحویل از تصویر بسته‌شدن و فقط برگ‌های واقعاً تمام‌شده محاسبه می‌شود؛ پیش از بستن یا با امتیاز نامشخص، مقدار `null` است.

## نقشهٔ رابط‌های عمومی

| دسترسی از نمای یکپارچه | عملیات اصلی |
|---|---|
| `hierarchy()` | `reparent`, `configureDepth`, `tree`, `progress`, `previewDelete`, `executeDelete` |
| `projects()` | `previewSubtreeMove`, `moveSubtree`؛ پیش‌نمایش کامل شاخه و نگاشت وضعیت هر نوع |
| `dependencies()` | `add`, `addBatch`, `get`, `links`, `remove`؛ هر دو نسخه و مجوز دو طرف لازم است |
| `planning()` | `estimate`, `previewSchedule`, `executeSchedule`, `criticalPath` |
| `checklists()` | `create`, `get`, `change`, `addItem`, `item`, `changeItem`, `items`, `progress`, `convert` |
| `collaboration()` | نظر/ویرایش/حذف/بازگردانی، پاسخ، سابقه، دنبال‌کننده و گیرندگان مجاز اعلان |
| `attachments()` | `attach`, `issueTicket`, `download`؛ میزبان باید `AttachmentStorage` را پیاده‌سازی کند |
| `templates()` | `publish`, `get`, `instantiate`؛ نسخهٔ قالب هنگام ایجاد پروژه صریح است |
| `sprints()` | `create`, `get`, `enroll`, `remove`, `start`, `snapshot`, `close`, `report`, `backlog` |
| `positions()` | `create`, `get`, `place`, `listing`؛ زمینه‌های `backlog/sprint/children` |
| `markers()` | `create`, `get`, `change`, `link`, `progress`؛ انواع `milestone/release` |
| `time()` | `start`, `timer`, `stop`, `cancelOwn`, `log`, `amend`, `entry`, `stats`, `rebuild` |
| `reports()` | `totals`, `subtreeTime`, `flow` |
| `bulk()` | `execute` با حالت `atomic` یا `partial` |

امتیازها رشتهٔ اعشاری حداکثر دو رقم هستند؛ مجموع‌ها دقیق محاسبه می‌شوند. تاریخ‌های ورودی `DateTimeImmutable` (تاریخ غیرقابل تغییر) هستند و به UTC (زمان هماهنگ جهانی) تبدیل می‌شوند. برای رتبه‌گذاری، نسخهٔ زمینه و کار و همسایه‌های مجاز را بفرستید؛ عدد رتبه را خودتان نسازید.

مجوزهای جدید بر حسب نیاز: `task.reparent`, `task.delete`, `task.reorder`, `dependency.manage`, `schedule.update`, `report.view`, `checklist.manage`, `comment.create`, `comment.edit_own`, `comment.moderate`, `watcher.manage`, `attachment.add/remove/download`, `template.manage`, `sprint.manage`, `milestone.manage`, `release.manage`, `time.log`, `time.manage_others`, `bulk.manage`. وجود مجوز کلی کافی نیست؛ سیاست میزبان و دسترسی به منبع نیز بررسی می‌شوند.

پیش‌نمایش، سند قابل اعتماد مرورگر نیست؛ اجرای آن همهٔ مقادیر مؤثر، نسخه‌ها و مجوزها را دوباره بررسی می‌کند. `preview_stale` یا `version_conflict` یعنی باید دوباره بخوانید و تصمیم تازه بگیرید. تکرار همان عملیات با محتوای متفاوت `idempotency_key_conflict` می‌دهد.

نمونه‌های اجرایی مفصل در `tests/PhaseFourAcceptanceTest.php` و فایل‌های مستقل `tests/Scenarios/TM013Test.php` تا شناسه‌های مالک فاز ۴ قرار دارند. این راهنما ادعای اتصال نهایی رابط کاربری یا پذیرش فاز ۷ نیست.
