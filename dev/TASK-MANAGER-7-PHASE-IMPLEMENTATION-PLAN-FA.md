# برنامه اجرایی هفت‌فازی Karnoweb Task Manager

**نسخه برنامه:** 1.0 — ۲۰ اکتبر ۲۰۲۶  
**سند حاکم:** `KARNOWEB-TASK-MANAGER-ENTERPRISE-PRD-v2.0-FA.md`  
**نوع خروجی:** یک پکیج Laravel مستقل و Headless، بدون وابستگی اجباری به Livewire، Kanban، Calendar یا CRM  
**اصل زمان‌بندی:** **۶ فاز ساخت + فاز هفتم فقط آزمون، بازتولید خطا و گزارش Go/No-Go.** هر فاز ساخت دارای تست‌های واحد و قراردادی خودش است؛ در فاز هفت حتی رفع باگ در کد محصول انجام نمی‌شود و نقص به فاز مالک بازگردانده خواهد شد.

> **دامنه:** این برنامه تمام قابلیت‌های مطرح‌شده در PRD v2.0 را برای «Enterprise Complete» پوشش می‌دهد؛ تقسیم فازها به معنی حذف/انتقال خاموش امکانات به انتشار بعدی نیست. هر Feature خارج از سند یا تغییری در دامنه نیازمند ثبت ADR، تأیید مالک محصول و به‌روزرسانی تست است.

## ۱. چرا هفت فاز؟

چهار یا پنج فاز برای چنین هسته‌ای، به‌خصوص با سه مسیر متفاوتِ `Task`، `External Subject` و `Multi-process Case`، هر مرحله را بیش‌ازحد بزرگ می‌کند. بیش از هفت فاز نیز هزینه هماهنگی Agent و خطر تغییر قراردادهای بین‌فازی را افزایش می‌دهد. هفت فاز، امکان تحویل قابل راستی‌آزمایی و CRM-first را می‌دهد:

| فاز | موضوع اصلی | محصول پایان فاز | قابل انتشار عمومی؟ |
|---|---|---|---|
| **۱** | Foundation، Security، Todo | یک Todo ایمن با Facade ساده و Context صریح | خیر؛ داخلی |
| **۲** | Workflow، Pipeline، Subject خارجی | موتور وضعیت و فرآیند CRM مستقل از UI | خیر؛ داخلی |
| **۳** | SLA، Handoff، Assignment، Campaign | سناریوهای کلیدی CRM، قرارداد و تیکت قابل اجرا | خیر؛ داخلی |
| **۴** | پروژه و مدیریت کار پیشرفته | زیرتسک، وابستگی، Sprint، فعالیت و زمان کار | خیر؛ داخلی |
| **۵** | Scheduling و Enterprise Features | Recurrence، Automation، Webhook، Export، گزارش‌های قابل دفاع | خیر؛ داخلی |
| **۶** | Adapterها، مهاجرت و مستندات | اتصال واقعی CRM/Kanban/Calendar، API تثبیت‌شده | فقط Release Candidate داخلی |
| **۷** | **فقط QA، Chaos، Security، Performance، Regression** | اجرای کامل آزمون‌ها و گزارش Go/No-Go، بدون اصلاح کد محصول | پس از تأیید Gate |

## ۲. مقررات تغییرناپذیر برای AI Agent

