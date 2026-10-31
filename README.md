# Karnoweb Task Manager

پکیج مستقل Laravel برای کار شخصی، پروژه و موتور گردش‌کار. Todo به Project، Pipeline، Kanban، Calendar یا CRM نیاز ندارد.

## نصب

وابستگی پکیج: PHP `^8.3` و Illuminate `^13.0`؛ الزامات Laravel 13 میزبان نیز برقرار باشند. در رجیستری تنظیم‌شده سازمان:

```bash
composer require karnoweb/task-manager
php artisan migrate
```

برای مخزن محلی و تنظیمات، [راهنمای نصب](docs/installation.md) را ببینید. انتشار config برای شروع لازم نیست.

## نخستین Todo

پس از ثبت UserResolver (حل‌کننده هویت) در میزبان طبق [راهنمای اتصال](docs/01-getting-started/resolvers.md):

<!-- example: readme-todo -->
```php
<?php
use Illuminate\Support\Str;
use Karnoweb\TaskManager\Facades\TaskManager;

$operation = (string) Str::uuid(); // برای تلاش مجدد همین فرمان، همین مقدار را نگه دارید.

return TaskManager::tasks()->inContext()->create('تماس با مشتری', $operation);
```

خروجی TaskResult (شیء داده نتیجه) شامل publicKey، state و version است. هویت فقط از حل‌کننده ثبت‌شده میزبان خوانده می‌شود؛ نبود حل‌کننده یا کاربر خطا دارد. با شعبه غیرفعال، Scope (قلمرو جداسازی) پیش‌فرض default است؛ با شعبه فعال branch:<شناسه> اعمال می‌شود؛ برای داده‌های موجود نگاشت قبلی را در [تنظیم Resolverها](docs/01-getting-started/resolvers.md) حفظ کنید. [شروع سریع و مثال کامل](docs/01-getting-started/quick-start.md).

## راهنما

- [مسیر مطالعه پایه، پیشرفته و داخلی](docs/README.md)
- [ساخت مستقیم Task پروژه](docs/03-basic-usage/tasks-projects.md)
- [گردش‌کار مستقل](docs/04-advanced/workflow.md) و [محافظ سفارشی](docs/04-advanced/custom-guards.md)
- [آزمون، محدودیت محیط و رفع خطا](docs/testing.md)
- [۱۵ سناریوی اجرایی جامع فارسی](tests/Scenarios/README.md)

آزمون در ریشه مخزن پکیج: `php vendor/phpunit/phpunit/phpunit --do-not-cache-result`. پایگاه آزمایش باید اختصاصی باشد؛ آزمون‌ها ساختار آن را بازسازی می‌کنند.

پیشوند جداول نصب تازه با `task-manager.table_prefix` تنظیم می‌شود؛ پیش‌فرض `tm_` است. [راهنمای پایگاه داده](docs/advanced/database.md).
