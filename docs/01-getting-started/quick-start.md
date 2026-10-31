# نصب و نخستین Todo

[فهرست راهنما](../README.md) · بعدی: [Task و Project](../03-basic-usage/tasks-projects.md)

## نصب در Laravel 13

پکیج به PHP نسخه `^8.3` و مؤلفه‌های Illuminate نسخه `^13.0` نیاز دارد؛ الزامات PHP خود Laravel 13 نیز باید برقرار باشد. فایل [composer.json](../../composer.json) مرجع وابستگی‌های پکیج است. در برنامه Laravel با دسترسی به پکیج در Composer (مدیر وابستگی PHP)، اجرا کنید:

```bash
composer require karnoweb/task-manager
php artisan migrate
```

Service Provider (ثبت‌کننده سرویس) و Migrationها (تعریف تغییرات ساختار پایگاه داده) خودکار بارگذاری می‌شوند. اگر کشف خودکار پکیج را غیرفعال کرده‌اید، `Karnoweb\TaskManager\TaskManagerServiceProvider::class` را در `bootstrap/providers.php` برنامه ثبت کنید. برای شروع، Project، Pipeline (چیدمان مراحل)، Kanban یا Calendar لازم نیست.

انتشار تنظیمات فقط برای تغییر پیش‌فرض‌هاست: `php artisan vendor:publish --tag=task-manager-config`. تنظیمات در `config/task-manager.php` میزبان قرار می‌گیرند. Todo از تنظیم اختیاری دیگری استفاده نمی‌کند؛ بررسی وابستگی‌ها و نصب در میزبان خودتان لازم است.

## مثال کامل: ساخت، خواندن و تکمیل

کوتاه‌ترین شروع در درخواست احرازهویت‌شده، `TaskManager::tasks()->inContext()->create(...)` است؛ [نمونه کوتاه و تنظیم هویت](resolvers.md) ابتدا این مسیر را توضیح می‌دهد. مثال کامل زیر عمداً Context (زمینه اجرا) صریح قدیمی را برای نمایش Scope اختصاصی و سازگاری حفظ کرده است؛ برای استفاده روزمره ساخت دستی آن لازم نیست.

کد زیر را در فایل خصوصی `storage/app/private/task-manager-docs/todo.php` میزبان ذخیره کنید؛ پوشه را در صورت نیاز بسازید. فایل در Namespace (فضای نام) نمونه میزبان است، نه فضای نام پکیج. خروجی require یک تابع بی‌نام با ورودی مدل `Authenticatable` (کاربر قابل احراز هویت Laravel) و شناسه عملیات است.

<!-- example: personal-todo -->
```php
<?php

namespace App\TaskManagerDocs;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Facades\TaskManager;

return static function (Authenticatable $user, string $operation): array {
    if (! Str::isUuid($operation)) {
        throw new \InvalidArgumentException('operation must be a UUID');
    }

    $actor = (string) $user->getAuthIdentifier();
    $context = new ExecutionContext(
        scope: 'personal:'.$actor,
        actor: $actor,
        correlationId: (string) Str::uuid(),
        capabilities: ['task.create', 'task.view', 'task.edit'],
    );

    $session = TaskManager::tasks()->inContext($context);
    $created = $session->create('تماس با تأمین‌کننده', $operation.':create');
    $read = $session->get($created->publicKey);
    $completed = $session->complete(
        $created->publicKey, $created->version, $operation.':complete',
    );

    return ['created' => $created, 'read' => $read, 'completed' => $completed];
};
```

در `php artisan tinker`، ابتدا با `auth()->loginUsingId(1)` کاربر واقعی موجود در برنامه را انتخاب کنید؛ اگر کاربر ۱ ندارید شناسه معتبر خودتان را بدهید و موفقیت ورود را بررسی کنید. سپس `$run = require storage_path('app/private/task-manager-docs/todo.php');`، `$operation = (string) \Illuminate\Support\Str::uuid();` و `$result = $run(auth()->user(), $operation);` را اجرا کنید. تولید شناسه و استفاده از آن سه دستور مستقل‌اند؛ مقدار operation را برای تلاش مجدد نگه دارید.

این تابع خودش احراز هویت نمی‌کند؛ فقط کاربر معتبرِ برنامه را به آن بدهید. در درخواست وب، مدل را از `$request->user()` پس از middleware (میان‌افزار) `auth` بگیرید. actor، scope و capabilities را از ورودی خام مرورگر نپذیرید. مثال فقط قلمرو شخصیِ مشتق‌شده از شناسه کاربر دارد؛ برای قلمرو مشترک تیم، نگاشت هویت و عضویت معتبر میزبان لازم است.

### ورودی، خروجی و مجوز

`created`، `read` و `completed` همگی `Karnoweb\TaskManager\DTO\TaskResult` هستند؛ DTO یعنی شیء انتقال داده. ویژگی‌های واقعی: publicKey، displayKey، title، scope، owner، state، version و completedAt. در اجرای تازه، state از pending به done و نسخه Task از ۱ به ۲ می‌رود؛ completedAt از null به زمان UTC (زمان هماهنگ جهانی) تبدیل می‌شود. publicKey شناسه UUID (شناسه یکتای استاندارد) است؛ displayKey مانند TM-1 برای نمایش است.

