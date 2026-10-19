# سند جامع سناریوهای آزمون Task Manager / Workflow & Process Engine

**نسخه:** 1.0 — ۲۰ اکتبر ۲۰۲۶  
**مبنای الزام:** PRD v2.0 و «برنامه اجرایی هفت‌فازی»  
**وضعیت:** سناریوها برای پیاده‌سازی توسط AI و QA تعریف شده‌اند، **هنوز اجرا یا تأیید نشده‌اند**.  
**هدف:** اثبات رفتار درست طی چندین عملیات پیاپی، رقابت هم‌زمان، تغییر Scope و دسترسی، خرابی Worker و هماهنگی CRM/Kanban/Calendar.

> **راهنمای پذیرش:** هر تست دارای داده اولیه، دست‌کم یک عملیات، انتظار صریح نسبت به state/DB/events و حالت خطاست. صرف status HTTP/عدم Exception، قبولی محسوب نمی‌شود. هر وضعیت مهم پس از هر گام Assert می‌شود؛ تکرار یک عملیات نباید اثر تکراری بسازد؛ اطلاعات خصوصی در فهرست/آمار/تایم‌لاین/پیام/خروجی نیز باید پوشیده بمانند. اجرای MySQL/PostgreSQL برای قفل، صف، Race و هم‌زمانی الزامی است؛ SQLite فقط Unit/Feature پایه.

## A. اصول اجرای مجموعه

1. **Fixtures مشترک و ایزوله:** Scope-A/Scope-B، Actor(owner,sales,contracts,support,manager,guest,service)، Department Sales/Contracts/Support، سه Workflow مستقل، یک Campaign و یک Calendar کاری؛ Seed کاملاً deterministic با `Clock` و ID ثابت.
2. **Assertions در هر مرحله:** database state، Version، Journal، immutable Activity/Transition/StageVisit، Outbox، no unapproved side effects، count/aggregate، Role/Scope visibility و Request IDs.
3. **Policy واقعی در نقاط حساس:** ActorResolver، ScopeResolver، SQL-level authorization، external host handler و guarded transition را تنها در Unit مجزا mock کن؛ در Security/E2E روی پیاده‌سازی واقعی یا Test Provider قرارداد-کامل اجرا کن.
4. **حدود یکپارچگی:** Contract/CRM مالک داده تجاری‌اند. Same-DB با transaction مشترک و Cross-DB با Saga/Pending/Compensation؛ هیچ Transaction سراسری جعلی مجاز نیست.
5. **سقوط و تکرارپذیری:** failure injection قبل از commit، بعد از commit، پیش از ACK، وسط batch و حین callback؛ تکرار با operation_id یکسان و مختلف.
6. **انتقال بین برد و Process:** `changeBoardVisibility` بدون تغییر Workflow؛ `moveInstanceToPipeline` با mapping؛ `delegate/spawn/parallel` با slot/case/return policy؛ هرکدام تست جدا.
7. **معیار پوشش:** هر `TM-001..236` یک فایل Scenario PHP + Markdown مستقل؛ `E2E-01..06` و `NET-...` به صورت سناریوی چندمرحله‌ای؛ `REG-...` به‌صورت edge/regression. نام‌ها و شناسه‌ها ثابت.
8. **کنترل کیفیت:** تست flaky با retry خاموش پوشانده نشود؛ علت Race بررسی شود. هر defect تست بازتولیدکننده + Fix + بازاجرای Full Suite داشته باشد.

## B. شش سناریوی انتهابه‌انتهای اصلی PRD (بازنشر عین الزامات)



### E2E-01 — Todo شخصی تا پروژه واقعی

Actor A با Context معتبر Todo «تماس با مشتری» را فقط با Title می‌سازد. سیستم Inbox خودش و Instance `personal` می‌سازد. Actor B هیچ List/Count/Task را نمی‌بیند. A با مجوز Todo را به Project تبدیل می‌کند، Workflow و Display Key جدید با Preview map می‌شود؛ public_key ثابت؛ Task در برد Kanban پروژه و Due در Calendar قابل مشاهده است. Comments/Share قبلی فقط طبق Policy انتقال باقی می‌مانند.

### E2E-02 — کمپین فروش ۱۰۰ رکورد، زمان Stage و پایان شرطی

مدیر CRM یک Campaign برای محصول ایجاد می‌کند؛ ۱۰۰ Lead/Opportunity وارد یک Group با Pipeline فروش می‌شوند؛ SLA مثلا `new=2h`, `contact=4h`, `negotiation=24h`, `reservation=48h` **اعداد صرفاً داده سناریوی تست‌اند، نه پیش‌فرض دائمی محصول**. Owner/Assignee و StageVisit برای هر ورود معتبرند.

- ۱۵ Instance تا Cutoff Milestone معتبر `reservation_passed` دارند؛ از این میان ۵ Instance در قرارداد delegated هستند.
- ۷۵ Instance هنوز این Milestone را ندارند و Active هستند؛ ۱۰ Instance پیش‌تر Terminal شده‌اند.
- پایان کمپین با دلیل `campaign_ended` و PolicyRevision مشخص اجرا می‌شود: **۷۵ مورد ناموفق با دلیل، ۱۵ مورد continued (از جمله ۵ قرارداد فعال)، ۱۰ مورد unchanged/already_terminal**؛ جمع دقیق ۱۰۰.
- Stage SLA breached در ۱۲ مورد از اعضا می‌تواند هشدار/برچسب Stale ایجاد کند؛ **نباید بدون Policy نتیجه شمار Campagin را تغییر دهد**.
- Retry همان `run_key` هیچ Transition یا Event مضاعف تولید نمی‌کند؛ Worker crash در وسط ۱۰۰ Item به‌طور کامل resume می‌شود؛ یک Item متعارض نتیجه exception قابل بررسی دارد.

### E2E-03 — فروش → قرارداد → فروش با سه Approver

Opportunity در مرحله `negotiation` است. کارشناس فروش `delegate` به Pipeline قرارداد می‌کند و مبدأ به `waiting_contract` می‌رود؛ Owner فروش ثابت، Assignee قرارداد تغییر، SLA مبدأ pause. مرحله قرارداد یک Approval Step با سه Approver و quorum تعریف‌شده دارد؛ پس از جمع نتیجه معتبر `approved`، Handoff resolved و با `ReturnPolicy.approved` مبدأ به `sale_next_stage` می‌رود، Assignee فروش براساس restore_origin برمی‌گردد و SLA resume یا reset مطابق snapshot. مسیر `rejected` به `rework` و `cancelled` به `follow_up` متفاوت‌اند. تمام رخدادها با Case Timeline قابل مشاهده مجوزدارند.

### E2E-04 — Ticket پشتیبانی → فرصت فروش

Ticket در Stage پشتیبانی ثبت می‌شود؛ کارشناس با دلیل `sales_opportunity_identified` درخواست `spawn` می‌دهد؛ CRM Opportunity می‌سازد و همان Case هر دو را نگه می‌دارد. Ticket طبق قوانین به referred_to_sales می‌رود و Opportunity وارد Pipeline فروش می‌شود. فروش پیام‌های محرمانه پشتیبانی را بدون مجوز نمی‌بیند؛ اختلال در CRM هنگام ایجاد Opportunity باعث retry/repair ایمن و وضعیت pending می‌شود نه duplicate.

### E2E-05 — Move مستقیم همان Ticket

یک Source Ticket به‌طور صریح در Pipeline فروش قابل قبول است؛ `moveInstanceToPipeline` وضعیت مبدأ را با Preview به مقصد نگاشت و Team/Department را از Service میزبان تغییر می‌دهد؛ Subject ID تغییر نمی‌کند، StageVisit قدیم بسته و جدید آغاز می‌شود، تاریخچه قابل بازیابی و Access جدید از CRM/Ticket policy تأیید می‌شود.

### E2E-06 — موازی و بازگشت

یک Opportunity دو Process Slot مستقل `legal_review` و `risk_review` دارد؛ Boardهای مختلف می‌توانند آن را نشان دهند. ReturnPolicy با `join=all` تا پایان هر دو منتظر می‌ماند. خروجی Conflict یکی نمی‌تواند وضعیت دیگری را بازنویسی کند؛ لغو یکی براساس policy explicit manual-review یا cancel کل فرایند است.


## C. تمامی ۲۳۶ سناریوی پذیرش PRD اصلی



**این‌ها الزامات تست هستند، نه نتایج اجراشده.** برای هر TM یک سناریوی Feature/Scenario خودکار با فایل Markdown فارسی مستقل لازم است. فایل سناریو باید Seed/Actor/Scope/Input/Operation ID/Version/Steps/Expected SQL Changes/Events/Permission/Teardown را توضیح دهد. اجرای یک سناریو شامل assert بعد از مراحل حساس باشد؛ از Assert تک‌مرحله‌ایِ صرف برای فرآیندهای چندروزه/چنددپارتمانی اجتناب شود.

### گروه A — Todo (001 تا 012)

| ID | شرح سناریو و نتیجه مورد انتظار |
|---|---|
| TM-001 | ساخت Todo با فقط عنوان — انتظار: project=null، actor صحیح، personal workflow |
| TM-002 | عنوان خالی و طولانی — انتظار: خطای typed، هیچ Task ذخیره نشود |
| TM-003 | تکمیل/بازگشایی — انتظار: status/completed_at/history سازگار |
| TM-004 | کار با due_at بدون starts_at — انتظار: Query upcoming صحیح؛ Calendar due marker |
| TM-005 | ویرایش متن و اولویت — انتظار: version افزایش، Activity ساخته شود |
| TM-006 | آرشیو و بازگردانی — انتظار: Task از Active خارج و سپس بازگردد |
| TM-007 | حذف نرم و بازیابی — انتظار: داده اصلی و روابط با سیاست معتبر بمانند |
| TM-008 | clone Task — انتظار: موارد مجاز کپی، ID/activity نه |
| TM-009 | Todo شخص B توسط A — انتظار: عدم دسترسی read/count/update |
| TM-010 | تبدیل Todo به Task پروژه‌ای — انتظار: authorization و status mapping اتمیک |
| TM-011 | دو فرمان یکسان با operation_id — انتظار: فقط یک ایجاد؛ نتیجه ثابت |
| TM-012 | کامند با operation_id یکسان و payload متفاوت — انتظار: idempotency_key_conflict |

### گروه B — Project و Workflow (013 تا 026)

| ID | شرح سناریو و نتیجه مورد انتظار |
|---|---|
| TM-013 | ساخت پروژه از قالب — انتظار: Workflow snapshot و scope معتبر |
| TM-014 | اعضا و نقش‌های مختلف — انتظار: permission matrix اجرا شود |
| TM-015 | انتقال نامجاز بین Statusها — انتظار: رد عملیات و حفظ وضعیت قبلی |
| TM-016 | انتقال همراه فیلد اجباری — انتظار: Validation یا success مطابق payload |
| TM-017 | Transition به همان Status — انتظار: Action معتبر + history بدون تغییر status |
| TM-018 | Cancel تأیید انتقال — انتظار: هیچ write/event رخ ندهد |
| TM-019 | تغییر نسخه Workflow پروژه — انتظار: Preview mapping و ثبت کامل/rollback |
| TM-020 | حذف Status استفاده‌شده — انتظار: بدون map ممنوع، با map موفق |
| TM-021 | آرشیو پروژه — انتظار: ایجاد Task جدید رد شود |
| TM-022 | عضوی که از پروژه خارج شده — انتظار: دسترسی revoke فوری و cache invalidation |
| TM-023 | Task با type Bug و Workflow اختصاصی — انتظار: قوانین نوع مستقل اعمال شود |
| TM-024 | Task چند مسئول و ظرفیت یک — انتظار: طبق config rejection/acceptance |
| TM-025 | انتقال Task به پروژه دیگر — انتظار: scope یکسان، role و workflow معتبر |
| TM-026 | Project read-only Viewer — انتظار: فقط مجاز به query جزئیات مجاز |

### گروه C — Hierarchy و Dependency (027 تا 040)

| ID | شرح سناریو و نتیجه مورد انتظار |
|---|---|
| TM-027 | دو زیرتسک در یک والد — انتظار: درصد پیشرفت ۰/۵۰/۱۰۰ درست |
| TM-028 | زیرتسک چندسطحی — انتظار: ancestor/descendant درست |
| TM-029 | فرزند زیر خودش — انتظار: task_hierarchy_cycle |
| TM-030 | جابه‌جایی والد به فرزند دور — انتظار: چرخه شناسایی شود |
| TM-031 | فرزند در Project دیگر — انتظار: بدون انتقال کنترل‌شده رد شود |
| TM-032 | حذف والد با فرزند — انتظار: deny_if_children پیش‌فرض |
| TM-033 | FS dependency — انتظار: شروع پس‌نیاز قبل از پایان پیش‌نیاز رد شود |
| TM-034 | SS / FF / SF — انتظار: هر چهار نوع guard/scheduling واقعی |
| TM-035 | افزودن A→B و B→A — انتظار: dependency_cycle |
| TM-036 | چرخه سه‌مرحله‌ای — انتظار: dependency_cycle حتی در Bulk |
| TM-037 | race در افزودن دو dependency — انتظار: یک تغییر معتبر و graph بدون چرخه |
| TM-038 | وابستگی بین Scopeها — انتظار: scope_mismatch |
| TM-039 | related link بدون Block — انتظار: انتقال مجاز طبق Workflow |
| TM-040 | Preview جابه‌جایی تاریخ وابستگان — انتظار: بدون تأیید هیچ تاریخی عوض نشود |

