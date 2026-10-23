# نصب

قید واقعی Composer: PHP ‎`^8.3` و Illuminate ‎`^13.0`. اجرای ثبت‌شده روی PHP 8.4.22 و Laravel 13.37.0 است؛ سایر ترکیب‌ها در این مأموریت اجرا نشده‌اند. درایور PDO متناسب با پایگاه‌داده لازم است. `ext-intl` برای تاریخ شمسی و `ext-curl` برای وب‌هوک پیش‌فرض لازم‌اند.

## نصب از مخزن محلی

در پروژهٔ Laravel کنار پوشهٔ `task-manager`:

```shell
composer config repositories.task-manager path ../task-manager
composer require karnoweb/task-manager:"*@dev"
php artisan vendor:publish --tag=task-manager-config
php artisan migrate
php artisan migrate:status
```

این مسیر با مخزن محلی واقعی است؛ انتشار بسته در رجیستری عمومی فرض نشده است. در رجیستری سازمانی، دستور معمول `composer require karnoweb/task-manager` را با همان رجیستری اجرا کنید.

Service Provider (ثبت‌کنندهٔ خدمات) خودکار ثبت می‌شود. اگر کشف خودکار غیرفعال است، `Karnoweb\TaskManager\TaskManagerServiceProvider::class` را به `bootstrap/providers.php` اضافه کنید. مهاجرت‌ها مستقیماً از بسته بارگذاری می‌شوند؛ برچسب ساختگی برای انتشار Migration وجود ندارد.

## بررسی نصب

[فرمان کامل نمونه](../examples/Console/TaskManagerExample.php) را در `app/Console/Commands/TaskManagerExample.php` کپی و `php artisan task-manager:example todo` را اجرا کنید. انتظار: کلید عمومی، `state=pending` و `project_id=null`. هسته جدول کاربر یا دپارتمان میزبان لازم ندارد. [اتصال‌های اختیاری](guides/index.md) جدا نصب می‌شوند. آزمون‌های مخزن از `migrate:fresh` استفاده می‌کنند؛ پایگاه‌دادهٔ آزمایش باید مستقل باشد.