1. **پیش از هر ویرایش:** سورس واقعی مخزن، Composer، Tests، Docker/CI و دو پکیج نمایش در نسخه قفل‌شده را بخوان؛ هیچ کلاس یا امضای متد را صرفاً از نام‌های مفهومی PRD فرض نکن.
2. **Dependency ها:** `task-manager` مستقل نصب می‌شود؛ `kanban-livewire` و `laravel-calendar` فقط dev/test یا پیشنهاد اختیاری. CRM نیز Adapter میزبان است، نه Dependency هسته.
3. **همه داده‌های تجاری مالک مشخص دارند:** Lead/Opportunity/Campaign/Contract/Ticket/Order متعلق به میزبان‌اند؛ Task، WorkflowDefinition/Instance، Pipeline، StageVisit، Case و Handoff متعلق به Task Manager.
4. **Facade راه ورودی ساده، Service/DTO راه حرفه‌ای:** facade بدون mutable global Context. Actor، Scope، Correlation، OperationID و Version در عملیات حساس صریح‌اند یا فقط در Resolver لبه با Fail-closed resolve می‌شوند.
5. **امنیت Query-level:** هر `list/count/facet/aggregate/export` باید Scope و Capability را پیش از pagination اعمال کند؛ هیچ post-filter امنیتی پذیرفته نیست.
6. **هر Write استاندارد:** scoped guard، expected_version، operation_id + hash payload، transaction، ordered locks، audit، command journal و mandatory outbox. رویداد فقط after-commit deliver می‌شود.
7. **هر زمان UTC+IANA:** بازه نیمه‌باز، قواعد DST، تکرار شمسی/میلادی و WorkCalendar قابل پیکربندی؛ از `sleep()` در تست اجتناب، Clock جعلی تزریق شود.
8. **Board حالت تجاری نیست:** وجود در دو Board، دو رکورد یا دو وضعیت موازی نمی‌سازد؛ فرآیند دوم تنها با Slot مستقل و `handoff/parallel` معتبر است.
9. **تست‌های مرحله‌ای اجباری‌اند** اما فقط قابلیت‌های همان فاز. فاز ۷ مجاز نیست اولین جایی باشد که PHPUnit نوشته یا اجرا می‌شود.
10. **پایان فاز مستند:** فایل‌های تغییرکرده، schema diff، APIهای عمومی، تست‌های Pass/Fail/Skipped، Risks/ADR، Migration وضعیت، شناسه commit، دستور بازتولید و DoD باید تحویل شود. هیچ ادعای سبز بدون log واقعی.
11. **امنیت داده‌های میزبان:** هر External Subject باید شناسه morph-map پایدار، Provider/Handler، Scope و query-level permission داشته باشد. Actor سیستم نیز محدود به Scope و Capability است.
12. **سناریوهای شکست بخشی از Feature هستند:** Retry، Worker Crash، duplicate event، تقابل نسخه، مجوز revocation، حذف Source و rebuild projection از ابتدا طراحی شوند.

## ۳. قرارداد فنی قبل از فاز اول (Architecture Spike کوتاه؛ بخشی از فاز ۱)

- نسخه‌های واقعی PHP/Laravel، MySQL/PostgreSQL، namespace، پکیج‌نام و ServiceProvider را مستند کن. برای تست SQLite و DBهای production profile جدا ایجاد کن.
- سورس واقعی پکیج‌های `kanban-livewire` و `laravel-calendar` را بخوان و Contract Map دقیق Provider/ActionHandler/DTO/Event و Drawer Section تدوین کن. اصلاح امنیت Kanban و صفحه‌بندی occurrence در Calendar را به عنوان پیش‌شرط تست یکپارچه در نظر بگیر؛ **در مخزن Task Manager آن دو را بی‌اجازه اصلاح نکن**.
- اگر سورس حسابداری/انبارداری در اختیار قرار گرفت، الگوی واقعی facade/service/builder/DTO/config را extract و تصمیم نام‌گذاری ثبت کن؛ بدون سورس، شباهت فرضی را پیاده‌سازی قطعی تلقی نکن.
- ADRهای غیرقابل تعویق: Scope Representation، Subject Aliases، Active-Slot uniqueness، Revision/Pipeline map، Lock order، Closure Table، Rank، Outbox و Time Clock. **برای Cutoff کمپین، پیش‌فرض `milestone.committed_at <= cutoff_at` است** و باید با تیم تصویب و در قرارداد زمان DB قفل شود.
- هدف کارایی و اندازه تیم را Benchmark واقعی مشخص می‌کند؛ زمان تحویل و اعداد p95 را بدون داده ادعای تضمین نکن.

