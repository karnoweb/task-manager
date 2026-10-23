# راهنمای فنی Task Manager

از Todo (کار شخصی) شروع کنید، سپس پروژه و در پایان گردش‌کار را بیاموزید. این راهنما براساس سورس فعلی پکیج و Laravel 13 نوشته شده است.

## مسیر مطالعه

1. [نصب و شروع سریع Todo](01-getting-started/quick-start.md): ساخت زمینه اجرا، شناسه عملیات، ایجاد، خواندن و تکمیل.
2. [مدیریت Task و Project](03-basic-usage/tasks-projects.md): ویرایش، بازگشایی، فهرست و انتقال کار به پروژه.
3. [گردش‌کار مستقل و تغییر وضعیت](04-advanced/workflow.md): تعریف نسخه‌دار، نمونه اجرا و فرمان تجاری.

## انتخاب درگاه

Facade (درگاه ایستای Laravel) اصلی `Karnoweb\TaskManager\Facades\TaskManager` است. `tasks()`، `projects()`، `workflows()` و `instances()` سرویس‌های موجود را برمی‌گردانند. مسیر آغازین `tasks()->inContext(...)` است؛ سرویس‌ها و مسیرهای قدیمی حذف نشده‌اند.

| نیاز | درگاه واقعی | خروجی اصلی |
|---|---|---|
| Todo | tasks → inContext → create/get/complete | TaskResult، شیء داده نوع‌دار |
| ویرایش و فهرست کار | tasks → change/findTasks | TaskResult یا فهرست آن |
| پروژه | projects → create/previewTaskMove/moveTask | آرایه |
| تعریف گردش‌کار | workflows → publish/revision | آرایه نسخه تعریف |
| اجرای گردش‌کار | instances → start/transition/executeBusinessCommand | آرایه نمونه اجرا |

`ExecutionContext` (زمینه اجرا) قلمرو داده، هویت، مجوزها و شناسه ردیابی را حمل می‌کند. آن را از هویت معتبر میزبان بسازید. شناسه عمومی Task با کلید نمایشی و شناسه اجرای گردش‌کار متفاوت است؛ نسخه هر رکورد نیز به همان رکورد تعلق دارد.

## اجرای مثال‌ها

هر بلوک PHP دارای شناسه `example` یک فایل کامل نمونه است؛ Namespace (فضای نام) و use (واردکردن کلاس) داخل آن آمده‌اند. تابع بی‌نامِ برگشتی، صرفاً بسته‌بندی مثال در برنامه میزبان است و متد جدید پکیج محسوب نمی‌شود. آزمون [TechnicalDocumentationExamplesTest](../tests/TechnicalDocumentationExamplesTest.php) همین بلوک‌ها را از Markdown می‌خواند و اجرا می‌کند، نه کپی جداگانه آن‌ها را.

در میزبان، نمونه‌ها را در مسیر خصوصی توضیح‌داده‌شده ذخیره کنید و از Tinker (محیط تعاملی Laravel) در محیط توسعه اجرا کنید؛ ورودی کاربر باید مدل احرازهویت‌شده خود برنامه باشد. فرمان اجرای آزمون‌های این راهنما در ریشه مخزن پکیج: `php vendor/phpunit/phpunit/phpunit --filter TechnicalDocumentationExamplesTest --do-not-cache-result`.

## تنظیمات و منابع تکمیلی

برای مثال‌های پایه انتشار تنظیمات یا اتصال سفارشی لازم نیست؛ جدول‌های پکیج و اتصال پایگاه داده لازم‌اند. مسیر مستقلِ نمونه Workflow نیز از handler (رابط اتصال موجودیت) بومی task استفاده می‌کند. ارسال اعلان، فایل، Webhook (ارسال رویداد HTTP) و موجودیت خارجی وابستگی‌های جدا دارند و شرط شروع این آموزش نیستند.

- [تنظیمات فعلی](configuration.md): به‌ویژه بی‌اثر بودن فعلی page_size/max_page_size در پیش‌فرض سرویس‌ها.
- [گزارش مرحله دوم](../dev/TaskManager-Stage-2-DX-Review-FA.md) و [فهرست کامل امضاهای عمومی](../dev/TaskManager-Public-Surface-Audit-FA.md).
- [پیشنهادهای اصلاح تجربه استفاده](API-IMPROVEMENT-PROPOSALS.md): پیشنهاد آینده؛ هیچ‌یک API (رابط برنامه‌نویسی) قابل فراخوانی جدید نیست.

## نتیجه اعتبارسنجی

تاریخ اجرا: ۲۰۲۶-۱۰-۲۲. محیط واقعی: Laravel **13.37.0**، Orchestra Testbench **11.3.0** (میزبان آزمون Laravel)، PHP **8.4.22**، PHPUnit **12.5.40** (اجراکننده آزمون) و SQLite در حافظه.

**نتیجه موفق: ۴۴ آزمون، ۷۰۷ بررسی.** از این مجموعه، ۱۴ آزمون جدید در TechnicalDocumentationExamplesTest هستند؛ ۳۰ آزمون قبلی حذف یا تغییر نشده‌اند. فرمان اجرا در ریشه پکیج:

```bash
php vendor/phpunit/phpunit/phpunit --filter 'TechnicalDocumentationExamplesTest|PersonalApiTest|DocumentationExamplesTest|WorkflowCoreTest|TicketWorkflowPolicyTest|EntryRoutingTest|GeneralReminderTest' --do-not-cache-result
```

پنج بلوک PHP دقیقاً از متن مستندات اجرا شدند:

| شناسه مثال | چیزی که واقعاً بررسی شد |
|---|---|
| personal-todo | ساخت/خواندن/تکمیل، جدول‌ها، خروجی، بازپخش، تعارض نسخه و جدایی مالک/قلمرو |
| manage-tasks | ویرایش، تکمیل، بازگشایی، آرشیو، بازیابی، فهرست و نسخه‌های ۲ تا ۶ |
| configure-permission | اجازه کاربر ۱ و رد کاربر دیگر پیش از نوشتن |
| create-project-task | انتشار، پروژه، انتقال همان Task و main Instance، بازپخش با preview اولیه و پیش‌نمایش قدیمی |
| independent-workflow | دو slot، انتقال عادی، فرمان تجاری، مجوز و دلیل، نسخه Task متفاوت از Instance و بازپخش |

مسیر تکمیل نمونه main پروژه پس از ویرایش فقط Task نیز با آزمون واقعی تأیید شد. لینک‌های فایل و بخش‌های مقصد در هر پنج سند تازه، در همان مجموعه بررسی شدند. شناسه‌های نامعتبر عملیات و استفاده اشتباه از نسخه Task در فرمان Instance نیز آزمون شکست دارند.

**حدود اعتبارسنجی:** مثال‌ها در برنامه Laravel 13 راه‌اندازی‌شده توسط Testbench اجرا شده‌اند؛ نصب تازه از مخزن Composer، ورود به برنامه واقعی شما از Tinker، همه تست‌های پکیج، MySQL/PostgreSQL، اتصال Ticket Chat و ارسال اعلان بیرونی در این اجرا آزموده نشده‌اند. دستورات نصب، پیش‌نیاز میزبان را توضیح می‌دهند؛ این گزارش ادعای اجرای نصب شبکه‌ای ندارد. نمونه‌های مسیر موفق بالا شکست باز ندارند.
