# معماری و نقطه‌های توسعه

Workflow Definition (تعریف گردش‌کار) نسخهٔ تغییرناپذیر دارد. Workflow Instance (نمونهٔ اجرای گردش‌کار) صاحب وضعیت، نسخه، مالک و تاریخچه است. Pipeline (مسیر عملیاتی) نگاشت Stage (مرحله) و سیاست انتقال را تعیین می‌کند؛ Board (نمای کانبان) صاحب وضعیت نیست.

Subject (موجودیت مرتبط) نام پایدار و شناسهٔ رشته‌ای دارد. اسلات `main` جایگاه اصلی است؛ قید یکتا از دو نمونهٔ فعال همان Subject/Scope/Slot جلوگیری می‌کند. اسلات‌های کمکی برای فرایند موازی مستقل‌اند. نمایش در چند نما نمونهٔ تازه نمی‌سازد.

مدل، پیام، دپارتمان و اعتبارسنجی تجاری در میزبان می‌مانند. نمونه‌های [Ticketing](../../examples/Ticketing/TaskManagerTicketingProvider.php) در `App\TaskManager` قرار می‌گیرند؛ وابستگی هسته به Ticket Chat نیستند.

نقطه‌های توسعه: `SubjectRegistry`، `SubjectHandler`، `StageLifecycle`، `ResponsibilityResolver`، `EntryRoutingProvider`، `ReminderRecipients`، `LocalSubjectRouter`/`RemoteSubjectRouter` و درایورهای اعلان، فایل و شبکه. ابتدا قرارداد موجود را متصل کنید؛ حقیقت دوم برای وضعیت نسازید.