---

## فاز ۱ — هسته مستقل، Facade، Security و Todo

### ساخت

- ساخت package skeleton، composer metadata، ServiceProvider، config و migrations؛ تست fresh install بدون CRM/UI.
- Context DTO و resolvers برای Actor/Scope/ServiceActor؛ no ambient state leakage در CLI/Jobs.
- Identifiers پایدار: `public_key`, `display_key`, aliases، operation id، correlation id، normalized search؛ portable uniqueness بین MySQL/Postgres/SQLite.
- `tm_tasks`, `tm_lists`، مالک Todo، Inbox lazy provision، shares/grants، basic personal workflow/instance؛ Create/Update/Complete/Reopen/Archive/Restore/SoftDelete/Clone/Convert-to-project فقط تا حد قرارداد پایه.
- پایه Audit/CommandJournal/Outbox و dispatcher in-process؛ نسخه optimistic و typed domain errors.
- Query/Count/Facet scoped، pagination پایدار، capability catalog/permission provider؛ Policy roleهای پایه و mutation guard.
- facade chain ساده با service typed؛ no static state و no mutable global singletons.

### خروجی قابل راستی‌آزمایی

Actor A تنها با Title یک Todo می‌سازد و با Facade تکمیل می‌کند؛ Actor B از Scope دیگر نه Task، نه List، نه Count و نه Activity را می‌بیند. Retry create نتیجه همان operation را برمی‌گرداند؛ payload متفاوت conflict قطعی می‌گیرد. بدون Queue/Calendar/Kanban کار می‌کند.

### Gate فاز ۱

**Gate مالکیت دقیق سناریوها (25 مورد):** TM-001..003, TM-005..009, TM-011..012, TM-073, TM-076, TM-078..080, TM-091..092, TM-095, TM-098..099, TM-126, TM-128..129, TM-153, TM-208. این موارد باید قبل از عبور از فاز، کامل Pass شوند؛ سناریوهای سایر فازها فقط Smoke/Contract در حد قابلیت‌های موجود هستند.
- تست migrations/rollback، Facade parity، عدم نشت Scope در Job و authorization-count.
- شواهد: اجرای بدون UI و fresh install روی SQLite + MySQL/Postgres test profile حداقل smoke؛ security unit/feature green.

## فاز ۲ — Workflow، Pipeline، Project پایه و External Subject

### ساخت

- `WorkflowDefinition`, immutable revision، State، Transition، Condition/Guard، required field/reason، terminal/reopen؛ mapping `project × task type → workflow`.
- Project/Team membership، project roles، Workflow role matrix، type presets؛ State فعلی فقط `WorkflowInstance` و نه دومین status مستقل روی CRM.
- `Pipeline`، Stage و versioned stage-to-state mapping، WIP capacity، StageVisit، stage-aging تاریخچه پایه و Rank context محفوظ.
- External Subject Registry/Handler، alias/morph map پایدار، permission در read+write، hook سرویس CRM، Active Slot invariant و `ProcessCase` پایه.
- `moveInstanceToPipeline` و تفاوت آن با `changeBoardVisibility`؛ انتقال با preview اثر، Department acceptance و mapping نسخه‌ها.
- owner/primary assignee، Activity مربوط به transition و department، facades `workflows/pipelines/instances/projects` مطابق قرارداد قفل‌شده.

### خروجی قابل راستی‌آزمایی

یک Lead از CRM mock، بدون کپی به جدول Tasks، در Workflow فروش جلو می‌رود و تغییر stage تنها از Task Manager + guard CRM تأیید می‌شود؛ یک Bug با Workflow مستقل زیر Epic مجاز است؛ تغییر Stage روی بردِ نمایشی بدون فرمان فرآیندی status را تغییر نمی‌دهد.

### Gate فاز ۲

