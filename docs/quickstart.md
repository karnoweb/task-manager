# سه شروع سریع مستقل

ابتدا [نصب](installation.md) را کامل کنید. فایل کامل [TaskManagerExample.php](../examples/Console/TaskManagerExample.php) در مسیر `app/Console/Commands/TaskManagerExample.php` قرار می‌گیرد.

## Todo شخصی

`php artisan task-manager:example todo` کار را می‌سازد، تکمیل و بازمی‌گشاید. خروجی `pending` و `project_id=null` است. Inbox (فهرست پیش‌فرض شخصی) و فرایند داخلی خودکار ساخته می‌شوند؛ پروژه و رابط کاربری اجباری نیستند.

## Workflow با Pipeline و Stage

`php artisan task-manager:example workflow` گردش‌کار، پروژه، کار و مسیر ترتیبی می‌سازد؛ کار را با نگاشت تأییدشده وارد پروژه و مسیر می‌کند و به `review` می‌برد. خروجی شامل `project_key` و `instance_key` است.

## موجودیت خارجی

`SubjectHandler` (قرارداد مجوز و اعتبارسنجی مالک داده) را با نام پایدار مانند `support.ticket` در `SubjectRegistry` ثبت کنید. همهٔ فایل‌های قابل کپی و گردش کامل در [Ticketing](guides/ticketing-integration.md) آمده‌اند. نام کلاس PHP در مرجع داده ذخیره نمی‌شود.

آزمون نمونه‌های پایه: `php vendor/phpunit/phpunit/phpunit tests/DocumentationExamplesTest.php`. نمونهٔ خارجی واقعی در `TicketingIntegrationTest` اجرا می‌شود؛ [تنظیم میزبان آزمایش](testing.md) را ببینید.
