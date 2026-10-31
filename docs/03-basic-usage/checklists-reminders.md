# چک‌لیست و یادآور شخصی

[فهرست](../README.md) · [شروع سریع و هویت معتبر](../01-getting-started/quick-start.md)

Checklist (فهرست بررسی) کار را به اقلام کوچک تقسیم می‌کند؛ تکمیل قلم، Task را خودکار تکمیل نمی‌کند. Reminder (یادآور) ثبت موعد است؛ ارسال واقعی اعلان به فرستنده میزبان و پردازشگر نیاز دارد. تنظیم پیش‌فرض برای ثبت یادآور خود شخص کافی است؛ گیرنده دیگر قرارداد ReminderRecipients را لازم دارد.

## استفاده کوتاه و اتصال کامل

پس از نصب Laravel 13 و ثبت [Gate آموزشی](tasks-projects.md)، فایل خصوصی `storage/app/private/task-manager-docs/checklists-reminders.php` را بسازید. در Tinker (محیط تعاملی Laravel)، فایل را require کنید و تابع برگشتی را با `auth()->user()` معتبر و UUID (شناسه یکتا) ذخیره‌شده همان قصد اجرا کنید. این Gate صرفاً قاعده میزبان نمونه برای قابلیت‌های اضافه است؛ برای محصول از مجوز واقعی کاربر استفاده کنید.

<!-- example: checklist-reminder -->
```php
<?php
namespace App\TaskManagerDocs;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Facades\TaskManager;

return static function (Authenticatable $user, string $operation): array {
    Gate::forUser($user)->authorize('task-manager.docs.configure');
    if (! Str::isUuid($operation)) {
        throw new \InvalidArgumentException('operation must be a UUID');
    }
    $actor = (string) $user->getAuthIdentifier();
    $context = new ExecutionContext('personal:'.$actor, $actor, (string) Str::uuid(), capabilities: [
        'task.create', 'task.view', 'task.edit', 'checklist.manage', 'reminder.manage',
    ]);
    $task = TaskManager::tasks()->inContext($context)->create('آماده‌سازی جلسه', $operation.':task');
    $list = TaskManager::checklists()->create($context, $task->publicKey, 'پیش از جلسه', $operation.':list');
    $item = TaskManager::checklists()->addItem($context, $list['public_key'], $list['version'], 'آماده‌سازی گزارش', null, null, $operation.':item');
    $checked = TaskManager::checklists()->changeItem($context, $item['public_key'], $item['version'], ['done' => true], $operation.':check');
    $when = TaskManager::reminders()->parseTime('2090-01-01 10:00:00', 'Asia/Tehran');
    $reminder = TaskManager::reminders()->createGeneral($context, 'یادآوری جلسه', $when, 'Asia/Tehran', $operation.':reminder');

    return compact('task', 'list', 'item', 'checked', 'reminder');
};
```

کوتاه‌ترین استفاده، فراخوان create روی checklists و createGeneral روی reminders با زمینه آماده است؛ باقی کد فایل بالا هویت، مجوز و داده نمونه را به‌صورت کامل آماده می‌کند. خروجی task از نوع TaskResult (شیء نتیجه نوع‌دار) و سایر خروجی‌ها آرایه‌اند. reminder.subject_type و task_id تهی‌اند؛ این یادآور شخصی مستقل از Task است. زمان آینده ثابت است تا ورودی تلاش مجدد تغییر نکند؛ در محصول، زمان انتخاب‌شده همان فرمان را نگه دارید و از محاسبه دوباره «اکنون + یک ساعت» در تلاش مجدد پرهیز کنید.

در tm_checklists و tm_checklist_items رکورد ایجاد و نسخه قلم هنگام check افزایش می‌یابد. نسخه فهرست بعد از addItem نیز تغییر می‌کند؛ برای افزودن قلم بعدی فهرست را دوباره بخوانید، نسخه قلم را جای نسخه فهرست ندهید. Task شخصی pending باقی می‌ماند. در tm_reminders موعد UTC (زمان هماهنگ جهانی) همراه منطقه زمانی و گیرنده ثبت می‌شود. عملیات در دفتر فرمان و رویدادهای ممیزی ثبت می‌شوند؛ وجود reminder به معنی تحویل پیام نیست.

خطاهای واقعی: checklist_title_invalid برای عنوان، version_conflict برای نسخه قدیمی، assignee_ineligible برای مسئول نامعتبر، reminder_in_past برای موعد گذشته، reminder_recipient_denied برای گیرنده غیرمجاز و not_found/capability_denied برای دسترسی. ابتدا ورودی یا مجوز واقعی را اصلاح کنید؛ پس از تعارض، داده را بازبینی و فرمان تازه بسازید. روش [پردازش یادآور و اعلان](../advanced/workers.md) باید با زمینه serviceActor (هویت پردازشگر) و مجوزهای صریح گیرنده اجرا شود.

تکرار زمان‌بندی‌شده Task مسیر جداگانه recurrences است: create روی Task مرجع، preview برای محدوده و generate با نسخه قانون و شناسه عملیات. [SchedulingCoreTest](../../tests/SchedulingCoreTest.php) و [RecurrenceHistoryTest](../../tests/RecurrenceHistoryTest.php) ورودی قواعد، محدوده، استثناها و تاریخچه واقعی را اجرا می‌کنند؛ تقویم شمسی به افزونه intl نیاز دارد. این صفحه به‌عمد یادآور مستقل را با تکرار Task یکی نمی‌گیرد.

بلوک بالا مستقیماً در [TechnicalDocumentationExamplesTest](../../tests/TechnicalDocumentationExamplesTest.php) اجرا می‌شود؛ [CollaborationCoreTest](../../tests/CollaborationCoreTest.php) و [ReminderCoreTest](../../tests/ReminderCoreTest.php) مسیرهای خطا و ارسال موعد را پوشش می‌دهند.