**Gate مالکیت دقیق سناریوها (38 مورد):** TM-010, TM-014..023, TM-025..026, TM-077, TM-096..097, TM-100, TM-127, TM-148..152, TM-154..165, TM-205, TM-207, TM-212. این موارد باید قبل از عبور از فاز، کامل Pass شوند؛ سناریوهای سایر فازها فقط Smoke/Contract در حد قابلیت‌های موجود هستند.
- تست انتقال هم‌زمان به WIP=1، workflow revision migration، StageVisit دقیق، revocation فیلترهای List/Count/Timeline؛ CRM guard veto rollback کامل.
## فاز ۳ — سناریوهای حیاتی CRM: SLA، ارجاع، مسئولیت، پایان کمپین

### ساخت

- Stage SLA / WorkingCalendar / breach-warning-stale-dead_candidate / pause-resume/reset / watchdog indexed / scheduler clock.
- سیاست مسئول ثابت و پویا، multiple assignees، team/department resolver، sequential/parallel/quorum approvers، تاریخچه و member departure.
- Handoff modes: `move`, `delegate`, `spawn`, `parallel`، ReturnPolicy versioned و Outcome matrix (`approved/rejected/cancelled/timed_out/recalled/manual_resolution`).
- Case Timeline، correlation و Process Milestones؛ multi-instance slot isolation، Join `all/any/quorum` و snapshots.
- Group/Campaign Closure مستقل از CRM، enrollment/cutoff، immutable policy، preview-run-delta، chunk claim/lease، idempotency، worker restart، conditional continuation پس از milestone رزرو.
- External coordination: transactional same-DB، pending Saga+compensation برای سرویس بیرونی؛ عدم ادعای atomic cross-DB.

### خروجی قابل راستی‌آزمایی

- کمپین ۱۰۰ کار: ۷۵ failed با دلیل `campaign_ended`، ۱۵ continued (شامل ۵ قرارداد delegated) و ۱۰ already_terminal؛ اعداد پس از Retry همان بمانند.
- فروش→قرارداد با سه Approver→بازگشت بر مبنای `approved/rejected/cancelled` و restore مالک/مسئول و SLA.
- تیکت پشتیبانی→فروش به صورت move همان Ticket یا spawn یک Opportunity **جدید** در CRM؛ داده محرمانه Ticket در فروش نشت نکند.

### Gate فاز ۳

**Gate مالکیت دقیق سناریوها (58 مورد):** TM-024, TM-146, TM-166..204, TM-206, TM-221..236. این موارد باید قبل از عبور از فاز، کامل Pass شوند؛ سناریوهای سایر فازها فقط Smoke/Contract در حد قابلیت‌های موجود هستند.
- سناریوهای نادر: دو Worker SLA، notification duplicate، cut-off edge برابر deadline، In-flight transition، Case loop، join cancellation، multi-approver duplicate votes و invalid department.
## فاز ۴ — مدیریت کامل تسک و پروژه

### ساخت

- Parent/Subtask و closure table با depth و cycle guard؛ reparent subtree و move project؛ policy حذف والد و leaf-weighted rollup.
- Dependency FS/FF/SS/SF، lag، business-calendar constraints و impact preview؛ Cycle prevention در race؛ blocked states vs terminal.
- Checklist، comment/reply/mentions، file attachment contracts و permission revocation، watchers؛ soft delete/retention/restore.
- Project templates، backlog/sprint/release/milestone، Story/Bug/Epic، positions با Rank نسخه‌دار، story points snapshot و بدون double-counting.
- Time tracking timer/start/stop، overlap guard، manual entries، rollups/projections، status transition history و Sprint snapshots؛ گزارش‌های Lead/Cycle/Velocity/Burndown قابل محاسبه.

### خروجی قابل راستی‌آزمایی

