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

Service Provider (ثبت‌کنندهٔ خدمات) خودکار ثبت می‌شود. اگر کشف خودکار غیرفعال است، `Karnoweb\TaskManager\TaskManagerServiceProvider::class` را به `bootstrap/providers.php` اضافه کنید. مهاجرت‌ها با `loadMigrationsFrom` از `database/migrations` بسته بارگذاری می‌شوند. کپی در پروژه میزبان اختیاری است: [انتشار مایگریشن](#انتشار-مایگریشن).

## انتشار مایگریشن

### هدف

کپی‌کردن فایل‌های `database/migrations` بسته در `database/migrations` میزبان، با همان نام فایل.

### پیش‌نیازها

پکیج نصب شده باشد و `TaskManagerServiceProvider` بوت شده باشد. برچسب فقط وقتی ثبت می‌شود که برنامه در کنسول باشد (`runningInConsole`).

ثبت از `publishes()` است، نه `publishesMigrations()`. حتی اگر `database.migrations.update_date_on_publish` برابر `true` باشد، Laravel تاریخ فایل را عوض نمی‌کند. نام‌ها همان `2026_10_20_000001_create_task_foundation.php` تا `2026_10_31_000030_add_pipeline_stage_administration.php` می‌مانند.

بدون این انتشار هم `php artisan migrate` فایل‌های داخل بسته را اجرا می‌کند. انتشار وقتی لازم است که میزبان بخواهد نسخهٔ خودش از همان فایل را نگه دارد.

### مرحله ۱: انتشار

از ریشهٔ پروژهٔ میزبان:

```shell
php artisan vendor:publish --tag=task-manager-migrations
```

بازنویسی فایل‌های موجود فقط با `--force`:

```shell
php artisan vendor:publish --tag=task-manager-migrations --force
```

### مرحله ۲: بررسی نام فایل

```shell
php artisan vendor:publish --tag=task-manager-migrations --no-interaction
```

انتظار: هر فایل مبدأ در `vendor/karnoweb/task-manager/database/migrations` با همان basename در `database/migrations` میزبان باشد. پیشوند تاریخ روز انتشار ساخته نمی‌شود. فایلی که از قبل وجود دارد بدون `--force` رد می‌شود (`SKIPPED`).

سپس:

```shell
php artisan migrate
php artisan migrate:status
```

`MigrateCommand` اول مسیرهای `loadMigrationsFrom` و بعد `database/migrations` میزبان را می‌خواند و با نام فایل (بدون مسیر) یکی می‌کند. اگر همان نام در هر دو جا باشد، کپی میزبان اجرا می‌شود و ردیف `migrations` یک‌بار ثبت می‌شود.

مایگریشنی که هنوز در میزبان کپی نشده از مسیر بسته اجرا می‌شود. بسته جدول یا ردیف `migrations` را به‌خاطر انتشار پاک نمی‌کند.

### خطاهای محتمل

| خطا | علت | کار |
|---|---|---|
| برچسب `task-manager-migrations` در فهرست نیست | Provider بوت نشده، یا دستور خارج از کنسول است | `Karnoweb\TaskManager\TaskManagerServiceProvider` را ثبت کنید و `php artisan vendor:publish --tag=task-manager-migrations` را از ریشهٔ میزبان اجرا کنید |
| فایل با تاریخ امروز ساخته شده | مسیر با `publishesMigrations()` ثبت شده، یا میزبان فایل را دستی عوض کرده | این بسته آن متد را صدا نمی‌زند. کپی تاریخ‌خورده را حذف کنید و دوباره با همین برچسب منتشر کنید |
| `SQLSTATE` جدول از قبل وجود دارد | همان نام قبلاً migrate شده و فایل منتشرشده دوباره به‌عنوان مهاجرت تازه دیده نمی‌شود؛ یا دو نام متفاوت همان جدول را می‌سازند | `php artisan migrate:status` را ببینید. نام ثبت‌شده را عوض نکنید. تغییر ساختار نصب موجود مهاجرت جدید می‌خواهد، نه ویرایش مهاجرت اجراشده |
| ویرایش کپی میزبان بی‌اثر است | فایل میزبان وجود ندارد، یا نامش با بسته یکی نیست و نسخهٔ بسته برندهٔ کلید نام نیست | basename باید با فایل بسته یکی باشد. وجود فایل را در `database/migrations` میزبان چک کنید |

## بررسی نصب

[فرمان کامل نمونه](../examples/Console/TaskManagerExample.php) را در `app/Console/Commands/TaskManagerExample.php` کپی و `php artisan task-manager:example todo` را اجرا کنید. انتظار: کلید عمومی، `state=pending` و `project_id=null`. هسته جدول کاربر یا دپارتمان میزبان لازم ندارد. [اتصال‌های اختیاری](guides/index.md) جدا نصب می‌شوند. آزمون‌های مخزن از `migrate:fresh` استفاده می‌کنند؛ پایگاه‌دادهٔ آزمایش باید مستقل باشد.