### گروه D — Content و Scheduling (041 تا 056)

| ID | شرح سناریو و نتیجه مورد انتظار |
|---|---|
| TM-041 | دو Checklist و پیشرفت متفاوت — انتظار: count/progress هرکدام و Task درست |
| TM-042 | Checklist item با assignee/due — انتظار: فیلتر و permission صحیح |
| TM-043 | تبدیل Item به Task با retry — انتظار: فقط یک Task جدید ساخته شود |
| TM-044 | Comment CRUD و تاریخچه edit — انتظار: نقش و window edit اعمال شود |
| TM-045 | HTML/script مخرب در کامنت — انتظار: خروجی safe، بدون XSS |
| TM-046 | Mention کاربر غیرمجاز — انتظار: اعلان/اطلاعات غیرمجاز ارسال نشود |
| TM-047 | ضمیمه فایل با رابط میزبان — انتظار: metadata ذخیره، دانلود امن |
| TM-048 | تلاش برای دانلود فایل Task دیگر — انتظار: not_found/access_denied |
| TM-049 | TimeEntry و اعداد تخمین — انتظار: spent/remaining صحیح |
| TM-050 | تداخل TimeEntry — انتظار: policy هشدار یا رد معتبر |
| TM-051 | بازه Task چندروزه — انتظار: ends_at نیمه‌باز و Calendar range درست |
| TM-052 | تسک فقط دارای due — انتظار: نشانگر مجزای مهلت، بدون span جعلی |
| TM-053 | timezone/DST ناموجود — انتظار: policy reject/resolve مطابق config |
| TM-054 | Template با Checklist — انتظار: clone defaults امن بدون members خارجی |
| TM-055 | فیلد اختصاصی نوع عددی/تاریخ — انتظار: validation و DB filter درست |
| TM-056 | archive field استفاده‌شده — انتظار: مقادیر حفظ، فرم جدید مخفی مطابق policy |

### گروه E — Recurrence و Sprint (057 تا 072)

| ID | شرح سناریو و نتیجه مورد انتظار |
|---|---|
| TM-057 | Daily recurrence با horizon — انتظار: محدود و بدون وقوع تکراری |
| TM-058 | Weekly recurrence در تغییر DST — انتظار: ساعت محلی صحیح |
| TM-059 | Monthly recurrence در روز ۳۱ — انتظار: fallback policy مشخص و تست‌شده |
| TM-060 | دو Job موازی تولید وقوع — انتظار: unique key و یک وقوع |
| TM-061 | تغییر یک وقوع — انتظار: سری اصلی ثابت، exception ثبت |
| TM-062 | تغییر کل سری — انتظار: وقوع‌های تکمیل‌شده سالم |
| TM-063 | توقف تکرار — انتظار: وقوع جدید پس از until ساخته نشود |
| TM-064 | تکرار پس از تکمیل قبلی — انتظار: فقط بعد از complete تولید شود |
| TM-065 | Sprint planned→active — انتظار: committed snapshot ایجاد شود |
| TM-066 | افزودن Story به Sprint فعال — انتظار: scope change جدا گزارش شود |
| TM-067 | پایان Sprint و انتقال ناتمام‌ها — انتظار: policy اعمال و تاریخچه سالم |
| TM-068 | Story points و Velocity — انتظار: جمع از Taskهای واقعاً Done |
| TM-069 | Burndown با تاریخچه — انتظار: تغییر تعداد Task گزارش اولیه را تحریف نکند |
| TM-070 | انتقال Story میان Epicها — انتظار: rollup درست و یک parent معتبر |
| TM-071 | Milestone و Release مرتبط با Task — انتظار: پیشرفت/تاریخ‌ها مستقل از Sprint |
| TM-072 | Critical path با داده ناقص — انتظار: insufficient_data، نه نتیجه جعلی |

### گروه F — Security و Integration (073 تا 090)

| ID | شرح سناریو و نتیجه مورد انتظار |
|---|---|
| TM-073 | نشت Query بین tenantها — انتظار: هیچ رکورد/Count/Filter از scope دیگر |
| TM-074 | IDOR Task/Checklist/Comment — انتظار: رد همه خواندن و نوشتن غیرمجاز |
| TM-075 | Authorization در Bulk — انتظار: atomic یا partial طبق mode، بدون bypass |
| TM-076 | دو Update هم‌زمان — انتظار: یکی موفق، دیگری version_conflict |
| TM-077 | Rollback پس از خطای انتقال — انتظار: نه Activity موفق نه Event انتشار |
| TM-078 | After Commit event — انتظار: فقط پس از commit منتشر شود |
| TM-079 | Outbox retry — انتظار: بدون dispatch دوباره Side Effect دامنه |
| TM-080 | Actor unconfigured در Job — انتظار: fail safe و بدون query گسترده |
| TM-081 | Kanban بدون نصب Task Manager — انتظار: عملکرد قبلی Kanban مستقل |
| TM-082 | Calendar بدون نصب Task Manager — انتظار: عملکرد قبلی Calendar مستقل |
| TM-083 | Task Manager بدون دو پکیج UI — انتظار: نصب، Facade، Todo، Project و گزارش سالم |
| TM-084 | Task+Kanban transition — انتظار: Service Task Manager مالک نوشتن، Drawer scoped |
| TM-085 | Task+Calendar move/resize — انتظار: زمان از SchedulingService و permission معتبر |
| TM-086 | Task+Kanban+Calendar — انتظار: یک رکورد حقیقت و refresh بدون اجرای دوباره |
| TM-087 | Order فقط Kanban/Calendar — انتظار: بدون نیاز به Task Manager |
| TM-088 | CRM Opportunity لینک‌شده به Task — انتظار: تغییر Task، status CRM را بی‌اجازه تغییر ندهد |
| TM-089 | ۱۰k Task و pagination — انتظار: DB paging، N+1 کنترل، Count مجاز |
| TM-090 | گزارش با مجوز محدود — انتظار: Aggregate فقط از Taskهای قابل مشاهده |

### گروه G — اصلاح مدل و توابع پایه (091 تا 108)

| ID | شرح سناریو و نتیجه مورد انتظار |
|---|---|
| TM-091 | Todo بدون Project به Inbox مخصوص Owner وارد شود؛ دیگری نه لیست و نه Count را ببیند |
| TM-092 | انتقال Todo بین دو List شخصی با Rank ثابت و یک عملیات معتبر |
| TM-093 | Reminder نسبی قبل از due فقط یک بار dispatch شود |
| TM-094 | تغییر Due، Reminder آتی را با dedupe امن باززمان‌بندی کند |
| TM-095 | Share محدود Task به Actor B فقط capability مجاز بدهد؛ لغو Share آن را فوراً باطل کند |
| TM-096 | Bug فرزند Epic با دو Workflow متفاوت مجاز باشد و Transitionهای مستقل اجرا شوند |
| TM-097 | تغییر نگاشت project×type، Taskهای قبلی را بدون Preview نامعتبر نکند |
| TM-098 | Blueprint پیش‌فرض در دو Scope مستقل clone شود، تغییر یکی دیگری را تغییر ندهد |
| TM-099 | public_key در Todoهای بدون Project هم همیشه یکتا باشد |
| TM-100 | انتقال بین Project کلید نمایشی را تغییر دهد، public_key ثابت بماند، alias قابل resolve فقط با Permission |
| TM-101 | closure table در reparent چندسطحی اتمیک و بدون مسیر نادرست باقی بماند |
| TM-102 | race در reparent دو subtree باعث cycle نشود |
| TM-103 | reorder هم‌زمان نتیجه نامعتبر نسازد و conflict شفاف بازگرداند |
| TM-104 | rank نزدیک به هم rebalance محدود شود و کارت‌ها گم نشوند |
| TM-105 | FS/FF/SS/SF به‌صورت واقعی از زمان شروع/تکمیل و lag کنترل شوند |
| TM-106 | dependency با تاریخ ناموجود و guard strict به‌جای حدس رد شود |
| TM-107 | cross-project در یک Scope با مجوز دوطرفه و cross-scope همیشه رد شود |
| TM-108 | Watcher غیرمجاز با mention یا پیوست دسترسی به Task پیدا نکند |

### گروه H — Time/History/Jalali (109 تا 125)

| ID | شرح سناریو و نتیجه مورد انتظار |
|---|---|
| TM-109 | دو تایمر فعال برای یک Actor هم‌زمان رد شوند |
| TM-110 | Start/Stop تایمر با retry مدت و time entry تکراری تولید نکند |
| TM-111 | Projection عقب‌مانده به‌عنوان stale اعلام یا از حقیقت محاسبه شود |
| TM-112 | all-day با انتهای غیرشامل در دو timezone روز آخر اضافی ندهد |
| TM-113 | جست‌وجوی «ي/ی»، «ك/ک»، رقم لاتین/فارسی جواب یکسان بدهد |
| TM-114 | Weekly recurrence در شنبه/جمعه با تعطیلات تنظیم‌پذیر درست باشد |
| TM-115 | multi-select فقط گزینه‌های معتبر Field را بپذیرد |
| TM-116 | sort/filter عددی و تاریخ از ستون typed ایندکس‌پذیر انجام شود |
| TM-117 | آرشیو Custom Field مقدار قدیمی را بی‌مجوز نابود نکند |
| TM-118 | تکرار روز ۲۵ ماه **شمسی** در ماه‌های مختلف تاریخ میلادی درست تولید کند |
| TM-119 | تکرار روز ۳۰/۳۱ شمسی با `skip/last_day` درست باشد |
| TM-120 | تغییر Rule شمسی وقوع‌های تکمیل‌شده را بازنویسی نکند |
| TM-121 | دو Generator هم‌زمان با Jalali recurrence وقوع تکراری ایجاد نکنند |
| TM-122 | Burndown بر Snapshot تاریخی تکیه کند نه فقط وضعیت فعلی Task |
| TM-123 | Velocity فقط برگ‌ها را بشمارد؛ Epic+فرزندان دوبار جمع نشوند |
| TM-124 | Lead/Cycle از status transitions با ترتیب زمانی محاسبه شود |
| TM-125 | Snapshotهای تکراری Sprint با dedupe مانع شمار مضاعف شوند |

### گروه I — Enterprise و Migration (126 تا 147)

| ID | شرح سناریو و نتیجه مورد انتظار |
|---|---|
| TM-126 | root Facade بدون Context امن عملیات را رد کند |
| TM-127 | تغییر capability عضو روی Query و Count و Cache بلافاصله اعمال شود |
| TM-128 | crash بعد از commit و قبل از dispatch، با Outbox رویداد را قابل بازیابی نگه دارد |
| TM-129 | Operation ID یکسان با payload متفاوت خطای ثابت برگرداند |
| TM-130 | CRM Deal به‌طور مستقیم با Trait در Kanban تغییر وضعیت دهد بدون Task Manager |
| TM-131 | CRM follow-up Task به Deal وصل شود؛ تغییر Task بدون policy مرحله Deal را تغییر ندهد |
| TM-132 | یک Task مرتبط با Deal در Kanban و Calendar یک شناسه و یک حقیقت داشته باشد |
| TM-133 | Query 100k Task با paging/scope filtering، کل Dataset را PHP load نکند |
| TM-134 | Search index normalized مجوز را قبل از Count، Filter و Page اعمال کند |
| TM-135 | Automation فقط Actions مجاز را اجرا کند و Activity ثبت کند |
| TM-136 | Automation chain از سقف Depth عبور نکند و حلقه بی‌نهایت ایجاد نکند |
| TM-137 | Retry رویداد یک Automation را دوبار اجرا نکند |
| TM-138 | Webhook با HMAC معتبر، timestamp و event_id ارسال شود |
| TM-139 | Webhook به IP داخلی/Metadata/Redirect غیرمجاز رد شود |
| TM-140 | Retry Webhook با Backoff و Dead Letter قابل پیگیری باشد |
| TM-141 | Export CSV/JSON مجوزدار، streaming، محافظت CSV injection و فایل موقت امن داشته باشد |
| TM-142 | Revoked permission اجازه دریافت فایل Export قبلی را ندهد |
| TM-143 | مهاجرت legacy Task در Shadow Read بدون double-write انجام شود |
| TM-144 | Cutover بعد از reconciliation؛ rollback بدون گم‌شدن Task قدیمی ممکن باشد |
| TM-145 | Import دوباره legacy با idempotency رکورد تکراری ایجاد نکند |
| TM-146 | member departure طبق سیاست انتخابی assignments را کنترل و audit کند |
| TM-147 | تغییر watcher روی اعلان تأثیر بگذارد ولی Permission جدید ایجاد نکند |

### گروه J — Audit/Subject/Pipeline (148 تا 165)

