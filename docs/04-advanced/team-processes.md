# مسئولیت، تأیید و فرآیندهای تیمی

[فهرست](../README.md) · [مفهوم Workflow](workflow.md)

این قابلیت‌ها روی Instance (نمونه اجرای گردش‌کار) کار می‌کنند. نسخه Task، نسخه Instance، نسخه Pipeline و نسخه مرحله تأیید مستقل‌اند؛ از خروجی همان عملیات استفاده کنید. Context (زمینه اجرا) باید مجوز واقعی میزبان داشته باشد. تعریف صرف مجوز در آرایه نمونه، جای احراز مجوز میزبان را نمی‌گیرد.

## مسیرهای کوتاه موجود و اتصال کامل

جدول، مسیر واقعی سرویس را نشان می‌دهد؛ امضای کامل ورودی/خروجی در [مرجع تولیدشده از سورس](../api/reference.md) آمده است. برای اجرای کامل، هر کلاس آزمون پیوندشده همراه کلاس پایه‌اش داده، تنظیم، Namespace (فضای نام)، use (کلاس‌های واردشده)، قرارداد میزبان و مسیر شکست را در همان فایل‌های واقعی دارد؛ کد دوم ناسازگاری در راهنما کپی نشده است.

| نیاز | مسیر روزمره | قرارداد یا مجوز افزوده | نمونه کامل و قابل اجرا |
|---|---|---|---|
| مسئول اصلی/ثانویه | assignments → assign؛ سیاست پویا: configureInstance/configureStage | assignment.manage و ResponsibilityResolver؛ پیش‌فرض فقط self | [AssignmentCoreTest](../../tests/AssignmentCoreTest.php)، [AssignmentTestCase](../../tests/AssignmentTestCase.php) |
| تأیید | approvals → begin → vote → execute؛ active برای مرحله جاری | approval.manage، approval.vote، approval.execute؛ نسخه مرحله و Instance | [ApprovalCoreTest](../../tests/ApprovalCoreTest.php)، [ApprovalTestCase](../../tests/ApprovalTestCase.php) |
| تعهد زمان خدمت | stagePolicies → publishSla؛ sla → pause/resume/run | sla.manage/pause/run؛ CalendarRegistry برای تقویم کاری نام‌دار | [SlaCoreTest](../../tests/SlaCoreTest.php)، [SlaTestCase](../../tests/SlaTestCase.php) |
| واگذاری و بازگشت | handoffs → delegate/parallel → settle | handoff.manage/view؛ سیاست نتیجه و بازگشت | [HandoffCoreTest](../../tests/HandoffCoreTest.php)، [HandoffParallelTest](../../tests/HandoffParallelTest.php) |
| موجودیت مقصد همان پایگاه | routes → move/spawn | LocalSubjectRouter و اتصال مشترک؛ handoff.manage | [LocalRouteTest](../../tests/LocalRouteTest.php) |
| مقصد بیرونی | sagas → begin → execute؛ repair برای رسید نامعلوم | RemoteSubjectRouter، saga.manage/repair؛ رسید پایدار | [ExternalSagaTest](../../tests/ExternalSagaTest.php) |
| پرونده مرتبط | cases → create/timeline؛ caseKey هنگام start | case.manage و مشاهده Subjectهای مجاز | [EnterpriseProcessTest](../../tests/EnterpriseProcessTest.php) |
| خودکارسازی | automation → publish؛ events → subscribe/enqueue/run | automation.manage/subscription.manage و زمینه serviceActor (پردازشگر) | [AutomationCoreTest](../../tests/AutomationCoreTest.php)، [EventDeliveryTest](../../tests/EventDeliveryTest.php) |
| زمان و برنامه | planning → estimate/previewSchedule/executeSchedule؛ time → start/stop | schedule.update/time.log؛ preview نسخه‌دار | [TaskPlanningTest](../../tests/TaskPlanningTest.php)، [TimeTrackingCoreTest](../../tests/TimeTrackingCoreTest.php) |
| دوره کاری و خروجی | sprints → enroll/start/snapshot/close؛ reports → flow/totals | sprint.manage/report.view؛ سیاست حمل کار ناتمام | [SprintCoreTest](../../tests/SprintCoreTest.php)، [PlanningViewsTest](../../tests/PlanningViewsTest.php) |

دستور هر نمونه از ریشه مخزن: `php vendor/phpunit/phpunit/phpunit --filter AssignmentCoreTest --do-not-cache-result`؛ برای سطر دیگر، نام همان کلاس واقعی را جای فیلتر بدهید. همه کلاس‌ها آزمون fixture (داده نمونه کنترل‌شده) هستند؛ migrate:fresh را روی داده محصول اجرا نکنید. مسیرهای زنجیره‌ای دقیق‌تر، داده آغازین و محدوده آزمون در [سناریوهای مسئولیت و فرایند](../../tests/Scenarios/README.md) آمده‌اند.

## داده و نتیجه

assignments مسئول‌ها و سیاست نسخه‌دار را نگه می‌دارد؛ به‌جای دست‌کاری owner مستقیم، مسیر انتساب را اجرا کنید. approvals مرحله/رأی را جدا ذخیره می‌کند؛ رأی حدنصاب نتیجه را یک‌بار قطعی می‌کند و اجرای دوباره نباید آن را دوباره‌شماری کند. SLA (تعهد زمان خدمت) به مراجعه فعال مرحله وابسته است؛ مرحله خارج‌شده نباید اعلان تأخیر تازه بگیرد. نام تقویم کاری باید ثبت‌شده و نسخه‌دار باشد؛ پکیج نمایش Calendar جای WorkingCalendar نیست.

واگذاری همان Subject هویت جدید تجاری نمی‌سازد؛ routes برای انتقال/ساخت داده مالک میزبان است. برای اثر سامانه بیرونی، تراکنش توزیع‌شده فرض نشده است؛ رسید و جبران صریح لازم‌اند. رویداد در tm_outbox همراه ثبت نهایی تغییر ذخیره می‌شود و پردازشگر بیرون تراکنش باز، مجوز جاری را دوباره می‌سنجد. [جزئیات پردازشگر](../advanced/workers.md) و [قراردادها](../api/contracts.md).

## شکست‌های مهم

assignment_unresolvable/assignee_ineligible یعنی تأمین‌کننده میزبان نامزد معتبر نداده است. approval_pending و approval_sequence_violation یعنی ترتیب یا مرحله فعال رعایت نشده؛ حذف حفاظت راه رفع نیست. calendar_unregistered به ثبت تقویم معتبر نیاز دارد. handoff_return_conflict به بازبینی نسخه مبدأ و مسیر repair (ترمیم کنترل‌شده) نیاز دارد. external_action_pending یعنی رسید مقصد هنوز معلوم نیست؛ قبل از تکرار اثر بیرونی از lookup قرارداد مقصد استفاده شود. preview_stale یعنی پیش‌نمایش تازه لازم است. داده و رویداد ناقص نباید بعد از شکست تراکنش باقی بمانند؛ آزمون‌های پیوندشده این مسیرها را بررسی می‌کنند.

تلاش مجدد همان فرمان، operation و داده ثابت می‌خواهد؛ قصد جدید با نسخه/دلیل اصلاح‌شده، شناسه تازه دارد. مرورگر نباید capability یا serviceActor تعیین کند. خروجی فعلی بیشتر سرویس‌های این صفحه آرایه است و با TaskResult نشست یکسان‌سازی نشده تا قرارداد قبلی شکسته نشود.
