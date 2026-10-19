# پرامپت‌های جداگانه AI Agent برای هفت فاز Task Manager

این فایل را همراه این سه سند در دسترس Agent قرار دهید: `KARNOWEB-TASK-MANAGER-ENTERPRISE-PRD-v2.0-FA.md`، `TASK-MANAGER-7-PHASE-IMPLEMENTATION-PLAN-FA.md` و `TASK-MANAGER-ENTERPRISE-TEST-SCENARIOS-FA.md`.

> **روش اجرا:** هر فاز در یک Task/Conversation مستقل، با دسترسی به مخزن و گزارش Commit فاز قبل. پس از هر Gate، خروجی برای بازبینی انسان تحویل شود؛ **بدون تأیید فاز قبل، به‌صورت خودکار وارد فاز بعد نشود**. اگر مخزن قبلاً ساخته شده، کار را از همان Commit فعلی ادامه بده و موازی‌سازی بی‌دلیل نکن.

## قرارداد ثابت همه Agentها

- PRD v2.0 مرجع مالکیت داده و رفتار است؛ توسعه براساس نام‌گذاری فرضی کلاس‌ها بدون دیدن کد فعلی ممنوع.
- خروجی باید **کد واقعی پکیج** به همراه migrations, contracts, services, DTOs, Facade, docs و تست‌های همان مرحله باشد، نه فقط architecture proposal.
- هسته مستقل از CRM/Kanban/Calendar/Livewire باشد؛ Adapter در مسیر اختیاری.
- Actor/Scope/Version/OperationID/Outbox/Audit/Query-level Authorization پایه همه Writeها و Queryهای حساس‌اند.
- Test failures پنهان نشوند؛ تنها تستی Pass است که log واقعی اجرا ارائه شده باشد.
- پس از پایان هر فاز: `PHASE-N-IMPLEMENTATION.md`, `PHASE-N-TEST-REPORT.md`, `PHASE-N-DECISIONS.md`, `PHASE-N-API.md`, `PHASE-N-SCHEMA.md` و `PHASE-N-NEXT-GATE.md` تحویل شود.
- سه پایگاه‌داده برای لایه پایه بررسی شوند؛ تست concurrency روی MySQL/Postgres، نه SQLite جعلی.
- با نقض قرارداد یا خطر امنیتی **توقف و گزارش** بده؛ راه‌حل موقت بی‌صدا نساز.

---

## پرامپت فاز ۱ — Foundation, Security, Todo

**ماموریت:** پکیج `karnoweb/task-manager` را به‌صورت Headless ایجاد/تکمیل کن. فقط قابلیت‌های فاز ۱ برنامه اجرایی را پیاده‌سازی کن: ServiceProvider/Config, ActorScopeContext, Resolver, typed DTO/domain errors, Stateless Facade, migration/identifier, Inbox/List/Owner/Share, native personal Task, optimistic version, idempotency journal, mandatory outbox, scoped query/aggregate, audit پایه و درایور ارسال رویداد بدون Queue خارجی.

**مسیر شروع:** وضعیت واقعی repo و چارچوب تست را inventory کن؛ اسامی Facade/accounting/inventory را تنها پس از رؤیت سورس واقعی در صورت دسترس اقتباس کن. اگر سورس در دسترس نیست این محدودیت را ثبت و API را با معیار سادگی + type safety طراحی کن. Pseudocode را به‌عنوان تحویل نهایی قبول نکن.

**تست و Gate:** ۲۵ تست مالک فاز ۱ را از جدول مالکیت هفت‌فازی بخوان و اجرا کن؛ یک Todo با Title، Create/Complete/Reopen، Retry/Conflict، Share/revoke، tenant isolation، Count و Outbox crash-after-commit را اثبات کن. Fail-safe بدون Scope را در CLI و Queue بررسی کن. Commit نهایی و گزارش بده. **فاز ۲ را شروع نکن.**

## پرامپت فاز ۲ — Workflow, Pipeline, External Subject

**ماموریت:** فقط فاز ۲: definition/revision/state/transition/guard/reason، project-type workflow mapping، Project/member permissions، Pipeline/Stage mapping، WorkflowInstance/active slot، StageVisit، ProcessCase پایه، External Subject Registry & Handler، move-to-pipeline با mapping/preview، Owner/Assignee پایه، WIP concurrency و Audit.

**دامنه:** Lead/Opportunity/Ticket در این مرحله *Mock Host Models* هستند؛ Task Manager حق ساخت جدول CRM یا تغییر مستقیم رکورد دامنه میزبان را ندارد. State تجاری در instance اصلی است؛ Board فقط نمایش. Workflowهای والد و فرزند می‌توانند متفاوت باشند.

**تست و Gate:** ۳۸ تست مالک فاز ۲ + Flow نمونه Lead از Transition مجاز تا Guard veto و rollback، Role revoke/count/query، Workflow revision migration، دو درخواست WIP=1، duplicate instance slot. تمام Failها اصلاح و Evidence ثبت شود؛ **فاز ۳ را شروع نکن.**

## پرامپت فاز ۳ — SLA, Handoff, Department, Campaign

**ماموریت:** فقط فاز ۳ (اولویت CRM): SLA StageVisit، warning/breach/stale/dead candidate، working clock و Pause/Resume، assignee fixed/dynamic/multi، approvers sequential/parallel/quorum، handoff move/delegate/spawn/parallel، ReturnPolicy revision/Outcome، Cross-DB Saga، ProcessCase Timeline، Group/Campaign closure با cut-off تاریخی/milestone/partial-result/keyset/lease/retry.