| ID | شرح سناریو و نتیجه مورد انتظار |
|---|---|
| TM-148 | ثبت تغییر Title و Priority با Actor و before/after — انتظار: دو Audit append-only، version و correlation درست، بدون تغییر تاریخچه قبلی |
| TM-149 | Timeline یک Case با Ticket و Opportunity — انتظار: فروش پیام خصوصی پشتیبانی را نبیند؛ فقط رخدادهای مجاز بازگردند |
| TM-150 | Edit/Delete Comment و Attachment در Audit — انتظار: بدنه محرمانه در log raw ذخیره نشود، رویداد معتبر بماند |
| TM-151 | رد Transition و تلاش دسترسی نامجاز — انتظار: نه transition موفق و نه event success ساخته شود؛ security decision امن ثبت شود |
| TM-152 | ساخت Workflow Instance برای Lead خارجی — انتظار: CRM مالک Lead بماند؛ state فقط در Instance باشد |
| TM-153 | ساخت Workflow Instance برای Task شخصی — انتظار: ساخت Todo فقط با Title و Context، Instance پیش‌فرض idempotent |
| TM-154 | ثبت Alias ثابت Morph Map برای Ticket — انتظار: تغییر نام Class مرجع Subject را خراب نکند |
| TM-155 | Subject وجود ندارد یا actor حق view ندارد — انتظار: start و query و count امن رد شوند |
| TM-156 | دو Slot مستقل برای یک Opportunity — انتظار: دو Instance هم‌زمان و وضعیت مستقل، بدون تعارض |
| TM-157 | دو تلاش concurrent در یک Slot — انتظار: فقط یک رکورد Active Slot و یک Instance فعال |
| TM-158 | بستن Instance و شروع اجرای بعدی همان Slot — انتظار: Instance تاریخی حفظ و Slot آزاد شود |
| TM-159 | تغییر CRM source status خارج از coordinator — انتظار: در Write API و reconciliation به‌صورت conflict گزارش شود |
| TM-160 | Stage Visit ورود و بازگشت — انتظار: هر ورود visit جدا؛ Duration دقیق و transition history |
| TM-161 | Mapping Stage به State نامعتبر — انتظار: Publish رد؛ هیچ Instance orphan نشود |
| TM-162 | دو Stage با State یکسان — انتظار: active_stage_key مسیر یکتا را تعیین کند و ambiguity رد شود |
| TM-163 | برد واحد با دو Pipeline منبع مجاز — انتظار: هر کارت Source/Instance/Permission خودش را داشته باشد |
| TM-164 | یک Pipeline در دو Board با Filterهای متفاوت — انتظار: یک State truth؛ جابه‌جایی فقط نمایشی تغییر Domain ندهد |
| TM-165 | Move یک Instance به Pipeline متفاوت — انتظار: Preview map و permission، visit خروج/ورود، هویت Subject حفظ |

### گروه K — Handoff/Case/Multi-Instance (166 تا 187)

| ID | شرح سناریو و نتیجه مورد انتظار |
|---|---|
| TM-166 | Delegate فروش به قرارداد — انتظار: Origin waiting، SLA pause، Destination slot جدید، Handoff فعال |
| TM-167 | تأیید قرارداد و برگشت موفق — انتظار: state mapping، assignee restore و audit کامل |
| TM-168 | رد قرارداد و برگشت برای اصلاح — انتظار: return policy متفاوت از success و reason اجباری |
| TM-169 | لغو قرارداد و بازگشت پیگیری — انتظار: cancelled نتیجه جداگانه، هیچ Success جعلی |
| TM-170 | Timeout قرارداد — انتظار: timeout policy زمان‌دار، فقط یک Outcome و برگشت مجاز |
| TM-171 | Recall دستی ارجاع — انتظار: مجوز recall، قفل destination و transition تعریف‌شده |
| TM-172 | Return با Origin تغییر کرده — انتظار: return_conflict و manual-review، نه overwrite |
| TM-173 | Outcome تکراری برابر — انتظار: idempotent؛ Event و Transition تکراری نشود |
| TM-174 | Outcome تکراری متناقض — انتظار: handoff_already_resolved یا version_conflict |
| TM-175 | Delegate هم‌زمان دو بار به یک مقصد — انتظار: یک Destination/Handoff معتبر، دیگری conflict/dedupe |
| TM-176 | Handoff چرخه‌ای خودکار A→B→A — انتظار: کنترل max depth و lineage بدون Loop بی‌نهایت |
| TM-177 | Handoff Parallel با Join=all — انتظار: Origin فقط پس از تکمیل همه مقصدها Resume شود |
| TM-178 | Handoff Parallel با Join=any — انتظار: اولین نتیجه مجاز کافی، دیگر نتایج با policy مدیریت شوند |
| TM-179 | Handoff Parallel با Quorum — انتظار: حدنصاب معتبر، رأی/نتیجه اضافی دوباره Resume نسازد |
| TM-180 | حفظ یک Subject در دو Board — انتظار: نمایش هم‌زمان مجاز، یک Slot state truth |
| TM-181 | انتقال همان Ticket به برد فروش — انتظار: Source همان Ticket بماند؛ Department و State هماهنگ تغییر کنند |
| TM-182 | Ticket→Opportunity در CRM — انتظار: دو Subject در Case، ایجاد CRM فقط از Service مالک، پیام محرمانه محافظت شود |
| TM-183 | Crash CRM هنگام Spawn Opportunity — انتظار: Saga pending/retry، بدون Opportunity تکراری یا Success جعلی |
| TM-184 | نمایش Case Timeline پس از دو Handoff — انتظار: Audit و correlation مرتب، redacted و Permission-aware |
| TM-185 | Handoff مقصد Subject را نمی‌پذیرد — انتظار: destination_not_accepted، هیچ تغییر Origin |
| TM-186 | محدودیت Cross-Scope در Handoff/Case — انتظار: ساخت لینک و اجرای فرایند بین Scopeها رد شود |
| TM-187 | خاتمه Handoff بعد از Close شدن Origin — انتظار: manual-repair/return_conflict، خطای شفاف |

### گروه L — Assignment/Approval/SLA (188 تا 204)

| ID | شرح سناریو و نتیجه مورد انتظار |
|---|---|
| TM-188 | Owner ثابت و Assignee متغیر در Stageها — انتظار: Owner حفظ، تاریخچه Assignments دقیق |
| TM-189 | Primary با چند Secondary — انتظار: حداکثر یک Primary فعال و N Secondary مجاز |
| TM-190 | Assignment RoundRobin هم‌زمان — انتظار: تخصیص عادلانه قابل پیش‌بینی، بدون انتخاب نامعتبر و duplicate |
| TM-191 | Dynamic Resolver هیچ کارشناس واجد شرایط ندارد — انتظار: assignment_unresolvable؛ صف بررسی، نه Actor تصادفی |
| TM-192 | بازگشت Assignee پس از قرارداد — انتظار: return policy restore_origin/keep_current/resolve_stage دقیق |
| TM-193 | تأیید سه‌نفره با Quorum=2 — انتظار: دو رأی معتبر نتیجه بسازد، رأی تکراری دوم شمرده نشود |
| TM-194 | Approval ترتیبی — انتظار: رأی مرحله بعدی قبل از مرحله قبل رد شود |
| TM-195 | Revocation یک Approver پیش از رأی — انتظار: حق رأی بازبینی؛ Audit و حل pending مشخص |
| TM-196 | Stage SLA elapsed ۲۴ ساعت — انتظار: در ۲۴ ساعت کامل breach و warning پیش از آن دقیق |
| TM-197 | Stage SLA در تقویم کاری ایران — انتظار: جمعه/شنبه و تعطیل/شیفت از WorkingCalendar محاسبه شود |
| TM-198 | بازگشت به Stage بعد از خروج — انتظار: Visit جدید و reset/cumulative policy واقعی |
| TM-199 | Pause/Resume SLA در Handoff — انتظار: Duration pause جدا، SLA مبدأ/مقصد مستقل |
| TM-200 | تسک بدون فعالیت اما Deadline نگذشته — انتظار: stale قابل علامت‌گذاری و business state بدون تغییر |
| TM-201 | SLA breach موجب Dead Candidate — انتظار: برچسب و پیشنهاد خروج، نه خودکار Failed مگر policy |
| TM-202 | AutoFail SLA بدون Permission/CRM Guard — انتظار: عملیات رد و manual review ثبت شود |
| TM-203 | دو Watchdog روی یک Visit — انتظار: یک SLA event/Notification/Action، dedupe واقعی |
| TM-204 | انتقال Task یک لحظه پیش از SLA job — انتظار: Job بعدی Stage قدیم را تغییر ندهد |

### گروه M — Security/Integration/Scale (205 تا 220)

| ID | شرح سناریو و نتیجه مورد انتظار |
|---|---|
| TM-205 | Count/Facet/Report روی Subject مخفی — انتظار: وجود/تعداد Record غیرمجاز افشا نشود |
| TM-206 | بررسی Authorization در هر Group Item — انتظار: Bulk با ۱۰۰ عضو، denial در یک مورد از کنترل خارج نشود |
| TM-207 | ServiceActor Job با Scope غلط — انتظار: fail-closed و عدم نشت به Tenant دیگر |
| TM-208 | Replay Outbox و event پس از Commit — انتظار: Side effect Domain تکراری نشود، Crash recovery |
| TM-209 | Kanban کارت Task و Opportunity در یک Board — انتظار: Sourceها مستقل، permission مشترک رعایت شود |
| TM-210 | Calendar نمایش Due/SLA/Recurring — انتظار: event_id پایدار، overlap-range و occurrence-safe pagination |
| TM-211 | Drag کانبان به Stage قرارداد — انتظار: Handler Handoff مناسب فراخوانی، نه direct DB status update |
| TM-212 | Task Manager بدون Kanban/Calendar یا CRM — انتظار: تمام Facade پایه و Process Instance تست موفق |
| TM-213 | CRM Pipeline Cutover به Task Manager — انتظار: CRM از Writer جدید، legacy writer غیرمجاز |
| TM-214 | Query صد هزار Task/Instance/Visit — انتظار: SQL filter/keyset، حافظه محدود، N+1 کنترل |
| TM-215 | Pagination هم‌زمان با تغییر Permission — انتظار: Cursor invalidation و عدم data leak |
| TM-216 | Automation اجرای مرحله‌ای از Handoff event — انتظار: max depth, whitelist, idempotent actions |
| TM-217 | Webhook Event Handoff با محتوای محرمانه — انتظار: HMAC/Scope/redaction و جلوگیری از SSRF |
| TM-218 | Export Group Closure برای کاربر محدود — انتظار: فقط Itemهای مجاز، CSV injection protection، revoke |
| TM-219 | Migration CRM workflow/stage با alias — انتظار: Mapping/instance/history دقیق، بدون duplicate truth |
| TM-220 | Shadow Read و Cutover CRM — انتظار: reconciliation، بدون double-write، rollback برنامه‌ریزی‌شده |

### گروه N — پایان کمپین و Bulk Lifecycle (221 تا 236)

| ID | شرح سناریو و نتیجه مورد انتظار |
|---|---|
| TM-221 | کمپین ۱۰۰ موردی با ۷۵ قبل رزرو، ۱۵ عبور، ۱۰ terminal — انتظار: ۷۵ failed reason=campaign_ended، ۱۵ continued، ۱۰ unchanged |
| TM-222 | منقضی‌شدن کمپین هنگام Stage رزرو بدون Milestone — انتظار: به‌صورت پیش‌فرض ناموفق، حتی اگر نام Stage رزرو باشد |
| TM-223 | Milestone رزرو قبل از Cutoff — انتظار: مجاز به ادامه حتی در Pipeline قرارداد |
| TM-224 | Milestone رزرو بعد از Cutoff — انتظار: عدم grandfather، طبق policy fail/manual-review |
| TM-225 | Terminal قبل از Campaign Close — انتظار: نباید دوباره Transition/Fail ساخته شود |
| TM-226 | Campaign پایان با Source CRM نامعتبر — انتظار: fail-closed و Exception نه اجرای خودسرانه |
| TM-227 | Preview Group Closure — انتظار: Revision، شمار مجاز، reason و delta بدون mutation |
| TM-228 | تغییر گروه پس از Preview قبل Execute — انتظار: preview_stale یا شمار تغییرات، نسخه معتبر |
| TM-229 | گزارش هر Item با reason و Operation ID — انتظار: lineage و Audit کامل، Exception قابل بررسی |
| TM-230 | اجرای گروهی دو Worker موازی — انتظار: Unique Item claim، هر Task یک action، no double audit |
| TM-231 | Crash پس از ۴۰ مورد از ۱۰۰ — انتظار: Resume ۶۰ باقی‌مانده بدون اجرای مجدد ۴۰ مورد |
| TM-232 | Policy پایان کمپین همراه SLA breach — انتظار: breach بدون fail policy بر نتیجه Campaign تأثیر نگذارد |
| TM-233 | در حال اجرا بودن قرارداد Delegated در پایان کمپین — انتظار: با milestone محافظت‌شده ادامه دهد؛ Handoff مبدأ معتبر |
| TM-234 | CRM veto روی تغییر وضعیت یک مورد — انتظار: Item manual_review؛ سایر Itemها طبق partial_success ادامه دهند |
| TM-235 | Run Key یکسان با Payload متفاوت — انتظار: idempotency_key_conflict و عدم شروع Run جدید |
| TM-236 | اجرای مجدد کمپین با Run جدید — انتظار: Preview تازه، terminalهای قبلی unchanged، Audit دوم معتبر |


## D. سناریوهای تکمیلی چندمرحله‌ای (NET)

### NET-001 — Todo ساده بدون Project، سپس تبدیل به کار تیمی

