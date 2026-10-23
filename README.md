# Karnoweb Task Manager

موتور مستقل Todo، پروژه و گردش‌کار برای Laravel 13 و PHP ‎^8.3. نصب Kanban، Calendar، CRM یا Ticket Chat برای هسته الزامی نیست.

## نصب و شروع

[نصب](docs/installation.md) را کامل کنید. فایل کامل [فرمان نمونه](examples/Console/TaskManagerExample.php) را در `app/Console/Commands/TaskManagerExample.php` کپی کنید:

```shell
php artisan task-manager:example todo
php artisan task-manager:example workflow
php artisan task-manager:example reminder
```

این فرمان برای محیط توسعه است؛ قابلیت‌های ثابت آن را در درخواست HTTP استفاده نکنید. در برنامهٔ واقعی، هویت، محدوده و قابلیت‌ها باید از سیاست معتبر میزبان ساخته شوند.

Facade (نمای دسترسی ساده): `Karnoweb\TaskManager\Facades\TaskManager`. مدیر قابل تزریق: `Karnoweb\TaskManager\TaskManager`.

## مستندات توسعه‌دهنده

- [شروع سریع](docs/quickstart.md)، [پیکربندی](docs/configuration.md)، [فهرست راهنماها](docs/guides/index.md)
- [مرجع امضاهای واقعی سرویس‌ها](docs/api/reference.md)، [قراردادها و داده‌ها](docs/api/contracts.md)
- [Ticketing](docs/guides/ticketing-integration.md)، [CRM](docs/guides/crm-integration.md)، [Kanban](docs/guides/kanban-integration.md)، [Calendar](docs/guides/calendar-integration.md)
- [معماری](docs/advanced/architecture.md)، [امنیت](docs/advanced/security.md)، [کارگرها](docs/advanced/workers.md)، [کارایی](docs/advanced/performance.md)
- [آزمون](docs/testing.md)، [رفع اشکال](docs/troubleshooting.md)، [ارتقا](docs/upgrade.md)

## وضعیت راستی‌آزمایی

گزارش جاری در [گزارش اصلاحات](docs/maintenance/corrections-report.md) ثبت می‌شود. گزارش‌های فازهای قبلی، شواهد تاریخی همان نسخه‌ها هستند؛ نامزد قدیمی فاز ۷ نتیجهٔ NO-GO (عدم تأیید انتشار) داشت. اصلاحات حاضر به معنی تأیید خودکار تمام ۳۶۲ سناریوی انتشار نیست.

[تغییرات](CHANGELOG.md) · [تحلیل شکاف‌ها](docs/maintenance/gap-analysis.md) · [تطبیق معیارهای تیکتینگ](docs/maintenance/ticketing-acceptance.md) · [گزارش پاک‌سازی](docs/maintenance/cleanup-report.md)
