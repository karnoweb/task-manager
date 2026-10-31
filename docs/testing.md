# آزمون، محیط و نتیجه واقعی

این مأموریت در ۲۰۲۶-۱۰-۲۴ فقط SQLite را اجرا می‌کند. PostgreSQL، MySQL و MariaDB همگی **Not Verified (تأییدنشده در این مأموریت)** هستند؛ تست‌هایشان حفظ شده‌اند. ملاحظه کارایی PostgreSQL قبلی در [کارایی](advanced/performance.md) باز است.

## اجرای تکرارپذیر

پیش‌نیاز: وابستگی‌های composer.lock، PHP 8.4.22، Laravel 13 و PHPUnit 12 در محیط اجرای ثبت‌شده؛ آزمون پکیج به پایگاه اختصاصی نیاز دارد. migrate:fresh داده آزمون را بازسازی می‌کند. نمونه زیر از ریشه مخزن در PowerShell اجرا می‌شود:

```powershell
$env:TM_TEST_DRIVER='sqlite'
php vendor/phpunit/phpunit/phpunit --log-junit .runtime/simplification/full-sqlite.xml
php vendor/phpunit/phpunit/phpunit tests/Scenarios/Complete
php vendor/phpunit/phpunit/phpunit --filter 'TechnicalDocumentationExamplesTest|DocumentationLinksTest|GuidedScenarioCatalogTest|ContextFactoryTest'
php vendor/phpstan/phpstan/phpstan analyse --memory-limit=1G
php vendor/laravel/pint/builds/pint --test
python tools/verify_package.py --junit .runtime/simplification/full-sqlite.xml
```

پیش از log-junit پوشه `.runtime/simplification` را بسازید. برای اجرای واقعی Ticket/CRM/دپارتمان و نمایش‌های اختیاری، میزبان واقعی لازم است؛ ابزارهای `tools/prepare_phase6_hosts.py` و `tools/prepare_ticketing_host.py` نمونه‌های Composer محلی را از بسته‌های موجود در پوشه همسایه آماده می‌کنند؛ پس از آماده‌سازی Composer را در میزبان‌ها اجرا کنید. این ابزارها خود بسته همسایه را تغییر نمی‌دهند. نسخه‌ها از فایل قفل همان نمونه می‌آیند؛ نبود نمونه به معنی تأیید اتصال نیست.

در محیط این مأموریت هر دو نمونه آماده بودند؛ پیش از اجرای مجموعه کامل و سناریوها این مقادیر تنظیم شدند:

```powershell
$env:TM_PHASE6_HOST_AUTOLOAD=(Join-Path $PWD '.runtime/phase6-hosts/all/vendor/autoload.php')
$env:TM_TICKETING_HOST_AUTOLOAD=(Join-Path $PWD '.runtime/ticketing-host/vendor/autoload.php')
```

این مسیرها فقط زیرساخت آزمون‌اند و وابستگی اجباری نصب هسته نیستند. نبود آن‌ها آزمون‌های مربوط را صریحاً skip (اجرانشده) می‌کند. هیچ سرویس ساختگی برای اعلام موفقیت اتصال واقعی جایگزین نشده است. خروجی‌ها فقط زیر `.runtime` هستند؛ شش ابزار قدیمی gate/freeze/generate فازها با verify_package.py و catalog نگه‌داری‌شده جایگزین شدند و فایل تاریخی در docs بازتولید نمی‌شود.

## نتایج مأموریت شعبه و پیشوند ـ ۲۰۲۶-۱۰-۲۴

| مجموعه | نتیجه |
|---|---|
| اجرای پایه SQLite | ۶۵۱ تست، ۸۹۰۲ بررسی، ۹۲ اجرا‌نشده؛ بدون شکست |
| اجرای کامل میانی | ۶۶۲ تست، ۹۰۰۱ بررسی، ۹۲ اجرا‌نشده؛ بدون شکست |
| شعبه، پیشوند، هویت، مستندات و لینک‌ها | ۴۴ تست، ۲۴۹۰ بررسی؛ بدون شکست |
| سناریوهای نهایی شعبه، پیشوند و ساختار | ۱۶ تست، ۱۲۸ بررسی؛ بدون شکست |
| ساختار SQLite | ۱۱۴ جدول: ۶۳ قلمرو مستقیم و ۵۱ وابسته به رابطه؛ بدون جدول جدا از مرز قلمرو |
| تحلیل ایستا | PHPStan سطح ۳، بدون خطا |
| اجرای کامل نهایی با میزبان‌های نمونه | ۶۶۹ تست؛ ۶۴۴ اجراشده و ۲۵ اجرا‌نشده؛ ۹۶۰۸ بررسی؛ صفر خطا و شکست؛ ۴۶۸٫۲۲۶ ثانیه |
| سبک کد کل پروژه | Pint --test موفق |
| ممیزی هسته | بدون Auth/Request مستقیم و بدون نام ثابت حل‌نشده جداول |
| لینک‌ها پس از تکمیل گزارش‌ها | ۷۷۰ پیوند محلی بدون خرابی؛ آزمون لینک‌ها: یک تست و ۷۸۳ بررسی موفق |