- **Fixture:** کاربر A و B در Scope-A؛ Scope-B مستقل؛ Inbox وجود ندارد.
- **گام‌ها و انتظارها:** ۱) A با `title+context` تسک می‌سازد ← Inbox و personal Instance دقیقاً یک‌بار ساخته، project=null. ۲) A due/priority می‌افزاید ← version و audit افزایش. ۳) B بدون share می‌خواند ← deny در get/list/count. ۴) A share محدود به view می‌دهد ← B فقط مشاهده می‌کند و update/complete رد می‌شود. ۵) A تسک را به Project تبدیل می‌کند ← شناسه پایدار، display-key/alias جدید، workflow mapping و policy share بازبینی. ۶) در Kanban/Calendar اختیاری نمایش می‌شود ← فقط منبع تعیین‌شده و داده مجاز.
- **Assert نهایی:** یک Task، عدم تکرار Inbox، timeline صحیح، projection قابل بازسازی، no leakage.

### NET-002 — کلید Idempotency و دو درخواست ناهمسان

- **Fixture:** Title، Actor، Scope و operation_id ثابت؛ دو payload متفاوت.
- **گام‌ها و انتظارها:** ۱) Create-A ← یک Task، Journal/Outbox واحد. ۲) تکرار عین Create-A ← همان result، هیچ side-effect دوم. ۳) Create-B با همان operation_id و due متفاوت ← `idempotency_key_conflict`. ۴) Retry هم‌زمان از دو Worker ← فقط یک Task. ۵) همان operation_id در Scope مجاز دیگری طبق namespace قرارداد ← نتایج ایزوله.
- **Assert نهایی:** atomic uniqueness، payload hash، عدم پنهان‌شدن اختلاف درخواست.

### NET-003 — مالک ثابت و مسئول اجرایی متغیر در فروش→قرارداد

- **Fixture:** Lead مالک Sales-A و Assignee Sales-B؛ Department قرارداد Team-C.
- **گام‌ها و انتظارها:** ۱) انتقال Lead به negotiation ← Owner ثابت، Assignee Sales-B. ۲) `delegate` قرارداد ← Origin on-hold و Stage SLA طبق policy paused؛ Destination assignee Contracts-C. ۳) مسئول قرارداد تغییر می‌کند ← فقط assignment مقصد و audit. ۴) قرارداد Approved ← ReturnPolicy Origin Sales-B را بازمی‌گرداند. ۵) Query timeline ← هر دو مسئول/تاریخ شروع و پایان قابل مشاهده طبق مجوز.
- **Assert نهایی:** Owner هیچ‌گاه عوض نشود؛ Task/Lead جدید بی‌دلیل ساخته نشود؛ SLA Resume با Reset اشتباه نشود.

### NET-004 — تأیید سه‌نفره ترتیبی با رأی نامعتبر

- **Fixture:** Approvers A,B,C به ترتیب؛ rule sequential؛ یک مرحله نیازمند سه تأیید.
- **گام‌ها و انتظارها:** ۱) رأی B قبل از A ← رد؛ بدون Activity معتبر Approval. ۲) رأی A ← ثبت یک رأی. ۳) A دوباره رأی می‌دهد ← no duplicate. ۴) B تأیید ← Step بعدی باز. ۵) C با `expected_version` قدیمی ← conflict؛ با نسخه صحیح تأیید ← Approved. ۶) Retry رویداد تکمیل ← فقط یک return/handoff completion.
- **Assert نهایی:** quorum، ترتیب، مجوز، hash payload و Audit رأی‌ها سازگار.

### NET-005 — تأیید هم‌زمان Quorum=2 از 3 و رد متقارن

- **Fixture:** سه approver، parallel، quorum=2 و RejectPolicy مصوب.
- **گام‌ها و انتظارها:** ۱) رأی A و B هم‌زمان approve ← تنها یک terminal transition. ۲) رأی C دیرهنگام ← outcome از قبل resolved، طبق Policy رد یا بدون side-effect. ۳) سناریوی تازه با رأی Reject A ← بر اساس veto/quorum policy رفتار صریح. ۴) با قطع دسترسی B پیش از رأی، vote او نباید مجاز بماند.
- **Assert نهایی:** هیچ‌گاه دو نتیجه متناقض یا سه outbox برای یک terminal outcome ایجاد نشود.

### NET-006 — نبود مسئول واجد شرایط در Stage پویا

- **Fixture:** Stage بعدی فقط تیم Legal، هیچ عضو فعال/مجاز ندارد.
- **گام‌ها و انتظارها:** ۱) Transition وارد Stage Legal می‌شود ← resolver عدم تخصیص را تشخیص می‌دهد. ۲) طبق policy به queue/manual-review می‌رود یا همان transition رد می‌شود؛ **نه** واگذاری null/تصادفی. ۳) عضو واجد شرایط به تیم می‌پیوندد و replay operation جدید با version جدید انجام می‌شود ← تخصیص درست. ۴) revoke عضویت بعدی ← policy member_departure اجرا می‌شود.
- **Assert نهایی:** هیچ Scope/Department اشتباه و Assignment یتیم ایجاد نشود.

### NET-007 — Stage SLA: سالم، هشدار، نقض، رکود، کاندیدای خروج

- **Fixture:** Stage SLA برابر ۲۴ ساعت، warning در ۲۰ ساعت، stale پس از ۶ ساعت عدم فعالیت.
- **گام‌ها و انتظارها:** ۱) ورود t0 ← StageVisit/Deadline دقیق. ۲) t0+6h بدون فعالیت ← Stale علامت‌گذاری ولی Status تجاری عوض نشود. ۳) t0+20h ← warning یک‌بار. ۴) t0+24h ← breached؛ با policy ترکیبی dead_candidate. ۵) دو Watchdog موازی ← فقط یک breach/action. ۶) مدیر Review می‌کند و انتقال مجاز انجام می‌دهد ← Visit قدیم resolved، Visit جدید مستقل.
- **Assert نهایی:** `stale`، `breached` و `failed` مفاهیم مجزا باشند؛ پیش‌فرض auto-fail ممنوع.

### NET-008 — Pause/Resume SLA قرارداد و محاسبه ساعت کاری

- **Fixture:** Sales SLA هشت ساعت کاری، شنبه تا چهارشنبه ۹–۱۷، تعطیل پنجشنبه/جمعه، contracts SLA مستقل.
- **گام‌ها و انتظارها:** ۱) ورود فروش شنبه ۱۵:۰۰ ← ۲ ساعت مصرف می‌شود. ۲) یکشنبه ۱۰:۰۰ delegate ← origin pause با ۳ ساعت مصرف کل و ۵ ساعت باقی‌مانده. ۳) در قرارداد ۲۰ ساعت واقعی طی می‌شود ← به زمان مبدأ اضافه نشود. ۴) Approved سه‌شنبه ۱۴:۰۰ ← فروش resume با همان ۵ ساعت. ۵) محاسبه در آینده براساس ساعت کاری و تعطیلات ← deadline صحیح، بدون منفی/دو بار کم‌شدن.
- **Assert نهایی:** pause intervals، deadline snapshots، owner/assignee و case timeline دقیق.

### NET-009 — بازگشت چندباره به یک Stage و جمع/ریست SLA

- **Fixture:** Negotiation با سیاست `reset_on_reentry`، سپس نسخه دیگر با `cumulative`.
- **گام‌ها و انتظارها:** ۱) ورود اول t0، خروج t0+2h ← Visit-1 بسته. ۲) بازگشت t0+5h ← Visit-2 جدید و مهلت تازه در Reset. ۳) در حالت Cumulative زمان مصرف‌شده Visit-1 لحاظ می‌شود. ۴) گزارش stage-aging ← هر Visit و مجموع تفکیک‌شده. ۵) ویرایش policy جاری بدون preview ← نباید تاریخچه را بازنویسی کند.
- **Assert نهایی:** یک Visit جدید به هر Reentry واقعی و عدم باز شدن Visit پیشین.

### NET-010 — Cutoff کمپین دقیق با ۱۰۰ مورد

- **Fixture:** ۱۰۰ عضو ثبت‌شده: ۷۵ فعال پیش از reservation_passed؛ ۱۵ دارای milestone قبل Cutoff (۵ delegated قرارداد)؛ ۱۰ terminal.
- **گام‌ها و انتظارها:** ۱) Preview ← دسته‌ها ۷۵/۱۵/۱۰ با اجازه و فیلتر Scope. ۲) closure با cutoff/version/key معتبر آغاز شود ← Group در closing. ۳) Worker هر عضو را Lock و از Milestone تاریخی تا cutoff تصمیم بگیرد. ۴) Run کامل ← ۷۵ `failed:campaign_ended`، ۱۵ `continued`، ۱۰ `already_terminal`. ۵) تکرار همان Run ID ← هیچ تغییر اضافی. ۶) خواندن Audit و Report ← مجموع دقیق ۱۰۰.
- **Assert نهایی:** ۵ فرآیند قرارداد ادامه‌دار و orphan-free؛ ۱۲ مورد SLA breached هیچ اثر ناخواسته‌ای در ۷۵/۱۵/۱۰ ندارند.

### NET-011 — عبور از رزرو دقیقاً قبل/بعد Cutoff

- **Fixture:** Cutoff ساعت ۱۲:۰۰:۰۰ UTC، سه Opportunity با Milestone در ۱۱:۵۹:۵۹، ۱۲:۰۰:۰۰ و ۱۲:۰۰:۰۱.
- **گام‌ها و انتظارها:** ۱) milestone نخست commit شود ← ادامه مجاز. ۲) milestone دوم با `committed_at == cutoff` نیز مجاز به ادامه است؛ **قرارداد پیشنهادی `committed_at <= cutoff` به‌عنوان ADR پیش از فاز ۳ قفل شود**. اگر تیم قرارداد متفاوتی تصویب کرد، PRD و این تست باید با هم نسخه‌بندی شوند؛ حدس در runtime ممنوع. ۳) milestone سوم ← ادامه پیش‌فرض ممنوع. ۴) Transition دیرهنگام/درحال‌اجرا ← براساس commit ordering و version حقیقت تعیین شود.
- **Assert نهایی:** تصمیم براساس تاریخچه معتبر و زمان commit، نه Stage name یا ساعت مرورگر.

### NET-012 — Crash وسط کمپین و تکرار Workerها

- **Fixture:** گروه ۱۰۰ عضو، Outbox واقعی، batch chunk ۱۰ و failure injection.
- **گام‌ها و انتظارها:** ۱) Worker-1 تا commit عضو ۳۷ پیش برود و از بین برود. ۲) Worker-2 از lease منقضی claim کند ← هیچ عضو ۱..۳۷ دوباره تغییر وضعیت ندهد. ۳) ۳ Worker هم‌زمان بقیه را اجرا کنند ← هر Item یک result terminal. ۴) گزارش Run ← تعداد دقیق، ۱۰۰ processed و duplicate event صفر. ۵) `run_key` همان و payload متفاوت ← conflict.
- **Assert نهایی:** partial_success/failed item در Report جدا باشند؛ جداول قابل resume و memory bounded.

### NET-013 — Preview کمپین قدیمی شده و Policy تغییر کرده

- **Fixture:** Preview با Revision-1، تغییر عضویت/مراحل بین Preview و Execute.
- **گام‌ها و انتظارها:** ۱) Preview-1 تولید شود. ۲) مدیر Revision-2 بسازد و یکی از اعضا پس از Cutoff جابه‌جا شود. ۳) Execute با preview قدیمی ← `preview_stale` یا delta صریح؛ اجرای خام revision-2 با consent قبلی ممنوع. ۴) Preview جدید و operation_id جدید ← رفتار مطابق snapshot جدید. ۵) ثبت Audit هر دو Preview و Run.
- **Assert نهایی:** Group enroll در حالت closing مطابق policy block/queue، data leak از delta وجود نداشته باشد.

### NET-014 — Transition هم‌زمان با پایان کمپین

- **Fixture:** Instance در آستانه milestone رزرو و Group هم‌زمان closing.
- **گام‌ها و انتظارها:** ۱) barrier واقعی برای دو Transaction بساز: transition reservation و closure item. ۲) هر دو تلاش به commit کنند. ۳) replay نتایج براساس ترتیب lock و زمان ثبت milestone تا cutoff انجام شود. ۴) هیچ وضعیت impossible مانند هم‌زمان failed+active-reservation باقی نماند. ۵) retry conflict با version صحیح مجاز یا manual-review شود.
- **Assert نهایی:** consistency در ۱۰۰ تکرار race؛ `operation_id`ها و eventها dedupe.

### NET-015 — فروش→قرارداد→فروش با سه Outcome

- **Fixture:** یک Opportunity و سه نمونه یکسان برای outcomeهای Approved/Rejected/Cancelled.
- **گام‌ها و انتظارها:** ۱) Sales→delegate Contract، Origin waiting، SLA pause. ۲) Contracts مرحله‌بندی و سه Approver اجرا شوند. ۳) در مسیر Approved فروش به next_stage و Owner ثابت؛ در Rejected به rework با دلیل؛ در Cancelled به follow_up با reason. ۴) در هر مسیر Assignee/SLA طبق snapshot بازگردد. ۵) Attempt نتیجه متناقض پس از resolved ← `handoff_already_resolved`.
- **Assert نهایی:** سه نتیجه متفاوت با تاریخچه یکپارچه، بدون تکثیر Opportunity.

### NET-016 — Ticket پشتیبانی به همان Subject در برد فروش منتقل می‌شود

