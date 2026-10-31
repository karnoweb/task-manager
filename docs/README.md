# راهنمای Task Manager

مسیر مطالعه: Todo (کار شخصی)، سپس پروژه و بعد موتور گردش‌کار. شانزده بلوک اجرایی نشانه‌گذاری‌شده در README و راهنماها، با Laravel 13 از خود Markdown استخراج و آزموده می‌شوند؛ مرجع امضاها برنامه اجرایی مستقل نیست.

## استفاده پایه

1. [نصب](installation.md) و [شروع سریع Todo](01-getting-started/quick-start.md)
2. [اتصال User، Branch و Scope؛ ساخت خودکار زمینه](01-getting-started/resolvers.md)
3. [TaskSession، ساخت مستقیم کار پروژه و مسیر انتقال قدیمی](03-basic-usage/tasks-projects.md)
4. [چک‌لیست و یادآور](03-basic-usage/checklists-reminders.md)
5. [تنظیمات و اندازه صفحه](configuration.md)

## استفاده پیشرفته

- [Workflow مستقل، نسخه‌ها و فرمان تجاری](04-advanced/workflow.md)
- [ثبت محافظ سفارشی](04-advanced/custom-guards.md)
- [مسئولیت، تأیید، SLA و واگذاری](04-advanced/team-processes.md)
- [اتصال‌های اختیاری، Ticketing، Kanban، Calendar و CRM](guides/index.md)
- [۱۵ سناریوی اجرایی جامع فارسی](../tests/Scenarios/README.md)

## مرجع توسعه داخلی

- [درگاه‌ها و امضاهای واقعی](api/reference.md) و [DTO و قراردادهای میزبان](api/contracts.md)
- [معماری و مالکیت وضعیت](advanced/architecture.md)
- [ساختار پایگاه داده و نسخه‌ها](advanced/database.md)
- [امنیت و زمینه اجرا](advanced/security.md)
- [تراکنش، رویداد و پردازشگر](advanced/workers.md)
- [کارایی](advanced/performance.md)، [آزمون و رفع خطا](testing.md) و [سازگاری و ارتقا](upgrade.md)
- [وضعیت اصلاح رابط عمومی](API-IMPROVEMENT-PROPOSALS.md)

Facade (درگاه ایستای Laravel) اصلی `Karnoweb\TaskManager\Facades\TaskManager` است. شروع روزمره با tasks()->inContext انجام می‌شود؛ projects()->createTask کار پروژه را مستقیم می‌سازد. زمینه اجرا فقط همان نشست را همراهی می‌کند و در Facade سراسری ذخیره نمی‌شود.

## نتیجه اعتبارسنجی

[شواهد اجرای واقعی و محدودیت‌های محیط](testing.md) و [گزارش پاک‌سازی](SIMPLIFICATION-REPORT-FA.md) مرجع نتیجه جاری‌اند؛ اسناد تاریخی جایگزین‌شده در Git قابل بازیابی‌اند. آزمون TechnicalDocumentationExamplesTest، بلوک‌های نشانه‌گذاری‌شده example را از README و راهنماها می‌خواند و همان کدها را اجرا می‌کند. مثال‌های API (رابط برنامه‌نویسی) آینده به‌عنوان قابلیت موجود عرضه نشده‌اند.