کاربر یک پروژه ۱۰۰ تسکی با Epic، Bug با Workflow متفاوت، hierarchy عمق ۸، dependency و sprint می‌سازد؛ double count رخ نمی‌دهد؛ Reparent و reorder هم‌زمان داده را خراب نمی‌کنند؛ Activity و time report از منبع حقیقت بازیابی می‌شوند.

### Gate فاز ۴

**Gate مالکیت دقیق سناریوها (52 مورد):** TM-013, TM-027..050, TM-065..072, TM-074..075, TM-090, TM-101..111, TM-122..125, TM-147. این موارد باید قبل از عبور از فاز، کامل Pass شوند؛ سناریوهای سایر فازها فقط Smoke/Contract در حد قابلیت‌های موجود هستند.
- همچنین query tree, rank, history, permissions و race باید اجرا شوند.
## فاز ۵ — Scheduling، Enterprise و Integration Events

### ساخت

- تاریخ‌ها، UTC/IANA، inclusive/exclusive، all-day و timezone/DST؛ Gregorian RFC5545 + recurrence شمسی مستقل (`calendar_system`)، Saturday start، working weekends/holidays.
- Reminders، snooze/quiet-hour/invalidations، scheduler with idempotency و Clock interface.
- Typed Custom Fields و multi-select indexable، presets/Templates، search normalization فارسی/عربی و ارقام، sort/filter via DB.
- Automation rule engine امن (whitelist actions، versioned condition، recursion guard، max depth/rate)، Outbox retries، notifications adapter.
- Webhook HTTPS HMAC + timestamp/replay/SSRF protections، export CSV/JSON stream و Excel optional، Async export revocation، projected reporting/rebuild و آزمون مکمل Critical Path طبق قرارداد PRD.
- Configuration reference، extension hooks، project/Process reports، SLA and group metrics. قابلیت Critical Path که به Dependency و زمان کاری وابسته است در فاز ۴ توسعه می‌یابد؛ فاز ۵ فقط تست ترکیب آن با WorkCalendar پیشرفته را تکمیل می‌کند.

### Gate فاز ۵

**Gate مالکیت دقیق سناریوها (42 مورد):** TM-051..064, TM-089, TM-093..094, TM-112..121, TM-133..142, TM-214..218. این موارد باید قبل از عبور از فاز، کامل Pass شوند؛ سناریوهای سایر فازها فقط Smoke/Contract در حد قابلیت‌های موجود هستند.
- DST/شمسی، ۲۹ اسفند/۳۰ اسفند، missing recurring date، duplicate reminder، webhook bad signature، CSV formula injection، repeated automation loop و after-commit recovery.

## فاز ۶ — اتصال واقعی CRM، Kanban و Calendar + مستندات نهایی

### ساخت

- Adapterهای اختیاری و جدا با version compatibility matrix؛ Task/Instance Kanban provider/handler و sectionهای Drawer، Calendar due/Stage SLA/milestone/recurrence provider.
- دستورات Drag از UI باید `expected_version` و `operation_id` را پاس دهند و Backend دوباره Policy/Workflow guard را بررسی کند؛ Calendar رویداد SLA read-only و recurrence occurrence-safe باشد.
- CRM migration: inventory schema قدیمی، mapping workflow/stage/type، shadow read، no double-write، backfill valid history، cutover و reconciliation/rollback؛ Ticket→Opportunity و Lead conversion از Handler CRM.
- End-user docs و developer docs: نصب مستقل، usage facade ساده، advanced Context, extension contracts، recipes CRM/Order/Ticket، examples Kanban-only، Calendar-only، third-party models، upgrade guide، troubleshooting.
- تثبیت API، changelog، configs، migration guide؛ تبدیل نتایج اجرای فازهای ۱..۵ به regression reusable.
- تست‌های integration/smoke در اپ میزبانِ نمونه با واقعی‌ترین dependencies و Policyها؛ **این بخش هنوز فاز ساخت اتصال است و قابل ادعای Release نهایی نیست**.

### Gate فاز ۶