- **Fixture:** Provider فروش رسماً Ticket را می‌پذیرد؛ Support-Sales Department policy مشخص.
- **گام‌ها و انتظارها:** ۱) Ticket در Support Stage-Review؛ پیام خصوصی قابل مشاهده فقط Support. ۲) `moveInstanceToPipeline` با Preview mapping و reason ← stage، workflow instance و department طبق قرارداد تغییر کنند. ۳) Sales مجاز Ticket را در Board خود ببیند و State تغییر دهد. ۴) Sales نامجاز private comment را نبیند. ۵) Return به Support در صورت policy با عملیات صریح، شناسه Ticket همان بماند.
- **Assert نهایی:** یک Ticket؛ migration history و StageVisit/permission منعکس شود.

### NET-017 — Ticket به Opportunity تبدیل تجاری می‌شود، نه Type Mutate

- **Fixture:** Ticket دارای درخواست فروش؛ CRM handler create Opportunity را پشتیبانی می‌کند.
- **گام‌ها و انتظارها:** ۱) Support با reason `sales_opportunity_identified` فرمان `spawn` می‌دهد. ۲) Task Manager Case/Request correlation می‌سازد و CRM واقعاً Opportunity جدید ایجاد می‌کند. ۳) Ticket به referred_to_sales و Opportunity به first_sales_stage می‌روند. ۴) Retry handler duplicate ← همان Opportunity و همان link. ۵) Failure در CRM ← pending/retry/compensated و نه success کاذب. ۶) Sales بدون Permission نباید private Ticket attachments را بخواند.
- **Assert نهایی:** دو Subject با شناسه جدا، یک Case، یک عملیات کسب‌وکار idempotent.

### NET-018 — هم‌زمانی دو Workflow روی یک Subject و Join=all

- **Fixture:** Opportunity دارای `legal_review` و `risk_review` با Slot مجزا؛ Origin باید منتظر هر دو باشد.
- **گام‌ها و انتظارها:** ۱) هر دو Process آغاز شوند و Boardهای مستقل نشان دهند. ۲) Legal approved ولی Risk pending ← Origin تغییری نکند. ۳) Risk approved ← Origin فقط یک Transition و Return. ۴) نتیجه Legal تکرار/دیرهنگام ← اثری نداشته باشد. ۵) یک Slot cancelled در اجرای تازه ← Policy manual-review/cancel مستقل بررسی شود.
- **Assert نهایی:** هیچ Slot دیگری overwrite نشود و StageVisit جدا داشته باشد.

### NET-019 — Case Timeline و محرمانگی بین دپارتمان‌ها

- **Fixture:** یک Case با Ticket private note و Opportunity و Contract، سه نقش Support/Sales/Legal.
- **گام‌ها و انتظارها:** ۱) کاربران timeline را بخوانند ← visibility هر آیتم براساس subject/action و Scope. ۲) Sales فهرست Case را باز کند ← private note حتی در count/snippet/notifications دیده نشود. ۳) عضویت Support revoke شود ← cache/timeline/facet permission فوراً تغییر. ۴) Admin مجاز chronology کامل را ببیند؛ timestamps/correlation بدون تغییر.
- **Assert نهایی:** global Case visibility به معنی global item visibility نباشد.

### NET-020 — انتقال به برد دیگر در برابر تغییر Process

- **Fixture:** Lead با Instance فروش و دو Board به تفکیک region/owner.
- **گام‌ها و انتظارها:** ۱) Board-A کارت را نشان می‌دهد. ۲) `changeBoardVisibility`/tag view به Board-B ← state و StageVisit بدون تغییر. ۳) در هر دو Board به شکل مجاز نمایش شود ← یک source-of-truth. ۴) Drag واقعی در Board-B با command transition ← Instance واحد و Board-A منعکس کند. ۵) stale drag از Board-A با version قدیمی ← conflict.
- **Assert نهایی:** view membership، pipeline move و handoff سه مفهوم مستقل باقی بمانند.

### NET-021 — خروج عضوی از تیم هنگام داشتن چند مسئولیت

- **Fixture:** Employee-A مالک Lead نیست ولی Primary Assignee یک Task و Approver یک Contract است.
- **گام‌ها و انتظارها:** ۱) Member-A از Department حذف شود. ۲) طبق policy `reassign_to_queue`، Assignment فعال به صف مجاز برود. ۳) رأی ثبت‌شده تاریخی حفظ ولی حق رأی بعدی revoke شود؛ quorum recalculation مطابق Policy Snapshot. ۴) Cache Query/Drawer/Count invalidate شود. ۵) Assignment جدید resolve شود؛ پرونده قابل ادامه بماند.
- **Assert نهایی:** تاریخچه برگشت‌ناپذیر و permission جدید اعمال شود؛ اختلال ناقص به Scope دیگر منتقل نشود.

### NET-022 — پایداری Rank در دو Board و Sprint

- **Fixture:** یک Task در Board A و B و در Backlog/Sprint؛ version هر Context جدا.
- **گام‌ها و انتظارها:** ۱) Drag روی Board A Rank همان context را تغییر دهد. ۲) Board B و Sprint ترتیب خودشان را حفظ کنند. ۳) دو Drag هم‌زمان روی Context A ← یکی conflict یا deterministic merge رسمی؛ silent last-write ممنوع. ۴) Rebalance fractional rank با ۱۰هزار Task ← ترتیب پایدار با tie-breaker.
- **Assert نهایی:** مجوز جابه‌جایی، WIP و scope در UI دور زده نشوند.

### NET-023 — زیرتسک چندسطحی، Reparent هم‌زمان و چرخه

- **Fixture:** A→B→C→D و E در همان Project؛ max_depth=8.
- **گام‌ها و انتظارها:** ۱) Parent A را زیر D قرار بده ← cycle رد. ۲) C را زیر E منتقل کن ← Closure و parent_id اتمیک. ۳) دو Transaction هم‌زمان B↔E را جابه‌جا کنند ← هیچ cycle بعد از commit. ۴) Move parent به Scope متفاوت بدون migration ← رد. ۵) Rollup و descendant permissions را دوباره بسنج.
- **Assert نهایی:** closure-table صحت کامل، version/lock، هیچ orphan یا duplicate ancestor.

### NET-024 — وابستگی‌های چهارگانه و زمان کاری

- **Fixture:** چهار Task با FS/FF/SS/SF و Lag + WorkingCalendar.
- **گام‌ها و انتظارها:** ۱) FS بدون finish پیش‌نیاز ← شروع تابع بسته به policy block. ۲) FF پایان قبل از پیش‌نیاز ← رد. ۳) SS با start-date constraint ← تاریخ زودهنگام رد. ۴) SF در صورت record-only policy صرفاً ثبت شود و تصمیم عملیاتی به‌طور ضمنی enforce نشود. ۵) ساخت dependency دایره‌ای A→B→C→A ← رد حتی در race.
- **Assert نهایی:** نوع dependency، semantics و قابلیت enforce با قرارداد نسخه‌دار و audit روشن باشند.

### NET-025 — Sprint/Backlog و گزارش تاریخی غیرقابل دستکاری

- **Fixture:** Epic با دو Story و یک Bug؛ Story points روی برگ‌ها؛ Sprint ۱۴ روزه.
- **گام‌ها و انتظارها:** ۱) برنامه‌ریزی Sprint با snapshot points ← ثبت version. ۲) Taskها جابه‌جا/Complete شوند ← burndown روزانه. ۳) Story Points والد Epic تغییر کند ← double count در Velocity نباید رخ دهد. ۴) پس از پایان Sprint، ویرایش Task/Workflow در هفته بعد ← Snapshot تاریخ Sprint قبلی تغییر نکند. ۵) Query گزارش با Actor بدون مجوز ← داده‌های فرعی/جمع کل افشا نشوند.
- **Assert نهایی:** source status transitions و daily snapshots قابل rebuild با کنترل revision.

### NET-026 — تایمر ثبت کار، قطعی و هم‌پوشانی

- **Fixture:** Task فعال با Time Tracking، Actor-A، Clock ساختگی.
- **گام‌ها و انتظارها:** ۱) Start t0 ← یک entry باز و unique active timer. ۲) Start دوم برای همان Actor در همان Context ← رد یا stop-and-switch فقط با policy. ۳) Stop t0+90m ← `ended_at`، ۹۰ دقیقه و audit. ۴) Stop مجدد همان ID ← نتیجه idempotent. ۵) افزودن ورود دستی هم‌پوشان خلاف rule ← رد؛ اصلاح بعداً با audit و recalculated rollup. ۶) Rebuild projection ← spent/remaining سازگار.
- **Assert نهایی:** مقدار منفی/فاصله ناممکن رد و گزارش پروژه بدون دو بار شماری.

### NET-027 — تکرار تسک در شمسی، ماه کوتاه و آخر هفته

- **Fixture:** Rule هر ماه روز ۳۰ شمسی، `skip` و `last_valid_day` به عنوان دو Policy صریح؛ Timezone تهران.
- **گام‌ها و انتظارها:** ۱) Rule با `calendar_system=jalali` بساز. ۲) وقوع در شهریور ۳۰ معتبر. ۳) برای مهر ۳۰ مطابق policy وضعیت مشخص. ۴) برای اسفند غیرکبیسه روز ۳۰ ← در `skip` وقوعی نیست؛ در `last_valid_day` ۲۹ اسفند است. ۵) برای اسفند کبیسه ۳۰ معتبر. ۶) recurring job تکراری ← occurrence_id ثابت و ایجاد یک Task/Reminder.
- **Assert نهایی:** RFC5545 میلادی نباید به‌غلط جایگزین محاسبه شمسی شود، نتایج نمایش و UTC صحیح.

### NET-028 — DST، رویداد تمام‌روز و Calendar

- **Fixture:** دو Zone دارای تغییر DST و یک Zone بدون DST؛ رویدادهای all-day و دارای ساعت.
- **گام‌ها و انتظارها:** ۱) برنامه تکراری در ساعت محلی ناموجود یا دوباره‌وقوع‌داده‌شده تعریف شود ← رفتار skip/shift/earlier/later طبق policy روشن. ۲) Calendar در ماه/هفته/روز باز شود ← occurrenceها نه گم شوند نه تکرار. ۳) تغییر Zone کاربر ← UTC منبع و all-day exclusive-end همان بمانند. ۴) Drag رویداد Stage SLA read-only ← رد؛ Drag Task due مجاز ← Handler و Audit اجرا.
- **Assert نهایی:** query بازه نیمه‌باز و Cursor occurrence-safe، بدون off-by-one در نیمه‌شب.

### NET-029 — یادآور قبل از Due و بی‌اعتباری بعد از تکمیل

- **Fixture:** Todo due t0+24h با یادآور ۲h قبل؛ کاربر quiet-hours فعال.
- **گام‌ها و انتظارها:** ۱) Reminder برنامه‌ریزی شود ← pending واحد. ۲) due تغییر کند ← reminder قدیمی cancel و جدید idempotent. ۳) Task پیش از موعد تکمیل شود ← pending reminder جدید cancel. ۴) Worker قدیمی پس از cancel برگردد ← پیام ارسال نشود. ۵) reopen و تنظیم due تازه ← جدید با کلید وقوع تازه و سیاست اعلان میزبان.
- **Assert نهایی:** پیام تکراری/دیرهنگام و افشای اطلاعات در اعلان مجاز نباشد.

### NET-030 — Automation: رویداد، شرط، چرخه و خروجی خارجی

- **Fixture:** قانون A در `stage_entered` یک Task ایجاد کند؛ قانون B ایجاد Task را Trigger کند؛ سقف زنجیره.
- **گام‌ها و انتظارها:** ۱) انتقال معتبر رخ دهد ← Event بعد commit. ۲) Rule A یک Task با origin_event_id و idempotency key بسازد. ۳) Rule B تلاش کند loop بسازد ← recursion-depth / dedupe مانع حلقه نامحدود. ۴) رویداد Queue دوباره ارسال شود ← Task تکراری ساخته نشود. ۵) Rule بعداً خاموش شود ← اجرای درحال‌انجام طبق policy snapshot و version بررسی شود.
- **Assert نهایی:** هیچ action بدون whitelist، tenant-cross، یا side-effect قبل commit وجود نداشته باشد.

### NET-031 — Webhook امضاشده، Replay و شکست مقصد

- **Fixture:** Endpoint HTTPS مجاز، secret در SecretProvider و Subscription per scope.
- **گام‌ها و انتظارها:** ۱) پس از transition، webhook outbox delivery با signature/timestamp/event_id ساخته شود. ۲) شبکه timeout کند ← retry محدود و وضعیت pending/dead-letter صحیح. ۳) webhook ACK گم شود ← همان event_id تکرار و مصرف‌کننده idempotent. ۴) ارسال به URL داخلی/metadata endpoint/redirect SSRF ← رد قبل از اتصال. ۵) replay request با timestamp قدیمی یا HMAC اشتباه ← رد.
- **Assert نهایی:** secret در log/audit/export افشا نشود؛ یک Domain transition، ارسال حداقل یک‌بار، اثر کسب‌وکاری دقیقاً یک‌بار با قرارداد مصرف‌کننده.

### NET-032 — Export در حجم بالا و لغو مجوز پس از صف‌شدن

