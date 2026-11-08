# قراردادها، DTO و خطا

[مرجع سرویس‌ها](reference.md) از Reflection (خواندن امضای واقعی کلاس PHP) تولید شده است. دستور بازسازی: `php tools/build_api_reference.php`؛ سرویس‌ها در این ابزار نمونه‌سازی نمی‌شوند.

`ExecutionContext(scope, actor, correlationId, serviceActor=false, capabilities=null, originEventId=null, automationDepth=0)` تغییرناپذیر است. هویت و محدوده را از ورودی عمومی نگیرید. `AuthorizationProvider::authorize` مجوز فرمان و `::constrain` محدودیت SQL را اعمال می‌کند.

`SubjectHandler` شامل `query(context, capability)`, `idColumn()`, `scopeColumn()` و `guard(context, subjectId, instance, action, payload)` است. guard در همان اتصال و تراکنش اجرا می‌شود. اثر بیرونی متعلق به Outbox (صندوق رویداد ماندگار) است. [پیاده‌سازی کامل](../../examples/Ticketing/TicketSubject.php).

`EntryRoutingProvider::authorize` و `::parent` سلسله‌مراتب و مجوز میزبان را می‌دهند. `ReminderRecipients::suggest` و `::authorize` پیشنهاد و صلاحیت گیرنده را کنترل می‌کنند. پیش‌فرض یادآور فقط خود کاربر است؛ Routing بدون Provider معتبر، خطای صریح دارد.

DTO (شیء انتقال داده)های `CreateTodo(context, title, operationId)`، `MutateTask`، `TaskQuery(context, page=1, limit=30)` و `TaskResult` مسیر نوع‌دار هستند. QueryService و اتصال‌های رابط قرارداد Cursor (نشانگر ادامهٔ صفحه) جدا دارند؛ نشانگر را بین سرویس‌ها جابه‌جا نکنید.

`transition()` انتقال عادی و `executeBusinessCommand()` فرمان ویژه با مجوز مستقل، دلیل و guard را اجرا می‌کند. `availableTransitions()` نامزدهای سیاست جاری را می‌دهد؛ `requires_validation=true` یعنی guard میزبان هنوز باید هنگام اجرا تأیید کند.

`DomainError`: `errorCode` کلید پایدار (مثلاً `not_found`)، `getMessage()` متن ترجمه‌شده (`task-manager::errors.{code}`)، `details` دادهٔ ساخت‌یافته. در `transition_policy_denied`، `details['allowed_destinations']` مقاصد مجاز است. `not_found` نبود مجوز را از نبود رکورد تفکیک نمی‌کند. انتشار `task-manager-lang` برای سفارشی‌سازی متن در میزبان.

`EventPump` رویداد committed را صف و بازیابی می‌کند؛ ارسال‌کنندهٔ میزبان باید `event_id` را رعایت کند. `ReminderService::query(context, limit=30, afterId=0)` هر صفحه را با مجوز فعلی می‌خواند؛ `count()` فقط pending/sent مجاز را می‌شمارد.