**Gate مالکیت دقیق سناریوها (21 مورد):** TM-004, TM-081..088, TM-130..132, TM-143..145, TM-209..211, TM-213, TM-219..220. این موارد باید قبل از عبور از فاز، کامل Pass شوند؛ سناریوهای سایر فازها فقط Smoke/Contract در حد قابلیت‌های موجود هستند.
- همه قابلیت‌های PRD به کد متصل هستند؛ هیچ feature با برچسب TODO/unsupported پنهان باقی نماند؛ اگر موردی باقی مانده، Gate فاز ۶ Failed است و ورود به فاز ۷ ممنوع.

## فاز ۷ — فقط آزمون نهایی، ثبت نقص و تصمیم انتشار

**ممنوع:** افزودن قابلیت، اصلاح کد محصول حتی برای Bugfix، تغییر معماری یا Migration، تغییر قراردادهای محصول و Refactor. **مجاز:** اجرای/تکمیل تست‌ها و ابزارهای QA، تزریق خطا، اندازه‌گیری، ثبت و بازتولید نقص، ساخت گزارش و تصمیم Go/No-Go. هر ایراد به **فاز مالک آن قابلیت** برمی‌گردد؛ بعد از اصلاح در همان فاز، Freeze تازه انجام می‌شود و کل فاز ۷ از ابتدا تکرار می‌گردد.

### ترتیب دقیق اجرای QA

1. **Freeze:** SHA/Tag کاندید، lockfile، versions، schema snapshot، config و seed fixture ثابت، environments مجزا.
2. **Unit/Feature:** اجرای **TM-001..236** و baseline PRD + سناریوهای الحاقی، همه با assert مرحله‌ای و نه فقط assert نهایی.
3. **Cross-module E2E:** Todo→Project؛ CRM Campaign→Reservation→Contract؛ Sales→Contract→Sales؛ Ticket→Opportunity؛ parallel process؛ Todo/Project/Agile؛ Board/Calendar.
4. **Security:** Role×Capability×Scope×Action؛ IDOR، Query/Count/Facet/Export/Event، Tenant/Department/Case leaks، malicious payload/CSV/Webhook.
5. **Concurrency:** status, WIP=1, rank, reparent, dependency cycle, approver, SLA threshold, campaign cutoff, duplicate click, same operation id different payload؛ barrier واقعی DB.
6. **Chaos:** fail/crash قبل/بعد commit و Publish، worker kill mid-batch، lost ack، queue duplicate/out-of-order, DB disconnect، webhook timeout/invalid endpoint، rebuild journal/outbox/projections.
7. **Time:** frozen time، IANA DST، شمسی/میلادی، all-day، Saturday week، business holiday، leap years، reminder offset، cumulative StageVisit.
8. **Load:** 10k/100k Tasks، 100/100k Group Items، 1k concurrent process mix، query count/memory/p95؛ نتایج محیط و داده ثبت شوند، نه شعار.
9. **Compatibility:** MySQL8/Postgres، SQLite baseline، Laravel/PHP matrix مصوب، install/uninstall/upgrade/rollback, API/Facade contracts.
10. **Release audit:** cross-check FR↔TM/E2E/NET، defect triage، SHA و reproducible logs، migration recovery rehearsal و decision Go/No-Go.

### معیار قاطع قبولی