این زنجیره task.create، task.view و task.edit می‌خواهد؛ خواندن خروجی ساخت و تکمیل نیز به view نیاز دارد. همین سه مجوز صریح به زمینه داده شده‌اند. capabilities صریح، فهرست پیش‌فرض سازنده را **جایگزین** می‌کند. پیش‌فرض انسانی مجوزهای بیشتری مثل اشتراک‌گذاری دارد؛ مثال برای این زنجیره به آن‌ها نیاز ندارد.

`ContextAuthorization` پیش‌فرض فقط مجوزهای زمینه را می‌سنجد؛ کنترل مالک و scope در سرویس برقرار است. اگر سیاست اضافی میزبان دارید، قرارداد `Karnoweb\TaskManager\Contracts\AuthorizationProvider` را با سیاست واقعی متصل کنید؛ این مثال ادعای اتصال به نظام نقش‌های محصول شما ندارد.

### شناسه عملیات و تلاش مجدد

هر فرمان یک operation مستقل می‌خواهد؛ نمونه از یک UUID با پسوندهای create و complete استفاده می‌کند. اجرای دوباره تابع با **همان کاربر، همان operation و همان داده** همان فرمان‌ها را بازپخش می‌کند و Todo دوم نمی‌سازد. برای Todo تازه UUID تازه بسازید. هنگام اصلاح محتوای فرمان یا نسخه پس از تعارض، آن را فرمان جدید بدانید و شناسه تازه بدهید.

correlationId شناسه ردیابی یک اجراست و می‌تواند در تلاش بعدی تازه باشد؛ جانشین operation نیست. پکیج نتیجه را در `tm_command_journal` ثبت و مجوز فعلی را هنگام بازپخش دوباره بررسی می‌کند. operation در هر scope یکتا است؛ حفظ شناسه بدون حفظ ورودی کافی نیست.

### اثر پایگاه داده

ساخت Todo به‌صورت خودکار Inbox کاربر در `tm_lists`، تعریف personal در `tm_workflow_definitions`، Task در `tm_tasks`، نمونه اصلی در `tm_workflow_instances`، جایگاه فعال در `tm_active_process_slots`، کلید نمایشی و شاخص ساختار را فراهم می‌کند. تعریف و Inbox از قبل موجود دوباره ساخته نمی‌شوند. ثبت عملیات، رویداد ممیزی و رویداد قابل پردازش در `tm_command_journal`، `tm_audit_events` و `tm_outbox` انجام می‌شود.

تکمیل، وضعیت نمونه اصلی را done و lifecycle (چرخه حیات) را closed می‌کند، نسخه Task و نمونه اجرا را افزایش می‌دهد، تاریخچه انتقال می‌نویسد و جایگاه فعال اصلی را آزاد می‌کند. Project ساخته نمی‌شود. وجود tm_outbox به معنی ارسال اعلان بیرونی نیست.

### نسخه Task و نمونه گردش‌کار

`$created->version` نسخه **Task** است و به complete داده می‌شود. برای `instances()->transition` باید نسخه **Workflow Instance** (نمونه اجرای گردش‌کار) را از همان نمونه گرفت. این دو شمارنده ممکن است متفاوت باشند؛ برابر بودن در این مثال تضمین عمومی نیست. در [مثال Workflow](../04-advanced/workflow.md) عمداً متفاوت‌اند.

## خطا و اقدام بعدی

خطاهای دامنه از نوع `Karnoweb\TaskManager\Exceptions\DomainError` هستند؛ کد خطا در getMessage و جزئیات در details است. برنامه میزبان باید نگاشت پاسخ HTTP خود را انجام دهد؛ پکیج در این نمونه کنترلر وب فراهم نمی‌کند.

| خطا | علت / رفع |
|---|---|
| InvalidArgumentException در مثال | operation ورودی UUID نیست؛ UUID یک فرمان تازه را بسازید یا شناسه قبلی معتبر را بازیابی کنید. |
| context_invalid | هویت، قلمرو یا شناسه ردیابی خالی یا بیش از ۱۲۰ بایت است؛ نگاشت معتبر میزبان را اصلاح کنید. |
| title_invalid | عنوان خالی یا بیش از ۲۵۵ نویسه؛ ورودی را اصلاح کنید. |
| capability_denied | مجوز زنجیره یا سیاست میزبان کافی نیست؛ مجوز واقعی کاربر را بررسی کنید، نه افزودن بی‌قید مجوزها. |
| not_found | رکورد وجود ندارد یا قابل مشاهده نیست؛ شناسه و کاربر/قلمرو را بررسی کنید؛ به بهانه این خطا محدودیت امنیتی را حذف نکنید. |
| version_conflict | نسخه قدیمی است؛ get، بازبینی تغییر و فرمان تازه با نسخه جدید. |
| idempotency_key_conflict | یک شناسه با محتوای متفاوت استفاده شده؛ برای تلاش مجدد ورودی قبلی، برای قصد جدید شناسه تازه. |
| transition_invalid | تکمیل دوباره با فرمان جدید یا انتقال نامعتبر؛ وضعیت فعلی را بخوانید. |
| workflow_command_required | Task عضو پروژه/گردش‌کار غیرشخصی است؛ از مسیر instances استفاده کنید. |

اعتبارسنجی: مثال personal-todo در [آزمون مستقیم مستندات](../../tests/TechnicalDocumentationExamplesTest.php) با ایجاد، خروجی، اثر جدول‌ها، بازپخش، جدایی کاربران و تعارض نسخه بررسی می‌شود. نتیجه اجرای واقعی در [فهرست راهنما](../README.md#نتیجه-اعتبارسنجی) ثبت شده است.
