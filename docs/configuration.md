# پیکربندی واقعی

منبع و فایل منتشرشده: `config/task-manager.php`. پس از ویرایش، `php artisan config:clear` اجرا کنید.

| گزینه | نوع / پیش‌فرض | اثر واقعی |
|---|---|---|
| `page_size` | integer / `30` | اعلام‌شده؛ سرویس‌های فعلی limit را صریح می‌گیرند و این مقدار را خودکار اعمال نمی‌کنند |
| `max_page_size` | integer / `100` | اعلام‌شده؛ سقف واقعی در هر سرویس اعتبارسنجی می‌شود |
| `export_directory` | string یا null / null | null یعنی `storage/app/private/task-manager-exports`؛ مسیر عمومی وب نباشد |
| `export_max_bytes` | integer / `104857600` | سقف فایل در ذخیره‌ساز محلی خروجی داده |
| `webhook_allowed_hosts` | list<string> / `[]` | دامنه‌های مجاز؛ HTTPS و بررسی DNS/IP خصوصی همچنان اجباری است |
| `notification_channels` | list<string> / `['in_app']` | کانال مجاز سیاست پیش‌فرض؛ نصب درایور ارسال را انجام نمی‌دهد |
| `quiet_hours.timezone` | string / `UTC` | منطقهٔ IANA برای ساعات سکوت |
| `quiet_hours.start` | string یا null / null | شروع مانند `22:00`؛ همراه end تنظیم شود |
| `quiet_hours.end` | string یا null / null | پایان مانند `07:00`؛ موعد ارسال تا زمان مجاز عقب می‌افتد |

مثال: مقدار quiet_hours را به `['timezone' => 'Asia/Tehran', 'start' => '22:00', 'end' => '07:00']` تغییر دهید. زمان UTC ذخیره‌شده عوض نمی‌شود.

Providerهای `AuthorizationProvider`، `SubjectRegistry`، `EntryRoutingProvider`، `ReminderRecipients` و `NotificationTransport` از Container (تزریق وابستگی Laravel) متصل می‌شوند؛ گزینهٔ تنظیم ساختگی ندارند. [Provider کامل Ticketing](../examples/Ticketing/TaskManagerTicketingProvider.php) نمونهٔ اجرایی است.