- **۱۰۰٪ سناریوهای اجباری با نتیجه واقعی Pass**؛ Skip فقط برای تفاوت واقعی محیط و با تأیید/جایگزین معتبر، نه برای دورزدن نقص.
- صفر defect باز در Security Critical/High و Data Loss/Scope Leak/Corrupt State/Unrecoverable Queue، عدم نقض invariantهای schema/visibility و عدم orphan پنهان.
- تمام End-to-End حیاتی حداقل روی MySQL و PostgreSQL موفق و مستقل از dummy success باشند؛ SQLite کافی نیست.
- Idempotency و Outbox crash-after-commit با شواهد بازتولید شود؛ duplications/side-effects غیرمجاز صفر.
- ماتریس نصب مستقل Task Manager، Kanban، Calendar و ترکیب‌های مجاز پاس شود؛ عدم وجود UI نباید featureهای core را بشکند.
- گزارش واقعی query count, p95/p99, CPU/memory peak, queue lag, migration time، دیتاست و سخت‌افزار ارائه شود؛ آستانه پیش از اجرا با تیم تصویب گردد.
- Release package با README, API docs, scenario docs, migration/upgrade docs و version/changelog منطبق با کد تست‌شده باشد.

### قاعده بازگشت از فاز ۷

اگر آزمون نشان دهد Feature ناقص است یا قرارداد پایه غلط است، فاز هفت باید **No-Go** اعلام کند؛ نقص به فاز مالک برگردد، feature اصلاح شود و پس از Freeze جدید فاز هفت از ابتدا اجرا شود. **فاز هفت نباید محل ساخت یواشکیِ امکانات جاافتاده باشد**.

---

## ۴. ساختار تست‌نویسی و تحویل مرحله‌ای به Agent

برای هر تست: **شناسه ثابت → شرط اولیه/Seed → Actor/Scope → مراحل با timestamp و version → نتیجه مورد انتظار پس از هر گام → تغییر DB → Audit/Journal/Outbox → رویداد/Handler → امنیت → Cleanup** ثبت شود. از `sleep`، assert صرف HTTP 200، mock کردن همه Domain Services و تستی که فقط تعداد جدول‌ها را چک می‌کند اجتناب شود.

مجموعه تست نهایی در فایل مستقل `TASK-MANAGER-ENTERPRISE-TEST-SCENARIOS-FA.md` آمده: **۲۳۶ سناریوی PRD، شش E2E اصلی، و سناریوهای تکمیلی چندمرحله‌ای NET/REG**. سند اصلی PRD همچنان مرجع قراردادهای داده است.

**Suiteها:** `Unit`, `Feature`, `DatabaseIntegrity`, `Security`, `Concurrency`, `IntegrationCRM`, `IntegrationKanban`, `IntegrationCalendar`, `Scenario`, `Time`, `QueueChaos`, `Performance`, `Upgrade`. در PHPUnit/ Pest به سادگی قابل تفکیک‌اند؛ اسم این Suiteها پیشنهاد اجرایی است، نه ادعای موجود بودن در کد.

**تست‌های سناریویی:** هر سناریوی پایدار یک PHP Test و یک فایل Markdown فارسی هم‌نام داشته باشد؛ Helperهای Scope/Actor/Clock/Outbox/Queue/CRM mocks مشترک ولی Assertionهای هر مرحله داخل خود تست روشن باشند. Failure Artifact: seed, params, DB vendor, timestamps, command id, event id, counterexamples, logs (بدون secrets).

## ۵. Prompt عمومی قابل کپی برای هر فاز

> PRD v2.0 و «برنامه اجرایی هفت‌فازی» و «سناریوهای تست کامل» را ابتدا بخوان. فقط فاز N را اجرا کن. با کد موجود هماهنگ بمان، هیچ Feature فاز آینده را شبیه‌سازی نکرده و Pass نزن. از Context Scope/Actor و operation_id/expected_version استفاده کن. Migrationهای نسخه‌دار، Service/Facade/DTO و تست‌های فاز را بساز، Gate مربوط را با لاگ واقعی نشان بده، مشکلات باقی‌مانده را اعلام کن. تغییر API یا Schema بعد از قفل‌شدن قرارداد فقط با ADR و تست ارتقا مجاز است. تا عبور Gate همان فاز وارد فاز بعد نشو.