**تست و Gate:** ۵۸ تست مالک فاز ۳ و E2E-02..06 با Test Host Providers. سناریوی کمپین **۷۵ failed، ۱۵ continued (۵ در قرارداد)، ۱۰ already-terminal** پس از Crash و Replay باید دقیقاً ثابت بماند. Ticket→Opportunity را تبدیل مدل ندان: دو Subject در یک Case. SLA breached بدون rule موجب AutoFail نیست. سه نتیجه approved/rejected/cancelled را برگردان، owner fixed و assignee restored. **فاز ۴ را شروع نکن.**

## پرامپت فاز ۴ — Full Project and Task Engine

**ماموریت:** فقط فاز ۴: Closure Table، depth/cycle/reparent، Dependency 4-type + Lag و enforcement policy، Checklist, Comments/mentions, Attachment contracts, Watchers, Project Templates, Backlog/Sprint/Milestone/Release, rank, Story Points snapshot، Time Tracking with active timer/overlap, history transitions و report projections/rollup/Critical Path.

**تست و Gate:** ۵۲ تست مالک فاز ۴؛ parent Bug با Workflow متفاوت، lock در concurrent reparent، Sprint historical snapshot، leaf-only Velocity، timer overlap، permission روی Comment/Attachment، dependency cycle و missed parent/child approvals. در خروجی راهنمای Laravel Facade برای Todo ساده و مدیریت Sprint قرار بده. **فاز ۵ را شروع نکن.**

## پرامپت فاز ۵ — Scheduling and Enterprise

**ماموریت:** فقط فاز ۵: Gregorian/Jalali recurrence، all-day/timezone/DST، reminders/snooze/quiet-hours، normalized Persian search، typed/multi Custom Fields، secure Automation، Event Subscription/Notifications، Webhook HMAC/SSRF protection، streaming/async Export، enterprise report و historical projection rebuild. Integration Event باید از Outbox باشد.

**تست و Gate:** ۴۲ تست مالک فاز ۵؛ اسفند کبیسه/غیرکبیسه و ۳۰ روز، زمان تابستانی، reminder بعد تغییر Due، worker retry، Automation loop bound، HMAC+replay+SSRF، export revoked permission و CSV injection، 100k bounded SQL/pagination. Backend بدون UI/CRM همچنان نصب و اجرا شود. **فاز ۶ را شروع نکن.**

## پرامپت فاز ۶ — CRM/Kanban/Calendar integration and docs

**ماموریت:** فقط فاز ۶: سورس/نسخه نصب‌شده Kanban و Calendar را **واقعاً** بخوان، Adapterهای optional برای Task/External Instance/Drawer و Calendar events بنویس؛ Source Selection per-board/per-calendar، secure Drag/Resize، Activity/SLA/Handoff sections، stable event IDs، recurrence-safe Query. CRM cutover/legacy task import/reconciliation/shadow read/no double-write و Docs کامل Facade/Service/DTO/config/extension را بساز.

**تست و Gate:** ۲۱ تست مالک فاز ۶؛ نصب چهار ترکیب واقعی (Task-only، Kanban-only، Calendar-only، همه)، بردهای CRM/Ticket/Order، Ticket→Opportunity، نسخه‌‌های locked Packages، Stage SLA read-only calendar، Drawer info permissions، migration no duplicate truth، rollback rehearsal و E2E-01..06 Host-real. **تمام امکانات محصول تا پایان این فاز باید موجود باشند.** Release Candidate را Freeze و تحویل بده. **فاز ۷ را بدون تأیید انسانی شروع نکن.**

## پرامپت فاز ۷ — فقط QA و تصمیم انتشار

**ماموریت:** این فاز به‌طور مطلق **فقط تست** است. کد محصول، Featureها، Facade، schema و migrations را ویرایش نکن؛ حتی برای Bugfix. فقط Runner/Fixtures/Test Files/Logs/Reports قابل تغییرند. اگر Bug یافت شد، `DEFECT` شامل خطوات بازتولید، DB vendor، expected/observed، test ID، impact، severity، lockfile/SHA بده و آن را به فاز مالک برگردان؛ پس از اصلاح خارج از فاز هفت و Freeze تازه، تمام فاز هفت را از اول اجرا کن.

**تست‌های اجباری:** ۲۳۶ TM + شش E2E + ۴۰ NET + ۸۰ REG = **۳۶۲ شناسه**، همراه SQL/Lock/Chaos/Crash/Retry/Permission Revoke/DST/Jalali/Campaign 100k/CRM Cutover/Browser-host/Standalone install. حداقل MySQL و PostgreSQL برای Concurrency و Queue-Chaos، و SQLite برای Unit/Feature پایه. هیچ تست Skip برای مخفی‌کردن Feature ناقص مجاز نیست.

**گزارش خروجی:** ماتریس requirements→tests→results، CI suite logs، امنیت و leakage، migration/reconciliation، performance benchmark با Env، Outbox recovery، Test Flakiness، Go/No-Go و فهرست نقص‌های باز. **اگر حتی یکی از Gateهای اصلی Fail باشد، خروجی نهایی `NO-GO` است و ادعای آماده انتشار نکن.**

---

## کنترل‌های انسانی پیشنهادی

قبل از آغاز فاز ۱: تأیید Scope و قراردادهای باز PRD (Actor/Scope، subject alias، cutoff equality، feature-release label).  
پس از فازهای ۲ و ۳: بازبینی نمونه CRM Lead/Opportunity/Ticket، جریان قرارداد و کمپین در جلسه واقعی تیم.  
پس از فاز ۶: قفل‌کردن Release Candidate SHA و بررسی مستندات مصرف.  
پس از فاز ۷: تصویب رسمی Go/No-Go؛ هیچ ادعای ۱۰۰٪ تست‌شده بدون گزارش واقعی.
