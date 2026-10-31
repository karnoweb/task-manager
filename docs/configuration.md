# پیکربندی واقعی

منبع و فایل منتشرشده: `config/task-manager.php`. پس از ویرایش، `php artisan config:clear` اجرا کنید.

هویت معتبر و زمینه خودکار: [راهنمای کامل Resolverها](01-getting-started/resolvers.md). `user.model` و `branch.model` محدودیت نوع اختیاری‌اند؛ `user.identifier`/`branch.identifier` مسیر ویژگی شناسه یا null هستند. `user.resolver`، `branch.resolver` و `scope.resolver` نام کلاس قابل تزریق یا قرارداد متصل به Container (سامانه تزریق وابستگی) می‌گیرند. ثبت Container تقدم دارد. نام کلاس در config قابل کش است؛ Closure تنها مسیر اتصال نیست.

`branch.enabled=false` پیش‌فرض است؛ `branch.default_id=null` بدون Resolver شعبه‌ای تحمیل نمی‌کند. `scope.default='default'` فقط با شعبه غیرفعال کاربرد دارد؛ با شعبه فعال قلمرو branch:<شناسه> اجباری است. برای اطلاعات قبلی، مقدار قبلی Scope را حفظ کنید. `context.capabilities` توانایی‌های پایه Todo را دارد؛ `context.capabilities_resolver` نام کلاس invokable با خروجی list<string> برای مجوزهای سمت سرور است. ورودی عمومی درخواست جای آن نیست. `AuthorizationProvider` در همه سرویس‌ها همچنان لازم‌الاجراست.

| گزینه | نوع / پیش‌فرض | اثر واقعی |
|---|---|---|
| `page_size` | integer / `30` | پیش‌فرض query کار، Instance و یادآور و queries()->tasks وقتی limit و آرگومان‌های پس از آن حذف شده‌اند |
| `max_page_size` | integer / `100` | سقف همین چهار مسیر؛ عدد صحیح بین ۱ تا ۱۰۰؛ سقف‌های worker و سرویس‌های دیگر مستقل‌اند |
| `export_directory` | string یا null / null | null یعنی `storage/app/private/task-manager-exports`؛ مسیر عمومی وب نباشد |
| `export_max_bytes` | integer / `104857600` | سقف فایل در ذخیره‌ساز محلی خروجی داده |
| `webhook_allowed_hosts` | list<string> / `[]` | دامنه‌های مجاز؛ HTTPS و بررسی DNS/IP خصوصی همچنان اجباری است |
| `notification_channels` | list<string> / `['in_app']` | کانال مجاز سیاست پیش‌فرض؛ نصب درایور ارسال را انجام نمی‌دهد |
| `quiet_hours.timezone` | string / `UTC` | منطقهٔ IANA برای ساعات سکوت |
| `quiet_hours.start` | string یا null / null | شروع مانند `22:00`؛ همراه end تنظیم شود |
| `quiet_hours.end` | string یا null / null | پایان مانند `07:00`؛ موعد ارسال تا زمان مجاز عقب می‌افتد |

مثال: مقدار quiet_hours را به `['timezone' => 'Asia/Tehran', 'start' => '22:00', 'end' => '07:00']` تغییر دهید. زمان UTC ذخیره‌شده عوض نمی‌شود.

Providerهای `AuthorizationProvider`، `SubjectRegistry`، `EntryRoutingProvider`، `ReminderRecipients` و `NotificationTransport` از Container (تزریق وابستگی Laravel) متصل می‌شوند؛ گزینهٔ تنظیم ساختگی ندارند. [Provider کامل Ticketing](../examples/Ticketing/TaskManagerTicketingProvider.php) نمونهٔ اجرایی است.

## صفحه‌بندی و سازگاری

`tasks()->query(context)` و `tasks()->inContext(context)->query()` مقدار page_size را می‌گیرند. مقدار صریح limit حفظ می‌شود و سقف max_page_size را رعایت می‌کند. مقدار پیش‌فرض ۳۰ و سقف ۱۰۰ مانند قبل است؛ max خارج ۱..۱۰۰ یا default نامعتبر، pagination_configuration_invalid می‌دهد. page_size فقط هنگام انتخاب پیش‌فرض بررسی می‌شود.

برای حفظ امضاهای قدیمی، در Instance/Reminder/QueryService اگر آرگومان نام‌دار پس از limit را بدهید ولی limit را حذف کنید، PHP جای خالی را با پیش‌فرض امضا (۳۰) پر می‌کند؛ این حالت مثل limit صریح ۳۰ است. برای ادامه صفحه با اندازه سفارشی، limit را هم صریح بفرستید. TaskQuery نیز limit صریح خودش را حفظ می‌کند و پیش‌فرض DTO به config منتقل نشده است. این رفتار قابل اتکا از تغییر ناسازگار امضا جلوگیری می‌کند.

پیشوند جداول نصب تازه با table_prefix (پیش‌فرض tm_) انتخاب می‌شود. قواعد ورودی، منع prefix هم‌زمان اتصال و انتقال نصب موجود در [پایگاه داده](advanced/database.md) توضیح داده شده‌اند.