**Prompt اختصاصی فاز ۷:** «فازهای ۱ تا ۶ Freeze شده‌اند؛ فقط آزمون جامع، Chaos، Security، Concurrency، Benchmark و گزارش نقص انجام بده؛ **حق ویرایش کد محصول یا ساخت ویژگی جدید نداری**. تمام TM-001..236، E2E-01..06، NET و REG را اجرا کن، تست‌های flaky را تثبیت کن، دوباره همه Suiteها را اجرا کن و گزارش Go/No-Go با شواهد بده. هیچ قابلیت جدید نساز و هر نقص ساختاری را به فاز مالک برگردان.»

## ۶. وابستگی‌های مهم و کنترل ریسک

| خطر | جلوگیری عملی |
|---|---|
| سازگارنبودن API Kanban/Calendar در روز اتصال | read locked tags و Contract Test در فاز ۱؛ Adapter واقعی فاز ۶ |
| دوباره‌کاری در CRM Pipeline | single writer و shadow read؛ cutover gate؛ هیچ double-write |
| ۲۰+ سرویس و schema حجیم برای Todo | Lazy provision؛ Facade/DTO کوچک؛ نصب بدون UI/Queue external |
| هم‌پوشانی Outbox/Automation با Side-effect میزبان | typed Domain Event و ownership matrix، idempotent consumer |
| Cross-DB transaction ناممکن | Saga، pending state، compensation/reconciliation، never fake success |
| Calendar recurring pagination | occurrence-aware cursor در Adapter + regression cross-range |
| افشای داده از aggregate و cache | SQL scope + capability before count/facet و invalidation after-commit |
| «تست‌ها در پایان» باعث کشف دیرهنگام نقص شود | TDD/Feature contract gate در هر ۶ فاز؛ فاز ۷ صرفاً سخت‌سازی و اثبات |

## ۷. خروجی‌های لازم در پایان هر فاز

| Artifact | الزام |
|---|---|
| `PHASE-N-IMPLEMENTATION.md` | قابلیت‌ها، فایل‌های تغییرکرده، قراردادهای قطعی |
| `PHASE-N-SCHEMA.md` | migrationها، ایندکس‌ها، قفل‌ها و rollback |
| `PHASE-N-TEST-REPORT.md` | تست‌ها و لاگ واقعی، Environment، Fail/Skip با علت |
| `PHASE-N-DECISIONS.md` | ADR و تغییر/ریسک باقی‌مانده |
| `PHASE-N-API.md` | Facade/Service DTO/config نمونه عملیِ همان فاز |
| `PHASE-N-NEXT-GATE.md` | قابلیت‌های فاز بعد و پیش‌شرط واقعی |

**گزارش فاز هفت** علاوه بر اینها: `FINAL-ACCEPTANCE-MATRIX.md`, `SECURITY-REVIEW.md`, `CHAOS-RESULTS.md`, `BENCHMARK-RESULTS.md`, `MIGRATION-REHEARSAL.md`, `RELEASE-GO-NOGO.md`.


## ۸. ماتریس دقیق «مالک فاز» برای ۲۳۶ تست

**هیچ سناریوی پایه بدون مالک و هیچ شناسه‌ای با دو مالک وجود ندارد.** هر فاز مسئول ساخت کامل Feature و اجرای Gate مربوط است. در فاز ۷ **همه ۲۳۶ مورد از نو** اجرا می‌شوند، علاوه بر ۶ E2E و ۱۲۰ تست تکمیلی. مالک بودن به معنی ممنوعیت اجرای زودتر smoke نیست؛ صرفاً زمان مجاز Pass اعلام‌کردن سناریوی کامل را تعیین می‌کند.

| فاز | دسته | تعداد |
|---|---|---:|
| 1 | Foundation/Todo | 25 |
| 2 | Workflow/External Subject | 38 |
| 3 | SLA/Handoff/Campaign | 58 |
| 4 | Project/Hierarchy/Agile | 52 |
| 5 | Scheduling/Enterprise | 42 |
| 6 | CRM/UI Adapters | 21 |
| **مجموع** | **TM-001..236** | **236** |