- **Fixture:** ۵۰۰۰ Task بین دو Scope، Actor-A فقط ۱۰۰۰ Task مجاز، CSV/JSON و Excel adapter اختیاری.
- **گام‌ها و انتظارها:** ۱) Export برای Scope-A درخواست شود ← job با predicate مجاز و filter hash. ۲) قبل از اجرای Worker دسترسی Actor-A به بعضی Taskها revoke شود. ۳) Worker export ← مجوز و Scope را دوباره بررسی؛ رکوردهای revoke‌شده حذف و Report صریح. ۴) دانلود بعدی پس از revoke کامل ← deny. ۵) Title با `=HYPERLINK(...)` و newline ← CSV formula injection neutralized.
- **Assert نهایی:** نه metadata، نه count و نه فایل نهایی اطلاعات Scope-B را ندهند؛ حافظه bounded.

### NET-033 — Custom Field typed و Query ایندکس‌پذیر

- **Fixture:** نوع number/date/boolean/text/single-select/multi-select و Scopeهای مستقل.
- **گام‌ها و انتظارها:** ۱) Define field و schema revision. ۲) Set value با نوع نادرست ← typed validation. ۳) Filter/sort عدد/date در SQL ← نه cast lexicographic و نه post-filter. ۴) Multi-select دقیق بر اساس pivot انتخاب شود؛ مقدار حذف‌شده از UI با backfill/impact safe. ۵) Actor بدون field capability ← فیلد در Drawer/Export/Facet پنهان.
- **Assert نهایی:** CustomField values cross-DB و scope-safe؛ تغییر تعریف بدون migration impact ممنوع.

### NET-034 — مهاجرت Workflow و Stage قدیمی CRM بدون Double Write

- **Fixture:** داده CRM قدیمی برای دو Pipeline، Lead/Opportunity و history ناقص.
- **گام‌ها و انتظارها:** ۱) Inventory/Map old→new با dry-run؛ unmatchedها گزارش. ۲) Backfill Instance با source aliases و stable IDs؛ unknown historical durations حدسی نساز. ۳) Shadow Read خروجی old/new را مقایسه و mismatch ثبت. ۴) Cutover فقط یک writer جدید، `crm_leads.stage_id` نویسنده موازی نباشد. ۵) یکی از CRM Handlerها error دهد ← atomic fail یا pending Saga واضح. ۶) Reconciliation counts/states/timelines و rollback rehearsal.
- **Assert نهایی:** برگشت ایمن بدون خاموش‌کردن audit/outbox و بدون حذف داده‌های جدید.

### NET-035 — Outbox crash-after-commit و بازسازی کامل

- **Fixture:** بعد commit DB موفق ولی قبل dispatch Queue، Process را Kill می‌کنیم.
- **گام‌ها و انتظارها:** ۱) فرمان Transition commit شود ← Instance/Visit/Audit/Journal/Outbox همگی ثبت. ۲) Process kill شود ← هیچ event منتشر نشده، Outbox pending محفوظ. ۳) Dispatcher پس از restart pending را ارسال کند. ۴) پیام دوبار deliver شود ← consumer idempotent. ۵) Projection پاک و rebuild شود ← KPI با منبع حقیقت بازسازی؛ Event ID ثابت.
- **Assert نهایی:** از دست‌رفتن رویداد صفر، ثبت Transition دوم صفر، status/metrics consistent.

### NET-036 — عملیات Cross-DB با Saga و شکست سرویس میزبان

- **Fixture:** Task Manager DB-A و CRM DB-B؛ Handler ساخت Opportunity پاسخ HTTP timeout می‌دهد.
- **گام‌ها و انتظارها:** ۱) `spawn` به صورت pending در DB-A ثبت و Outbox منتشر. ۲) CRM عمل را commit کند ولی ACK گم شود. ۳) Retry با business operation id اولیه ← همان Opportunity را برگرداند. ۴) اگر CRM واقعاً fail شود، retry محدود و سپس manual repair/compensation طبق policy؛ مبدأ success دروغین نگیرد. ۵) replay و reconciliation links/Case.
- **Assert نهایی:** نه transaction توهمی بین دو DB و نه Opportunity تکراری.

### NET-037 — ۱۰۰ هزار عضو کمپین با محدودیت حافظه

- **Fixture:** ۱۰۰k Group Members؛ سهم ۷۵k fail، ۱۵k continue و ۱۰k terminal مطابق fixture مصنوعی کنترل‌شده.
- **گام‌ها و انتظارها:** ۱) Preview با scoped aggregate بدون load کل دیتاست. ۲) Run با keyset و claim lease chunks. ۳) توقف و ادامه چند Worker؛ حافظه peak و throughput اندازه‌گیری. ۴) مقایسه report با fixture truth. ۵) replay Run idempotent؛ Webhook/Notification count از deduped events.
- **Assert نهایی:** bounded memory، zero skipped/double item و زمان اجرا ثبت شود؛ حد عملکرد قبل از آزمون با تیم تعیین شود.

### NET-038 — حذف/آرشیو دارای Child، Dependency، Handoff و Case

- **Fixture:** Parent Project Task با سه فرزند، دو dependency و Handoff قرارداد فعال.
- **گام‌ها و انتظارها:** ۱) حذف والد با `deny_if_children` ← رد و بدون side effect. ۲) policy detach/cascade با preview و مجوز مدیر اجرا شود ← روابط/Closure/Audit حفظ طبق قرارداد. ۳) Subject خارجی CRM حذف نرم شود ← Taskهای مرتبط بی‌صدا حذف نشوند. ۴) Handoff active برای پرونده archived شود ← pending return/manual review متناسب با policy. ۵) Restore parent ← idempotent، روابط معتبر و privacy unchanged.
- **Assert نهایی:** هیچ orphan با FK خراب و هیچ تاریخچه پاک‌شده.

### NET-039 — یکپارچگی واقعی Kanban + Calendar + CRM + Task Manager

- **Fixture:** یک Task فروش و یک Opportunity، Kanban Board فقط CRM، Board فقط Task، Calendar رویداد Due و SLA.
- **گام‌ها و انتظارها:** ۱) Source selection per-board/per-calendar فقط مدل‌های ثبت‌شده. ۲) Drag Opportunity به Stage بعدی ← command `expected_version/operation_id` به CRM Guard؛ update فقط Instance واحد. ۳) Drawer Task Activity/Handoff/Dependencies lazily و با permission. ۴) Calendar due تغییر دهد ← تنها فیلد مجاز Task via handler تغییر کند؛ due SLA read-only جابه‌جا نشود. ۵) Event after commit هر دو UI را refresh کند، بدون اجرای transition مجدد. ۶) Uninstall Kanban/Calendar ← Core Task Manager سالم.
- **Assert نهایی:** استقلال نصب در چهار ترکیب Task-only/Kanban-only/Calendar-only/all-three.

### NET-040 — توالی ۳۰روزه واقعی CRM با تغییر مسئول و کمپین

- **Fixture:** Fake Clock ۳۰ روز، ۵۰ Lead، دو Campaign، سه Team، دو Department، پنج Workflow و reminders.
- **گام‌ها و انتظارها:** ۱) Day-1 ایجاد Lead/Task/Owner و StageVisit. ۲) Day-3 تغییر Assignee و SLA breach یکی از موارد؛ Mark+Escalate نه AutoFail. ۳) Day-5 ارسال گروهی پنج مورد به قرارداد. ۴) Day-7 approve/reject/cancel با ReturnPolicy. ۵) Day-12 Support Ticket به Opportunity تبدیل و Case link ساخته شود. ۶) Day-15 Campaign-A cutoff و partial failure worker، retry. ۷) Day-25 عضو تیم revoke، role/cache/notifications revalidate. ۸) Day-30 metrics/rebuild و reconciliations اجرا شوند.
- **Assert نهایی:** هویت همه Subjectها، Counts، Stage durations، Assignment history، Activity، SLA، Campaign outcomes، Outbox/Journal و امنیت Scope پس از هر گام و در انتها.

## E. آزمون‌های مرزی و منفی (REG) — ۸۰ تست مستقل

این تست‌ها به‌صورت مستقل و هر کدام با `Seed → Action → ExpectedState/DB/Event/Permission` پیاده‌سازی شوند. وجود Test Double فقط برای مرز خارجی مجاز است؛ اعتبار مجوز و Transition واقعی باقی بماند.


### Scope/Permissions

| شناسه | وضعیت و عملیات | نتیجه مورد انتظار |
|---|---|---|
| REG-001 | Actor-A از Scope-A یک Task با ID واقعی Scope-B را می‌خواند | get/query/count/facet/timeline برابر deny یا مجموعه خالی؛ بدون side-channel آماری |
| REG-002 | Actor با مجوز فقط List درخواست bulk export کل Project می‌دهد | فقط رکوردهای مجاز؛ دانلود بعد از لغو دسترسی رد |
| REG-003 | Watcher در Task ثبت شده اما `task.view` ندارد | فقط با Watcher بودن دسترسی به متن/پیوست/عدد Aggregate کسب نکند |
| REG-004 | ServiceActor مجاز برای فقط Sales تلاش به transition در Support می‌کند | مجوز با Scope+Capability رد و Audit امنیتی معتبر |
| REG-005 | در لحظه pagination، عضو تیم revoke شود | صفحه بعد Cursor قدیمی داده قبلی محرمانه را نشان ندهد |
| REG-006 | Case به سه Subject از تیم‌های مختلف اشاره دارد | timeline هر Subject per-row مجوز بررسی کند و count محرمانه افشا نکند |
| REG-007 | Comment خصوصی روی یک Ticket در Drawer Opportunity مرتبط ظاهر شود | بدون مجوز Ticket دیده نشود؛ metadata/preview هم مخفی |
| REG-008 | Creator و Owner دو فرد متفاوت هستند | تغییر Owner نیازمند capability اختصاصی؛ Creator به‌تنهایی همه مجوزها را نگرفته باشد |

### Workflow/Stages

| شناسه | وضعیت و عملیات | نتیجه مورد انتظار |
|---|---|---|
| REG-009 | Workflow Definition بعد از استفاده به‌صورت in-place ویرایش شود | immutable revision؛ migration/impact preview اجباری |
| REG-010 | حذف Stage مورد استفاده بدون map انجام شود | رد و عدم orphan Instance/StageVisit |
| REG-011 | Bug فرزند Epic با workflow type-specific باشد | parent در همان Scope/Project مجاز؛ Workflowهای متفاوت معتبر |
| REG-012 | Self-transition برای ثبت note اجرا شود | history مجاز، بدون StageVisit جعلی جدید |
| REG-013 | فرمان تغییر وضعیت با actor منقضی‌شده اجرا شود | رد و عدم ایجاد Outbox transition |
| REG-014 | Stage دارای WIP=1، دو انتقال هم‌زمان دریافت کند | یکی success و دیگری capacity_exceeded بدون COUNT race |
| REG-015 | Pipeline دو Stage برای یک Workflow State داشته باشد | active_stage_key معتبر و انتقال/route صریح لازم |
| REG-016 | تبدیل Terminal به Active بدون یال Reopen باشد | رد؛ completed_at و report historical محفوظ |

### Handoff/Departments

| شناسه | وضعیت و عملیات | نتیجه مورد انتظار |
|---|---|---|
| REG-017 | Delegate به Department مقصد فاقد acceptance policy انجام شود | رد قبل از ساخت Destination Instance |
| REG-018 | همان Handoff با ID یکسان هم‌زمان دو بار اجرا شود | یک Destination Slot، یک Case Link، یک Journal result |
| REG-019 | Handoff Approved سپس Cancelled دیرهنگام برسد | Outcome متناقض رد؛ Origin دوباره transition نکند |
| REG-020 | مبدأ هنگام قرارداد Archived شده باشد | بازگشت خودکار با snapshot stale رد/ manual_resolution |
| REG-021 | Join=any با دو نتیجه یکی success یکی late failed | origin فقط یک‌بار resolve شود؛ Late result ثبت تاریخی مجاز |
| REG-022 | Handoff دایره‌ای بین سه Pipeline بدون policy انجام شود | Cycle/Depth rule انتقال نامعتبر را رد کند |
| REG-023 | Ticket به Opportunity در دو DB با ACK گم‌شده تبدیل شود | یک Opportunity، pending/reconciliation بدون duplicate |
| REG-024 | دپارتمان مقصد داده Support private را query کند | deny حتی اگر عضو Case یا assignee جدید باشد |

### Assignments/Approvals

| شناسه | وضعیت و عملیات | نتیجه مورد انتظار |
|---|---|---|
| REG-025 | دو Primary Assignee هم‌زمان ست شوند | فقط یک primary فعال بر اساس VersionGuard |
| REG-026 | یک Secondary Assignee دو بار اضافه شود | idempotent یا duplicate_assignment، بدون دو ردیف فعال |
| REG-027 | Owner تغییر کند ولی Assignee ثابت باشد | تفکیک roleها در DB/Activity/Permissions حفظ شود |
| REG-028 | Approver دسترسی خودش را در لحظه رأی از دست دهد | رأی جدید رد حتی با Snapshot قدیمی |
| REG-029 | quorum سه‌نفره با دو رأی هم‌زمان و انقضای یکی | snapshot/version و vote policy نتیجه واحد تولید کند |
| REG-030 | یک Approver دو رأی هم‌زمان مخالف بفرستد | یک رأی معتبر یا conflict صریح، نه approve+reject با هم |
| REG-031 | Stage resolver هیچ عضو فعال پیدا نکند | assignment_unresolvable و صف بررسی؛ no silent null |
| REG-032 | Member departures چند Task از دو Scope را تحت تأثیر بگذارد | فقط Scope/Team مجاز و Audit به‌ازای هر Task |

