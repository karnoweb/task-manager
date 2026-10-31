# گردش‌کار مستقل و انتقال وضعیت

[فهرست](../README.md) · قبلی: [Task و Project](../03-basic-usage/tasks-projects.md)

Workflow (تعریف گردش‌کار) وضعیت‌ها و انتقال‌ها را مشخص می‌کند؛ Workflow Instance (نمونه اجرای گردش‌کار) اجرای آن تعریف برای یک Subject (موجودیت موضوع فرآیند) است. Pipeline (چیدمان عملیاتی مراحل) و Board (برد نمایشی) برای موتور پایه الزامی نیستند؛ برد صاحب وضعیت فرآیند نیست.

## پیش‌نیاز و تصمیم نمونه

نصب Laravel 13 و پکیج، جدول‌ها و کاربر معتبر لازم‌اند. [Gate نمونه صفحه قبل](../03-basic-usage/tasks-projects.md#اجازه-تنظیم-پروژه-در-نمونه-آموزشی) را در همان نشست اجرا کنید. مثال به‌صورت مستقل از Project و Pipeline، یک فرآیند بازبینی را برای Task بومی اجرا می‌کند؛ handler (رابط اتصال موجودیت) task از قبل در SubjectRegistry (فهرست رابط‌های موجودیت) ثبت شده است.

هر نمونه اجرا به یک Subject نیاز دارد؛ «مستقل» به معنی اجرای بدون Subject نیست. Task تازه از قبل نمونه personal در slot (جایگاه فرآیند) main دارد. بنابراین بازبینی مستقل را در slot دیگری به نام review شروع می‌کنیم. پایان review، Todo اصلی را تکمیل نمی‌کند. این انتخاب، نیاز به مدل بیرونی یا Migration میزبان تازه را از مثال حذف می‌کند.

## تعریف، شروع، انتقال عادی و فرمان تجاری

فایل خصوصی `storage/app/private/task-manager-docs/workflow.php` میزبان؛ Namespace (فضای نام)، use (کلاس‌های مصرفی) و ورودی‌های تابع داخل نمونه مشخص‌اند:

<!-- example: independent-workflow -->
```php
<?php

namespace App\TaskManagerDocs;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\DTO\MutateTask;
use Karnoweb\TaskManager\Facades\TaskManager;

return static function (Authenticatable $user, string $operation): array {
    Gate::forUser($user)->authorize('task-manager.docs.configure');
    if (! Str::isUuid($operation)) {
        throw new \InvalidArgumentException('operation must be a UUID');
    }
    $actor = (string) $user->getAuthIdentifier();
    $context = new ExecutionContext(
        'personal:'.$actor, $actor, (string) Str::uuid(),
        capabilities: [
            'task.create', 'task.view', 'task.edit',
            'workflow.manage', 'workflow.view', 'workflow.transition', 'document.approve',
        ],
    );
    $definition = [
        'initial' => 'draft',
        'states' => [
            'draft' => ['terminal' => 'none'],
            'review' => ['terminal' => 'none'],
            'approved' => ['terminal' => 'success'],
        ],
        'transitions' => [
            'submit' => ['from' => 'draft', 'to' => 'review'],
            'approve' => [
                'from' => 'review', 'to' => 'approved',
                'command' => 'approve_result', 'capability' => 'document.approve',
                'reason_required' => true, 'reason_codes' => ['approved'],
                'required_fields' => ['result'],
            ],
        ],
    ];
    $revision = TaskManager::workflows()->publish(
        $context, 'docs.review.'.str_replace('-', '', strtolower($operation)),
        0, $operation.':publish', $definition,
    );
    $task = TaskManager::tasks()->inContext($context)->create('بررسی سند', $operation.':task');
    $edited = TaskManager::tasks()->change(new MutateTask(
        $context, $task->publicKey, $task->version, $operation.':edit',
        'update', ['priority' => 'high'],
    ));
    $started = TaskManager::instances()->start(
        $context, 'task', $edited->publicKey, $revision['id'], $operation.':start', slot: 'review',
    );
    $available = TaskManager::instances()->availableTransitions($context, $started['instance_key']);
    $submitted = TaskManager::instances()->transition(
        $context, $started['instance_key'], $started['version'], $operation.':submit', 'submit',
    );
    $approved = TaskManager::instances()->executeBusinessCommand(
        $context, $submitted['instance_key'], $submitted['version'], $operation.':approve',
        'approve_result', ['result' => 'تأیید شد', 'reason_code' => 'approved'],
    );
    $current = TaskManager::tasks()->inContext($context)->get($task->publicKey);

    return compact('revision', 'edited', 'started', 'available', 'submitted', 'approved', 'current');
};
```

اجرا در Tinker (محیط تعاملی Laravel)، پس از ورود کاربر ۱ و ثبت Gate: `$run = require storage_path('app/private/task-manager-docs/workflow.php');`، `$operation = (string) \Illuminate\Support\Str::uuid();` و `$result = $run(auth()->user(), $operation);`.

user باید از احراز هویت معتبر میزبان باشد. scope از شناسه همین کاربر ساخته شده و مجوزها پس از تأیید Gate به‌صورت ثابت در سرور انتخاب شده‌اند؛ کاربر نمی‌تواند آن‌ها را از درخواست تعیین کند. document.approve نام مجوز دامنه‌ای تعریف‌شده در **همین مثال** است؛ قابلیت آماده یا اتصال به سامانه اسناد بیرونی نیست. برای اجرای این مثال Provider یا تنظیم سفارشی لازم نیست. قابلیت‌های پایه Workflow در مجوزهای پیش‌فرض Todo نیستند.

### ورودی و خروجی واقعی

- publish: context، key، expectedRevision، operation و definition؛ خروجی آرایه دارای id، key، revision و definition. برای انتشار بعدی، شماره قبلی معتبر لازم است؛ تغییر تعریف، اجرای قبلی را خودکار به نسخه جدید منتقل نمی‌کند.
- start: context، type، subjectId، revisionId و operation؛ slot پیش‌فرض main است؛ pipelineKey، stageKey و caseKey اختیاری‌اند. خروجی آرایه با instance_key، subject_id، workflow_revision_id، current_state_key، version و lifecycle.
- transition: شناسه و **نسخه نمونه اجرا**، operation، نام انتقال و payload (داده فرمان). availableTransitions گزینه‌های عادی را با requires_validation نشان می‌دهد؛ تأیید قطعی مجوز/شرط/Guard (شرط محافظ) هنگام اجراست.
- executeBusinessCommand: به‌جای نام یال approve، نام command یعنی approve_result می‌گیرد. انتقال عادی نمی‌تواند فرمان تجاری را اجرا کند. reason_code باید از فهرست مجاز و result غیرخالی باشد.

available در ابتدای مثال فقط submit به review را پیشنهاد می‌کند. بعد از submit، یال فرمان تجاری در availableTransitions فهرست نمی‌شود؛ نبودن آن به معنی نبود قابلیت executeBusinessCommand نیست.

## اثر پایگاه داده و تفاوت نسخه‌ها

در اجرای تازه و بدون تغییر هم‌زمان:

| نقطه | نسخه Task | نسخه review Instance | وضعیت review | وضعیت main شخصی |
|---|---|---|---|---|
| ساخت Task | ۱ | هنوز نیست | — | pending |
| ویرایش اولویت | ۲ | هنوز نیست | — | pending |
| start | ۳ | ۱ | draft | pending |
| submit | ۴ | ۲ | review | pending |
| approve_result | ۵ | ۳ | approved | pending |

edited نسخه ۲ یک TaskResult است؛ started نسخه ۱ یک آرایه Instance است. current نسخه ۵ و state برابر pending دارد؛ get Task وضعیت نمونه main را نشان می‌دهد، نه فرآیند review. برای ادامه review از instance_key و version همان Instance استفاده کنید؛ عدد نسخه Task را به transition ندهید.

publish در `tm_workflow_heads` و `tm_workflow_definitions` نسخه تعریف ثبت می‌کند و شاخص وضعیت‌ها را می‌سازد. start یک نمونه review در `tm_workflow_instances` و جایگاه فعال آن در `tm_active_process_slots` می‌سازد. انتقال‌ها در `tm_instance_transitions` ثبت می‌شوند؛ Task مرتبط نیز نسخه می‌گیرد. پایان موفق، lifecycle را closed می‌کند و جایگاه فعال review را آزاد می‌کند؛ جایگاه main شخصی باقی می‌ماند. فرمان‌ها و رویدادها در دفتر عملیات، ممیزی و خروجی رویداد ثبت می‌شوند. پروژه، Pipeline و رکورد تجاری بیرونی ساخته نمی‌شوند.

همان operation و همان ورودی در تلاش مجدد حفظ شوند. در مثال چندمرحله‌ای، نتیجه start بازپخش‌شده نسخه آغازین را دارد؛ برای **فرمان تازه پس از اجرای قبلی** از `instances()->get($context, $key)` وضعیت فعلی را بخوانید و قصد جدید را با شناسه تازه اجرا کنید. خواندن خودکار آخرین نسخه بدون بازبینی کاربر، جایگزین کنترل تعارض نیست.

## اجرای اصلی Task پروژه

برای کار پروژه، نمونه main را با `instances()->authorizedQuery($context)` و فیلترهای subject_type برابر task، subject_id برابر public_key و slot_key برابر main پیدا کنید. سپس شناسه و نسخه همان نمونه را به transition بدهید؛ برای تعریف صفحه قبل نام انتقال finish است. این مسیر به workflow.view، workflow.transition و دسترسی ویرایش Task نیاز دارد. برای کار پروژه نمونه main تازه start نکنید؛ انتقال به پروژه همان نمونه را نگه می‌دارد.

## موجودیت خارجی، Pipeline و Guard

برای مدل خارجی، `Karnoweb\TaskManager\Contracts\SubjectHandler` و alias پایدار آن باید در `Karnoweb\TaskManager\SubjectRegistry` ثبت شوند. query باید مجوز فعلی میزبان را محدود کند؛ موتور شرط scope و شناسه را اضافه می‌کند. guard همین قرارداد برای هماهنگی/اعتبارسنجی همان اتصال پایگاه داده است. نمونه واقعی موجود [Ticketing Provider](../../examples/Ticketing/TaskManagerTicketingProvider.php) و [TicketSubject](../../examples/Ticketing/TicketSubject.php) است؛ آن نمونه به پکیج و مدل‌های Ticket Chat نیاز دارد و در مثال مستقل بالا استفاده نشده است.

Guard نام‌دار داخل definition با SubjectHandler::guard یکی نیست. اکنون میزبان می‌تواند مجموعه تغییرناپذیر `TransitionGuards` را در Container (تزریق وابستگی Laravel) متصل کند؛ نیازی به بازسازی InstanceService نیست. [نمونه کامل ثبت و اجرا](custom-guards.md) را ببینید. آرایه guards سازنده قدیمی، حتی اگر مقدار نام معین نامعتبر باشد، بر مجموعه جدید تقدم دارد؛ نام غایب همچنان guard_unregistered می‌دهد. مثال مستقل بالا بدون Guard سفارشی هم کار می‌کند.

Pipeline اختیاری است؛ ساخت آن با `pipelines()->create` و نگاشت stateها انجام می‌شود. transitionMode واقعی custom، sequential و free است؛ sequential مجاورت مراحل را محدود می‌کند. `Definition::stateMachine` یال‌های عادی بین همه وضعیت‌های متفاوت می‌سازد؛ برای مسیر محدود، مانند همین مثال transitions صریح بنویسید. در این صفحه Pipeline جدیدی لازم نیست.

## خطا و رفع

خطاهای دامنه `Karnoweb\TaskManager\Exceptions\DomainError` با getMessage و details هستند. جدول، رفتار واقعی را توضیح می‌دهد؛ کد نمونه مسیر موفق است و آزمون‌ها مسیرهای شکست را جدا می‌سنجند.

| خطا | علت / رفع |
|---|---|
| active_slot_conflict | فرآیند فعال در همان Subject/slot؛ نمونه فعلی را بخوانید یا فقط برای فرآیند واقعاً مستقل جایگاه جدا انتخاب کنید. |
| blueprint_reserved | نام personal برای هسته رزرو است؛ key متفاوت انتخاب کنید. |
| workflow_invalid / transition_invalid | تعریف یا انتقال با حالت فعلی سازگار نیست؛ initial، states، transitions و نسخه جاری را بازبینی کنید. |
| version_conflict | نسخه Instance قدیمی است؛ get و بازبینی، سپس فرمان تازه. |
| business_command_required | یال فرمان تجاری با transition فراخوانده شده؛ executeBusinessCommand لازم است. |
| business_command_invalid | فرمان در حالت فعلی دقیقاً یک یال متناظر ندارد؛ تعریف و current_state_key را بررسی کنید. |
| required_field_missing | مثلاً result نیست/خالی است؛ داده معتبر را با فرمان جدید بفرستید. |
| reason_required / reason_invalid | reason_code جا افتاده یا خارج از فهرست است؛ دلیل معتبر بدهید. |
| capability_denied / not_found | مجوز، دسترسی Subject، actor و scope را بررسی کنید. |
| subject_type_unregistered | رابط موجودیت در Registry نیست؛ اتصال معتبر میزبان با حفظ task را ثبت کنید. |
| guard_unregistered / guard_veto | اتصال محافظ غایب است یا شرط را رد کرده؛ ثبت و قاعده را اصلاح کنید، نه حذف کنترل. |
| transition_policy_denied / capacity_exceeded | سیاست Pipeline یا ظرفیت مرحله مانع است؛ مقصد/ظرفیت مجاز را بررسی کنید. |
| instance_on_hold | اجرای منتظر اجازه این حرکت را ندارد؛ انتظار را از مسیر دامنه حل کنید. |

آزمون [TechnicalDocumentationExamplesTest](../../tests/TechnicalDocumentationExamplesTest.php) بلوک بالا را اجرا می‌کند و وضعیت، نسخه‌ها، دو slot، ثبت تاریخچه، بازپخش، مجوز فرمان و دلیل را می‌سنجد. نتیجه در [فهرست راهنما](../README.md#نتیجه-اعتبارسنجی) ثبت می‌شود. این آزمون جای گواه سازگاری مدل خارجی یا چند پایگاه داده را نمی‌گیرد.