شمار بررسی‌های اجراهای هدفمند با اجرای کامل جمع نمی‌شود. ۹۲ مورد اجرا‌نشده در اجرای پایه و میانی شامل میزبان‌های نمونه غیرفعال نیز بود. اجرای نهایی مسیر دو autoload میزبان موجود را صریح تنظیم کرد؛ محدودیت‌های مختص موتور سرور با SQLite تأیید نمی‌شوند.

فهرست ساختار و مسیرهای امنیتی در [ممیزی قلمرو](../dev/TENANCY-AUDIT-FA.md) آمده است. هیچ آزمون امنیتی برای عبور مجموعه حذف یا skip نشده است. فایل نهایی JUnit در `.runtime/tenancy-complete.xml` و گزارش ابزارها زیر `.runtime/tenancy-*` هستند. ۲۵ مورد اجرا‌نشده به رفتار قفل هم‌زمان، زمان ثبت قطعی PostgreSQL و قواعد مقایسه رشته MariaDB وابسته‌اند؛ جزئیات هر مورد در JUnit ثبت شده است. PostgreSQL، MySQL و MariaDB همچنان Not Verified هستند.

## چه چیزی بررسی می‌شود

TechnicalDocumentationExamplesTest هفده بلوک PHP را از Markdown واقعی می‌خواند؛ ساخت Todo، پروژه، نشست تغییرات، Workflow، محافظ سفارشی، یادآور و اتصال هویت/شعبه/قلمرو اجرا می‌شوند. مثال ناموفق به عنوان نمونه عملی منتشر نمی‌شود. DocumentationLinksTest همه لینک‌های نسبی جاری را همراه fragment (نشانه عنوان) بررسی می‌کند. GuidedScenarioCatalogTest، با حفظ نام قدیمی کلاس آزمون، ۱۵ جریان مستقل جدید، متدهای تست‌های مرجع قبلی و وجود هر ۲۳۶ تست TM را می‌سنجد.

[فهرست سناریوها](../tests/Scenarios/README.md) و [نگاشت همه تست‌های قدیمی](../tests/Scenarios/COVERAGE-MAPPING.md) جایگزین راهنماهای پیوندی و شرح‌های تکراری شدند. تست‌های تخصصی واحد، امنیت، بازگشت خطا، Rollback (بازگشت تراکنش) و رقابت حذف نشده‌اند.

## خطا و رفع آن

- context_user_missing: احراز هویت معتبر یا Context (زمینه اجرا) صریح برای Job لازم است؛ کاربر غایب مدیر تلقی نمی‌شود.
- context_branch_denied: عضویت شعبه را در Resolver میزبان بررسی کنید؛ عوض‌کردن پیش‌فرض مجوز ایجاد نمی‌کند.
- capability_denied: فهرست توانایی زمینه کافی نیست؛ مجوز محدود و معتبر از سرور لازم است؛ AuthorizationProvider نیز کنترل مستقل دارد.
- not_found: نبود رکورد و نبود دسترسی عمداً یکسان‌اند؛ Scope، مالک، عضویت و محدودیت پرس‌وجوی میزبان را بررسی کنید.
- version_conflict: نسخه مرتبط Task یا Instance را دوباره بخوانید و قصد تغییر را بازبینی کنید؛ همه نسخه‌ها یکی نیستند.
- idempotency_key_conflict: شناسه عملیات قبلاً با ورودی متفاوت استفاده شده؛ retry همان فرمان باید همان شناسه و همان ورودی را نگه دارد.
- guard_unregistered: محافظ نام‌دار را پیش از ساخت سرویس در Container ثبت کنید؛ guard_veto به معنی رد شرط تجاری است.
- نبود پکیج اختیاری یا extension (افزونه): همان قابلیت و تست وابسته تأییدنشده است؛ نیازمندی واقعی را نصب کنید.

گزارش ساختاریافته JUnit و کنسول در `.runtime/simplification` و خلاصه verifier در `.runtime/test-results/package-verification.json` می‌مانند. [گزارش تغییرات و بازیابی Git](SIMPLIFICATION-REPORT-FA.md).