### SLA/Time

| شناسه | وضعیت و عملیات | نتیجه مورد انتظار |
|---|---|---|
| REG-033 | Clock دقیقاً بر deadline_at برابر شود | breached در `now >= deadline` و فقط یک event |
| REG-034 | Stage از قبل خارج شده ولی Job breach با تأخیر اجرا شود | Visit بسته دوباره fail/move نشود |
| REG-035 | دو Watchdog هشدار ۲۰ساعته را اجرا کنند | unique `(visit,revision,event_key)`، پیام و اثر واحد |
| REG-036 | SLA در Pause باشد ولی Worker stale job دریافت کند | Re-evaluate؛ تغییر Stage ممنوع |
| REG-037 | در Reentry policy reset باشد | StageVisit دوم با موعد نو و اولی ثابت |
| REG-038 | مدت زمان قرارداد ۲۰ ساعت طول بکشد ولی origin paused باشد | ساعت مبدأ این مدت را مصرف نکند |
| REG-039 | کاربر منطقه زمان را تغییر دهد | Deadline UTC و تاریخچه ثابت؛ صرفاً نمایش تغییر کند |
| REG-040 | working calendar یک تعطیلی ناگهانی اعلام کند | Recalculate فقط طبق policy و preview؛ audit و نسخه snapshot |

### Campaign/Bulk

| شناسه | وضعیت و عملیات | نتیجه مورد انتظار |
|---|---|---|
| REG-041 | Campaign closure روی ۱۰۰ عضو با سهم ۷۵/۱۵/۱۰ اجرا شود | شمار نتیجه دقیق و هر عضو یک نتیجه |
| REG-042 | رزرو باز شده ولی milestone `reservation_passed` وجود ندارد | به‌صورت پیش‌فرض failed:campaign_ended |
| REG-043 | milestone در تاریخچه قبل Cutoff و Stage فعلی عقب‌تر باشد | اجازه ادامه براساس milestone تاریخی معتبر |
| REG-044 | milestone با input مستقیم client جعل شود | رد؛ فقط Transition/Provider مورد اعتماد |
| REG-045 | Worker در آیتم ۳۷ crash کند و restart شود | ۱۰۰ نتیجه بدون double transition |
| REG-046 | Preview قدیمی با PolicyRevision جدید اجرا شود | preview_stale یا delta/approval صریح |
| REG-047 | CRM Guard روی یک عضو veto کند | manual_review همان عضو و partial_success سایرین |
| REG-048 | کمپین بسته‌شده با run_key یکسان payload دیگر بگیرد | idempotency_key_conflict، no second run |

### Hierarchy/Dependency

| شناسه | وضعیت و عملیات | نتیجه مورد انتظار |
|---|---|---|
| REG-049 | Task خودش Parent خودش شود | task_hierarchy_cycle |
| REG-050 | Reparent والد به فرزند دور انجام شود | cycle guard با DB lock، no partial closure |
| REG-051 | Subtree از Project-A به Project-B بدون مجوز move شود | رد؛ Scope/Workflow/Share/Rank rollback |
| REG-052 | عمق ۸ با subtask نهم ایجاد شود | depth_exceeded بدون تغییر DB |
| REG-053 | A→B→C dependency و تلاش C→A انجام شود | dependency_cycle، حتی در race |
| REG-054 | FS blocked Task با Force Complete غیرمجاز تکمیل شود | guard رد و timeline unchanged |
| REG-055 | چک‌لیست تبدیل به Task شود و Retry گردد | یک Task جدید، Checklist linked و نه duplicate |
| REG-056 | Child حذف نرم شود و والد Rollup بخواهد | Policy شمارش detached/deleted اعمال، velocity double count نشود |

### Scheduling/Recurrence

| شناسه | وضعیت و عملیات | نتیجه مورد انتظار |
|---|---|---|
| REG-057 | RRULE میلادی روز ۳۱ ماهانه باشد | ماه بدون روز ۳۱ با policy مشخص skip/shift، نه تاریخ جعلی |
| REG-058 | تکرار شمسی روز ۳۰ اسفند غیرکبیسه باشد | skip یا last_valid_day طبق calendar_system rule |
| REG-059 | دو occurrence در بازه هم‌پوشان صفحه‌بندی Calendar قرار گیرند | Cursor occurrence-safe، no drop/no duplication |
| REG-060 | تغییر ساعت تابستانی local time ناموجود ایجاد کند | DST policy نسخه‌دار؛ not silent guess |
| REG-061 | رویداد تمام‌روز تا فردا تعیین شود | exclusive end؛ نمایش یک روز بدون off-by-one |
| REG-062 | روز شروع هفته شنبه تنظیم شود | تکرار weekly و working-calendar مطابق شنبه |
| REG-063 | Task قبل Reminder کامل شود | pending notification cancel و worker stale no-op |
| REG-064 | یک occurrence با دو Worker تولید شود | occurrence_id unique و فقط یک Task/Event |

### Enterprise/Reports

| شناسه | وضعیت و عملیات | نتیجه مورد انتظار |
|---|---|---|
| REG-065 | دو Task با عنوان «ي/ی» و «ك/ک» و ارقام فارسی جستجو شوند | normalization correct و sort/filter قابل پیش‌بینی |
| REG-066 | CustomField numeric=2,10 و sort انجام شود | مرتب‌سازی عددی نه حروفی |
| REG-067 | Export CSV شامل `=1+1` باشد | فرمول خنثی و محتوای مجاز intact |
| REG-068 | Webhook به localhost یا private IP resolve شود | SSRF guard reject حتی بعد DNS rebinding/redirect |
| REG-069 | Webhook replay با امضای معتبر اما timestamp قدیمی برسد | replay reject و هیچ side effect |
| REG-070 | Automation A↔B سبب حلقه شود | bounded recursion/idempotency، no infinite worker |
| REG-071 | Sprint پس از close مجدداً قیمت/points تغییر کند | snapshot historical بدون دستکاری |
| REG-072 | Rebuild metrics از Activity و Transition history اجرا شود | مقادیر برابر با baseline و totals authorization-aware |

### Integrations/Upgrade

| شناسه | وضعیت و عملیات | نتیجه مورد انتظار |
|---|---|---|
| REG-073 | TaskManager فقط بدون Kanban/Calendar نصب شود | Facade/CRUD/Workflow/Queue via in-process کار کند |
| REG-074 | Kanban بدون TaskManager با Order source نصب شود | Kanban مستقل و بدون نیاز به task tables |
| REG-075 | Calendar بدون TaskManager با Event source نصب شود | Calendar مستقل و بدون نیاز به task tables |
| REG-076 | هر سه پکیج همراه CRM نصب شوند | یک Data Truth و انتقال بدون double handler |
| REG-077 | Drag کارت با version قدیمی انجام شود | conflict/rollback UI و no stale update |
| REG-078 | Calendar due رویداد Read-only SLA drag شود | رد و بدون Mutation Date |
| REG-079 | CRM migration از Stage قدیمی با History ناقص انجام شود | unknown provenance، عدم جعل مدت زمان تاریخی |
| REG-080 | Upgrade از release candidate با migration fail شود | rollback/reconciliation امن، عدم پاک‌شدن Task/Audit/Case |


## F. ماتریس اجرای آزمون روی محیط‌ها

| Suite | SQLite | MySQL 8+ | PostgreSQL | Host Integration |
|---|---|---|---|---|
| Unit/DTO/Validation/Facade | اجباری | Smoke | Smoke | — |
| Feature/Permissions/Query/Hierarchy | اجباری | اجباری | اجباری | Sample host |
| Locking/Concurrent WIP/Idempotency | کافی نیست | **اجباری** | **اجباری** | — |
| SLA/Recurrence/DST/Jalali | Clock Fake | **اجباری** | **اجباری** | Calendar |
| Handoff/Saga/Campaign Worker Chaos | فقط Unit | **اجباری** | **اجباری** | CRM/Ticket/Contracts |
| UI Bridge / Drawer / Drag | — | اجباری | اجباری | **Browser host** |
| Migration/Upgrade/Rollback | حداقل | **اجباری** | **اجباری** | CRM app |
| Benchmarks 10k/100k | — | **اجباری** | **اجباری** | Load profile |
| Webhook SSRF/Signatures/Export | Mock unit | اجباری | اجباری | Webhook receiver |

## G. ماتریس تزریق خطا

| محل خطا | انتظار |
|---|---|
| قبل از شروع DB transaction | هیچ تغییر و event |
| در میانه تغییر چند جدول و قبل commit | Rollback کامل، no ghost StageVisit/Approval |
| بلافاصله بعد commit و قبل dispatch | Outbox برای recovery موجود |
| پس از دریافت پیام قبل ACK | idempotent consumer، عدم تکرار اثر |
| پس از Claim عضوی از Campaign و قبل Action | lease recovery بدون skip |
| پس از commit Action و قبل mark item result | journal/idempotency اجازه replay دوگانه ندهد |
| پس از ایجاد Opportunity در CRM و قبل ACK به TaskManager | correlation + business operation key همان Opportunity را بازیابی کند |
| در میانه تغییر Workflow Revision/Project/Subtree | atomic map یا rollback کامل |
| پس از revoke دسترسی و قبل Export/Download | دوباره بررسی permission، no leak |
| بروز خطای Webhook/SMTP/Push | اصل Transition rollback نشود؛ وضعیت Delivery و retry قابل بررسی |

## H. قواعد نهایی قبولی سناریوهای چندمرحله‌ای

- برای هر NET: حداقل **یک Assert پس از هر گام**، ازجمله State، DB count/version، Activity، Journal، Outbox، و Permission در جای حساس.
- برای NET-010 و NET-037: انتظار کمی شامل `failed/continued/already_terminal/manual_review` و مجموع دقیق ثبت؛ Item exceptionها نباید از جمع ناپدید شوند.
- برای NET-015/017: رابطه Case/Subject/Instance ثابت؛ قوانین تجاری CRM و قرارداد به Handler میزبان واگذار شوند؛ تست Fake CRM باید Contract-realistic باشد و در فاز ۶ با Host واقعی تکرار شود.
- برای NET-039: `Kanban-only`, `Calendar-only`, `TaskManager-only`, `all-three` به‌صورت **چهار نصب واقعی مجزا** اجرا شوند.
- برای NET-040: در طول ۳۰ روز شبیه‌سازی، پس از هر روز/عملیات Assertions اساسی تکرار شوند، نه فقط در روز ۳۰.
- برای تمام REGها: Failure باید `typed domain code` و عدم side-effect غیرمجاز داشته باشد. Logger نباید secrets یا PII غیرمجاز را ثبت کند.
- خروجی نهایی: `test-name`, `requirements`, `db vendor`, `php/laravel`, `seed`, `expected/observed`, `time`, `SQL count`, `logs`, `file/line`, `defect link`, `pass/fail/skip-reason`.

## I. پوشش، شمارش و ردیابی

- **۲۳۶ تست** baseline `TM-001..236` از فصل ۳۱ PRD عیناً حفظ شده‌اند.
- **۶ سناریوی بزرگ** `E2E-01..06` از فصل ۲۹ PRD عیناً حفظ شده‌اند.
- **۴۰ سناریوی چندمرحله‌ای جدید** `NET-001..040` برای اثبات ارتباط و چرخه کامل.
- **۸۰ آزمون مرزی و منفی جدید** `REG-001..080` برای فساد داده، امنیت، تاریخ و صف.
- جمع **۳۶۲ شناسه آزمون/سناریو** (۲۳۶ + ۶ + ۴۰ + ۸۰). این عدد **تعداد قراردادهای آزمون** است، نه تعداد PHPUnit method یا assertion؛ هر سناریو ممکن است چند Test/Assertion داشته باشد.
- **ترتیب اجرا در فاز ۷:** Baseline TM → NET و E2E → REG → Chaos → Load → DB-matrix → Host-real integration → full rerun → Go/No-Go.
- قبل از Go باید ماتریس `FR-01..25 → TM/E2E/NET/REG → فایل تست → آخرین گزارش اجرا` قابل تولید باشد؛ هر FR بدون تست یا هر تست Skip بدون justification مانع Go است.

## J. ماتریس اختصاص مالک فاز برای هر یک از ۲۳۶ سناریوی اصلی

این جدول به‌صورت ماشینی اعتبارسنجی شده است؛ هیچ TM بدون مالک یا با مالک تکراری ندارد. اجرای همه این موارد در فاز ۷ الزامی است؛ فاز مالک زمانی است که Test کامل برای اولین بار باید Pass شود.

