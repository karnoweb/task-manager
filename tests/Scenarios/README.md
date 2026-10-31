# سناریوهای اجرایی Task Manager

۱۵ فایل PHP مستقل با ۱۶ جریان آزمون؛ هر فایل واقعاً سرویس‌ها را فراخوانی و نتیجه داده را بررسی می‌کند. ۲۳۶ تست TM و آزمون‌های واحد، امنیت و سرور حفظ شده‌اند. [نگاشت کامل](COVERAGE-MAPPING.md) و [محیط/نتیجه اجرا](../../docs/testing.md).

| سناریو | جریان | تست |
|---|---|---|
| [SC-01](Complete/SC-01.md) | چرخه کامل Todo | [PHP](Complete/Scenario01TodoTest.php) |
| [SC-02](Complete/SC-02.md) | Todo، چک‌لیست، یادآور و تکرار | [PHP](Complete/Scenario02ChecklistReminderTest.php) |
| [SC-03](Complete/SC-03.md) | ساخت مستقیم Task پروژه | [PHP](Complete/Scenario03ProjectTest.php) |
| [SC-04](Complete/SC-04.md) | درخت کار و وابستگی | [PHP](Complete/Scenario04HierarchyTest.php) |
| [SC-05](Complete/SC-05.md) | اسپرینت، برنامه‌ریزی و زمان | [PHP](Complete/Scenario05SprintTimeTest.php) |
| [SC-06](Complete/SC-06.md) | گردش‌کار ترتیبی، آزاد و سیاست میزبان | [PHP](Complete/Scenario06WorkflowPolicyTest.php) |
| [SC-07](Complete/SC-07.md) | بازگشت مرحله و تغییر Pipeline | [PHP](Complete/Scenario07PipelineTest.php) |
| [SC-08](Complete/SC-08.md) | مسئولیت و رأی تأیید | [PHP](Complete/Scenario08AssignmentApprovalTest.php) |
| [SC-09](Complete/SC-09.md) | مهلت خدمت و خودکارسازی | [PHP](Complete/Scenario09SlaAutomationTest.php) |
| [SC-10](Complete/SC-10.md) | Ticket واقعی از ورود تا خاتمه | [PHP](Complete/Scenario10TicketTest.php) |
| [SC-11](Complete/SC-11.md) | اتصال واقعی CRM و انتقال دپارتمان | [PHP](Complete/Scenario11DepartmentCrmTest.php) |
| [SC-12](Complete/SC-12.md) | واگذاری و پرونده فرایند | [PHP](Complete/Scenario12HandoffCaseTest.php) |
| [SC-13](Complete/SC-13.md) | پایان کمپین صدعضوی | [PHP](Complete/Scenario13CampaignTest.php) |
| [SC-14](Complete/SC-14.md) | هویت، شعبه و جداسازی داده | [PHP](Complete/Scenario14IdentityScopeTest.php) |
| [SC-15](Complete/SC-15.md) | تعارض نسخه، شناسه فرمان و تلاش مجدد رویداد | [PHP](Complete/Scenario15RetryOutboxTest.php) |

اجرا: `php vendor/phpunit/phpunit/phpunit tests/Scenarios/Complete` با TM_TEST_DRIVER=sqlite. فقط SC-10/11 میزبان واقعی لازم دارند. راهنمای آماده‌سازی و متغیرهای بارگذاری در docs/testing است. migrate:fresh روی پایگاه اختصاصی آزمون اجرا می‌شود.
