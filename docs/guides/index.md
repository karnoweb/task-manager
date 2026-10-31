# اتصال‌های اختیاری

[فهرست اصلی](../README.md)

هسته به هیچ‌یک از رابط‌های نمایش یا سامانه‌های تجاری نیاز ندارد. برای Kanban (نمای برد) و Calendar (نمای تقویم)، `integrations()->board` و `integrations()->calendar` با Surface (منابع مجاز سرور)، ContextBridge (دریافت زمینه معتبر میزبان) و BoundCursor (نشانگر صفحه مقید به زمینه) ساخته می‌شوند؛ مرورگر فقط می‌تواند منابع مجاز را محدودتر کند.

- [Ticketing واقعی](ticketing-integration.md): تمام فایل‌های رابط میزبان موجود و پیش‌نیاز اجرا.
- [معماری و مالکیت وضعیت](../advanced/architecture.md): داده تجاری نزد میزبان، Instance صاحب وضعیت فرآیند.
- [UiIntegrationTest](../../tests/UiIntegrationTest.php)، [UiProcessSafetyTest](../../tests/UiProcessSafetyTest.php) و [HostUiCompositionTest](../../tests/HostUiCompositionTest.php): نمونه‌های کامل نصب، زمینه، fetch، حرکت، فیلتر و جلوگیری از جعل ورودی.

نبود پکیج مقصد برای board/calendar خطای kanban_package_required/calendar_package_required می‌دهد؛ CRM بدون کلاس واقعی و HostAccess میزبان خطای crm_host_binding_required دارد. این قراردادها گزینه خاموش/روشن هسته نیستند. [پیش‌نیاز محیط آزمایش](../testing.md) و [آزمون استقلال نصب](../../tests/PhaseSixInstallationTest.php) مرجع‌اند؛ محیط غایب تأییدشده گزارش نمی‌شود.

## نوع شناسه در پرس‌وجوی مجوز میزبان

قرارداد Subject (موجودیت مرتبط) شناسه رشته‌ای دارد؛ کلید اصلی مدل Laravel می‌تواند عددی باشد. اگر جدول اختیار میزبان شناسه را رشته‌ای ذخیره می‌کند، مقایسه مستقیم دو ستون رشته‌ای و عددی با whereColumn در PostgreSQL خطای نوع می‌دهد. نوع‌های دو طرف باید در SQL (زبان پرس‌وجوی پایگاه داده) به‌طور صریح سازگار شوند و فیلتر قلمرو، نوع موجودیت، هویت و اختیار ویرایش حفظ شود.

[اتصال نمونه واقعی CRM](../../tests/RealHostE2ETestCase.php) شناسه عددی مدل را به متن تبدیل می‌کند؛ نام جدول از دو مقدار ثابت داخلی انتخاب می‌شود و ورودی کاربر نام جدول یا عبارت SQL را تعیین نمی‌کند. [هشت سناریوی میزبان](../../tests/RealHostE2ETest.php) نتیجه و جلوگیری از دسترسی ناخواسته را می‌سنجند. [نتایج واقعی اجرای جاری](../testing.md) فقط SQLite را تأیید می‌کند.