| شناسه | فاز مالک | گروه الزامی |
|---|---:|---|
| TM-001 | 1 | Foundation/Todo |
| TM-002 | 1 | Foundation/Todo |
| TM-003 | 1 | Foundation/Todo |
| TM-004 | 6 | CRM/UI Adapters |
| TM-005 | 1 | Foundation/Todo |
| TM-006 | 1 | Foundation/Todo |
| TM-007 | 1 | Foundation/Todo |
| TM-008 | 1 | Foundation/Todo |
| TM-009 | 1 | Foundation/Todo |
| TM-010 | 2 | Workflow/External Subject |
| TM-011 | 1 | Foundation/Todo |
| TM-012 | 1 | Foundation/Todo |
| TM-013 | 4 | Project/Hierarchy/Agile |
| TM-014 | 2 | Workflow/External Subject |
| TM-015 | 2 | Workflow/External Subject |
| TM-016 | 2 | Workflow/External Subject |
| TM-017 | 2 | Workflow/External Subject |
| TM-018 | 2 | Workflow/External Subject |
| TM-019 | 2 | Workflow/External Subject |
| TM-020 | 2 | Workflow/External Subject |
| TM-021 | 2 | Workflow/External Subject |
| TM-022 | 2 | Workflow/External Subject |
| TM-023 | 2 | Workflow/External Subject |
| TM-024 | 3 | SLA/Handoff/Campaign |
| TM-025 | 2 | Workflow/External Subject |
| TM-026 | 2 | Workflow/External Subject |
| TM-027 | 4 | Project/Hierarchy/Agile |
| TM-028 | 4 | Project/Hierarchy/Agile |
| TM-029 | 4 | Project/Hierarchy/Agile |
| TM-030 | 4 | Project/Hierarchy/Agile |
| TM-031 | 4 | Project/Hierarchy/Agile |
| TM-032 | 4 | Project/Hierarchy/Agile |
| TM-033 | 4 | Project/Hierarchy/Agile |
| TM-034 | 4 | Project/Hierarchy/Agile |
| TM-035 | 4 | Project/Hierarchy/Agile |
| TM-036 | 4 | Project/Hierarchy/Agile |
| TM-037 | 4 | Project/Hierarchy/Agile |
| TM-038 | 4 | Project/Hierarchy/Agile |
| TM-039 | 4 | Project/Hierarchy/Agile |
| TM-040 | 4 | Project/Hierarchy/Agile |
| TM-041 | 4 | Project/Hierarchy/Agile |
| TM-042 | 4 | Project/Hierarchy/Agile |
| TM-043 | 4 | Project/Hierarchy/Agile |
| TM-044 | 4 | Project/Hierarchy/Agile |
| TM-045 | 4 | Project/Hierarchy/Agile |
| TM-046 | 4 | Project/Hierarchy/Agile |
| TM-047 | 4 | Project/Hierarchy/Agile |
| TM-048 | 4 | Project/Hierarchy/Agile |
| TM-049 | 4 | Project/Hierarchy/Agile |
| TM-050 | 4 | Project/Hierarchy/Agile |
| TM-051 | 5 | Scheduling/Enterprise |
| TM-052 | 5 | Scheduling/Enterprise |
| TM-053 | 5 | Scheduling/Enterprise |
| TM-054 | 5 | Scheduling/Enterprise |
| TM-055 | 5 | Scheduling/Enterprise |
| TM-056 | 5 | Scheduling/Enterprise |
| TM-057 | 5 | Scheduling/Enterprise |
| TM-058 | 5 | Scheduling/Enterprise |
| TM-059 | 5 | Scheduling/Enterprise |
| TM-060 | 5 | Scheduling/Enterprise |
| TM-061 | 5 | Scheduling/Enterprise |
| TM-062 | 5 | Scheduling/Enterprise |
| TM-063 | 5 | Scheduling/Enterprise |
| TM-064 | 5 | Scheduling/Enterprise |
| TM-065 | 4 | Project/Hierarchy/Agile |
| TM-066 | 4 | Project/Hierarchy/Agile |
| TM-067 | 4 | Project/Hierarchy/Agile |
| TM-068 | 4 | Project/Hierarchy/Agile |
| TM-069 | 4 | Project/Hierarchy/Agile |
| TM-070 | 4 | Project/Hierarchy/Agile |
| TM-071 | 4 | Project/Hierarchy/Agile |
| TM-072 | 4 | Project/Hierarchy/Agile |
| TM-073 | 1 | Foundation/Todo |
| TM-074 | 4 | Project/Hierarchy/Agile |
| TM-075 | 4 | Project/Hierarchy/Agile |
| TM-076 | 1 | Foundation/Todo |
| TM-077 | 2 | Workflow/External Subject |
| TM-078 | 1 | Foundation/Todo |
| TM-079 | 1 | Foundation/Todo |
| TM-080 | 1 | Foundation/Todo |
| TM-081 | 6 | CRM/UI Adapters |
| TM-082 | 6 | CRM/UI Adapters |
| TM-083 | 6 | CRM/UI Adapters |
| TM-084 | 6 | CRM/UI Adapters |
| TM-085 | 6 | CRM/UI Adapters |
| TM-086 | 6 | CRM/UI Adapters |
| TM-087 | 6 | CRM/UI Adapters |
| TM-088 | 6 | CRM/UI Adapters |
| TM-089 | 5 | Scheduling/Enterprise |
| TM-090 | 4 | Project/Hierarchy/Agile |
| TM-091 | 1 | Foundation/Todo |
| TM-092 | 1 | Foundation/Todo |
| TM-093 | 5 | Scheduling/Enterprise |
| TM-094 | 5 | Scheduling/Enterprise |
| TM-095 | 1 | Foundation/Todo |
| TM-096 | 2 | Workflow/External Subject |
| TM-097 | 2 | Workflow/External Subject |
| TM-098 | 1 | Foundation/Todo |
| TM-099 | 1 | Foundation/Todo |
| TM-100 | 2 | Workflow/External Subject |
| TM-101 | 4 | Project/Hierarchy/Agile |
| TM-102 | 4 | Project/Hierarchy/Agile |
| TM-103 | 4 | Project/Hierarchy/Agile |
| TM-104 | 4 | Project/Hierarchy/Agile |
| TM-105 | 4 | Project/Hierarchy/Agile |
| TM-106 | 4 | Project/Hierarchy/Agile |
| TM-107 | 4 | Project/Hierarchy/Agile |
| TM-108 | 4 | Project/Hierarchy/Agile |
| TM-109 | 4 | Project/Hierarchy/Agile |
| TM-110 | 4 | Project/Hierarchy/Agile |
| TM-111 | 4 | Project/Hierarchy/Agile |
| TM-112 | 5 | Scheduling/Enterprise |
| TM-113 | 5 | Scheduling/Enterprise |
| TM-114 | 5 | Scheduling/Enterprise |
| TM-115 | 5 | Scheduling/Enterprise |
| TM-116 | 5 | Scheduling/Enterprise |
| TM-117 | 5 | Scheduling/Enterprise |
| TM-118 | 5 | Scheduling/Enterprise |
| TM-119 | 5 | Scheduling/Enterprise |
| TM-120 | 5 | Scheduling/Enterprise |
| TM-121 | 5 | Scheduling/Enterprise |
| TM-122 | 4 | Project/Hierarchy/Agile |
| TM-123 | 4 | Project/Hierarchy/Agile |
| TM-124 | 4 | Project/Hierarchy/Agile |
| TM-125 | 4 | Project/Hierarchy/Agile |
| TM-126 | 1 | Foundation/Todo |
| TM-127 | 2 | Workflow/External Subject |
| TM-128 | 1 | Foundation/Todo |
| TM-129 | 1 | Foundation/Todo |
| TM-130 | 6 | CRM/UI Adapters |
| TM-131 | 6 | CRM/UI Adapters |
| TM-132 | 6 | CRM/UI Adapters |
| TM-133 | 5 | Scheduling/Enterprise |
| TM-134 | 5 | Scheduling/Enterprise |
| TM-135 | 5 | Scheduling/Enterprise |
| TM-136 | 5 | Scheduling/Enterprise |
| TM-137 | 5 | Scheduling/Enterprise |
| TM-138 | 5 | Scheduling/Enterprise |
| TM-139 | 5 | Scheduling/Enterprise |
| TM-140 | 5 | Scheduling/Enterprise |
| TM-141 | 5 | Scheduling/Enterprise |
| TM-142 | 5 | Scheduling/Enterprise |
| TM-143 | 6 | CRM/UI Adapters |
| TM-144 | 6 | CRM/UI Adapters |
| TM-145 | 6 | CRM/UI Adapters |
| TM-146 | 3 | SLA/Handoff/Campaign |
| TM-147 | 4 | Project/Hierarchy/Agile |
| TM-148 | 2 | Workflow/External Subject |
| TM-149 | 2 | Workflow/External Subject |
| TM-150 | 2 | Workflow/External Subject |
| TM-151 | 2 | Workflow/External Subject |
| TM-152 | 2 | Workflow/External Subject |
| TM-153 | 1 | Foundation/Todo |
| TM-154 | 2 | Workflow/External Subject |
| TM-155 | 2 | Workflow/External Subject |
| TM-156 | 2 | Workflow/External Subject |
| TM-157 | 2 | Workflow/External Subject |
| TM-158 | 2 | Workflow/External Subject |
| TM-159 | 2 | Workflow/External Subject |
| TM-160 | 2 | Workflow/External Subject |
| TM-161 | 2 | Workflow/External Subject |
| TM-162 | 2 | Workflow/External Subject |
| TM-163 | 2 | Workflow/External Subject |
| TM-164 | 2 | Workflow/External Subject |
| TM-165 | 2 | Workflow/External Subject |
| TM-166 | 3 | SLA/Handoff/Campaign |
| TM-167 | 3 | SLA/Handoff/Campaign |
| TM-168 | 3 | SLA/Handoff/Campaign |
| TM-169 | 3 | SLA/Handoff/Campaign |
| TM-170 | 3 | SLA/Handoff/Campaign |
| TM-171 | 3 | SLA/Handoff/Campaign |
| TM-172 | 3 | SLA/Handoff/Campaign |
| TM-173 | 3 | SLA/Handoff/Campaign |
| TM-174 | 3 | SLA/Handoff/Campaign |
| TM-175 | 3 | SLA/Handoff/Campaign |
| TM-176 | 3 | SLA/Handoff/Campaign |
| TM-177 | 3 | SLA/Handoff/Campaign |
| TM-178 | 3 | SLA/Handoff/Campaign |
| TM-179 | 3 | SLA/Handoff/Campaign |
| TM-180 | 3 | SLA/Handoff/Campaign |
| TM-181 | 3 | SLA/Handoff/Campaign |
| TM-182 | 3 | SLA/Handoff/Campaign |
| TM-183 | 3 | SLA/Handoff/Campaign |
| TM-184 | 3 | SLA/Handoff/Campaign |
| TM-185 | 3 | SLA/Handoff/Campaign |
| TM-186 | 3 | SLA/Handoff/Campaign |
| TM-187 | 3 | SLA/Handoff/Campaign |
| TM-188 | 3 | SLA/Handoff/Campaign |
| TM-189 | 3 | SLA/Handoff/Campaign |
| TM-190 | 3 | SLA/Handoff/Campaign |
| TM-191 | 3 | SLA/Handoff/Campaign |
| TM-192 | 3 | SLA/Handoff/Campaign |
| TM-193 | 3 | SLA/Handoff/Campaign |
| TM-194 | 3 | SLA/Handoff/Campaign |
| TM-195 | 3 | SLA/Handoff/Campaign |
| TM-196 | 3 | SLA/Handoff/Campaign |
| TM-197 | 3 | SLA/Handoff/Campaign |
| TM-198 | 3 | SLA/Handoff/Campaign |
| TM-199 | 3 | SLA/Handoff/Campaign |
| TM-200 | 3 | SLA/Handoff/Campaign |
| TM-201 | 3 | SLA/Handoff/Campaign |
| TM-202 | 3 | SLA/Handoff/Campaign |
| TM-203 | 3 | SLA/Handoff/Campaign |
| TM-204 | 3 | SLA/Handoff/Campaign |
| TM-205 | 2 | Workflow/External Subject |
| TM-206 | 3 | SLA/Handoff/Campaign |
| TM-207 | 2 | Workflow/External Subject |
| TM-208 | 1 | Foundation/Todo |
| TM-209 | 6 | CRM/UI Adapters |
| TM-210 | 6 | CRM/UI Adapters |
| TM-211 | 6 | CRM/UI Adapters |
| TM-212 | 2 | Workflow/External Subject |
| TM-213 | 6 | CRM/UI Adapters |
| TM-214 | 5 | Scheduling/Enterprise |
| TM-215 | 5 | Scheduling/Enterprise |
| TM-216 | 5 | Scheduling/Enterprise |
| TM-217 | 5 | Scheduling/Enterprise |
| TM-218 | 5 | Scheduling/Enterprise |
| TM-219 | 6 | CRM/UI Adapters |
| TM-220 | 6 | CRM/UI Adapters |
| TM-221 | 3 | SLA/Handoff/Campaign |
| TM-222 | 3 | SLA/Handoff/Campaign |
| TM-223 | 3 | SLA/Handoff/Campaign |
| TM-224 | 3 | SLA/Handoff/Campaign |
| TM-225 | 3 | SLA/Handoff/Campaign |
| TM-226 | 3 | SLA/Handoff/Campaign |
| TM-227 | 3 | SLA/Handoff/Campaign |
| TM-228 | 3 | SLA/Handoff/Campaign |
| TM-229 | 3 | SLA/Handoff/Campaign |
| TM-230 | 3 | SLA/Handoff/Campaign |
| TM-231 | 3 | SLA/Handoff/Campaign |
| TM-232 | 3 | SLA/Handoff/Campaign |
| TM-233 | 3 | SLA/Handoff/Campaign |
| TM-234 | 3 | SLA/Handoff/Campaign |
| TM-235 | 3 | SLA/Handoff/Campaign |
| TM-236 | 3 | SLA/Handoff/Campaign |
