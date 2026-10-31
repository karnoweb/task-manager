# اتصال واقعی Ticketing

[فهرست اتصال‌ها](index.md) · [گردش‌کار مستقل](../04-advanced/workflow.md)

این اتصال برای پکیج واقعی Karnoweb Ticket Chat و مدل Conversation از نوع ticket نوشته شده است؛ هسته Task Manager آن را لازم ندارد. داده پیام، دپارتمان و نتیجه تجاری نزد میزبان می‌ماند. alias پایدار support.ticket در SubjectRegistry (فهرست رابط موجودیت‌ها) ذخیره می‌شود؛ نام کلاس PHP در رکورد فرآیند ذخیره نمی‌شود.

## فایل‌های کامل میزبان

این فایل‌ها را با Namespace (فضای نام) موجود `App\TaskManager` در پوشه app/TaskManager میزبان قرار دهید؛ تمام useها و کد واقعی در فایل‌ها آمده‌اند:

- [TaskManagerTicketingProvider](../../examples/Ticketing/TaskManagerTicketingProvider.php): اتصال Container (تزریق وابستگی) و حفظ handlerهای قبلی.
- [TicketSubject](../../examples/Ticketing/TicketSubject.php): query مجاز، ستون شناسه/قلمرو و guard (محافظ) تغییر تجاری.
- [TicketWorkflow](../../examples/Ticketing/TicketWorkflow.php): تعریف وضعیت‌ها و فرمان بستن.
- [TicketWrites](../../examples/Ticketing/TicketWrites.php): نوشتن کنترل‌شده وضعیت تجاری و نتیجه در اتصال مشترک.
- [DepartmentRouting](../../examples/Ticketing/DepartmentRouting.php) و [TicketDepartmentRouter](../../examples/Ticketing/TicketDepartmentRouter.php): سلسله‌مراتب و جابه‌جایی معتبر میزبان.
- [TicketReminderRecipients](../../examples/Ticketing/TicketReminderRecipients.php): گیرنده پیشنهادی و مجوز گیرنده دیگر.
- [TicketMessageAccess](../../examples/Ticketing/TicketMessageAccess.php): کنترل دسترسی پیام در دامنه میزبان.

Provider را در bootstrap/providers.php میزبان ثبت کنید؛ تنظیم مدل و پایگاه داده Ticket Chat باید از قبل معتبر باشد. این نمونه دپارتمان را با meta.scope_key به قلمرو متصل می‌کند و شناسه actor/scope را عددی معتبر می‌خواهد؛ سیاست تجاری همه میزبان‌ها را فرض نمی‌کند. برای محصول دیگر، همین قرارداد را با داده واقعی آن میزبان متصل کنید؛ انتقال مستقیم این سیاست بدون تطبیق مجاز نیست.

## مسیر اجرا، مجوز و نتیجه

پس از ساخت Ticket واقعی و ثبت SubjectHandler (رابط اتصال موجودیت)، Workflow را منتشر کنید؛ entryRouting()->enter براساس دپارتمان، یا instances()->start بدون مسیر، اجرای آن Subject را آغاز می‌کند. خروجی Instance دارای instance_key، current_state_key و version است. انتقال عادی و executeBusinessCommand جدا هستند؛ فرمان close_result مجوز تجاری مستقل، reason_code معتبر و داده نتیجه لازم دارد. جدول‌ها و نگاشت واقعی دلیل/وضعیت در TicketWorkflow مشخص‌اند.

Context (زمینه اجرا) باید از کاربر و قلمرو معتبر میزبان ساخته شود؛ query روی مسئول، سازنده و عضویت فعال بخش محدود می‌شود. تغییر وضعیت تجاری و Instance در همان اتصال تراکنش اجرا می‌شوند؛ داده را هم‌زمان از سرویس قدیمی ناسازگار ننویسید. task_id برای Ticket خارجی تهی است؛ Subject ID همان شناسه Conversation باقی می‌ماند. عقب‌ماندن تصویر وضعیت تجاری به ticket_projection_conflict می‌رسد، نه بازنویسی خاموش.

نام ثبت‌نشده subject_type_unregistered؛ بخش نامعتبر/نامرئی not_found؛ گیرنده نامجاز reminder_recipient_denied؛ مجوز فرمان ناکافی capability_denied است. پس از خطا داده و مجوز واقعی را اصلاح و نسخه/شناسه عملیات مناسب را رعایت کنید؛ guard میزبان را حذف نکنید.

## آزمون معتبر

[TicketingIntegrationTest](../../tests/TicketingIntegrationTest.php) داده کاربر و Ticket واقعی، Provider و فرآیند را می‌سازد و چندمرحله‌ای اجرا می‌کند. اجرای مخزن با `php vendor/phpunit/phpunit/phpunit --filter TicketingIntegrationTest --do-not-cache-result` است؛ ابتدا TM_TICKETING_HOST_AUTOLOAD و TM_PHASE6_HOST_AUTOLOAD را به autoload نمونه میزبان واقعی پشتیبانی‌شده تنظیم کنید. بدون آن‌ها تست Skip (اجرانشده به دلیل پیش‌نیاز) می‌شود. ساخت کاربران/جدول‌های مصنوعی این fixture (نمونه آزمون) را در پایگاه تجاری اجرا نکنید. وضعیت واقعی این مأموریت در [گزارش آزمون](../testing.md) آمده است؛ خواندن سورس به‌تنهایی گواه موفقیت اتصال نیست.
