# PRD جامع و یکپارچه — Karnoweb Task Manager / Workflow & Process Engine

**نسخه سند:** 2.0.0 — پیش‌نویس تصمیم‌گیری تیمی، جایگزین یکپارچه PRD 1.0 و Review Resolution 1.1  
**تاریخ تدوین:** ۲۰ اکتبر ۲۰۲۶  
**پکیج پیشنهادی:** `karnoweb/task-manager`  
**مخاطبان:** مالک محصول، تیم CRM، معمار نرم‌افزار، توسعه‌دهنده Laravel، امنیت، QA، عملیات و مدیر پروژه  
**وضعیت:** **نیازمندی و طراحی پیشنهادی، نه قابلیت پیاده‌سازی‌شده.** موارد نیازمند تصویب در انتهای سند با مالک تصمیم و پیشنهاد پیش‌فرض مشخص شده‌اند.  
**مبنای تصمیم:** PRD v1.0، اصلاحات بازبینی v1.1، سناریوهای CRM، کمپین فروش، SLA، انتقال تیکت و قرارداد بین‌بردی این گفتگو.

> **هدف کلیدی:** یک موتور *Headless* (بدون رابط کاربری اجباری) برای Todo ساده، Task/Project پیشرفته و اجرای Workflow/Pipeline روی مدل‌های بیرونی مثل Lead، Opportunity، Ticket و Order؛ بدون وابستگی اجباری به Kanban یا Calendar. **CRM اولویت نخست یکپارچه‌سازی است** و می‌تواند Pipeline/Stage/Workflow تکراری خود را به این موتور منتقل کند.

> **مرزبندی مستند:** این سند قراردادهای طراحی پیشنهادی را تثبیت می‌کند؛ نه ادعای وجود API در کد فعلی. در زمان اجرا باید امضای واقعی `kanban-livewire` و `laravel-calendar` در نسخه نصب‌شده با Adapter تطبیق داده شود. الگوی Facade با الهام از تجربه پکیج‌های حسابداری/انبارداری کاربر است، ولی تطبیق اسامی متد با سورس همان نسخه‌ها منوط به بررسی سورس واقعی است. **سورس حسابداری و انبارداری در این بازنگری در اختیار نبود.**

---

## ۱. خلاصه تصمیم محصول

Task Manager سه قابلیت هم‌راستا در **یک پکیج** ارائه می‌کند:

1. **Task Engine:** ساخت و مدیریت Todo، Task، زیرتسک، وابستگی، Checklist، کامنت، زمان، مسئولیت و پروژه/اسپرینت.
2. **Workflow & Pipeline Engine:** وضعیت‌ها، انتقال‌های مجاز، مراحل نمایشی، نسخه‌بندی، SLA هر مرحله، مسئولیت پویا، تأیید چندنفره، ارجاع و بازگشت، مدیریت گروهی چرخه‌عمر.
3. **Process Coordination:** مدیریت جریان یک «پرونده کار» میان Task و **موجودیت‌های تجاری مستقل** (سرنخ، فرصت، تیکت، قرارداد، سفارش)، با چند اجرای مستقل Workflow، حفظ هویت و تاریخچه.

**اصل بنیادی:** Board ابزار *نمایش* است؛ Pipeline تعریف فرایند؛ Stage نمایش مرتب وضعیت یا نقطه پیشرفت؛ Workflow قانون انتقال؛ Workflow Instance اجرای واقعی آن؛ Process Case شناسه ردیابی فراگیر. **وجود یک کارت در دو Board به معنی دو نسخه داده یا دو وضعیت متناقض نیست.**

**حالت‌های استفاده:** `personal` (Todo در یک مرحله)، `team`, `agile`, `process` و `enterprise` فقط Preset تنظیمات‌اند؛ نه جدول یا مدل Task متفاوت. ساخت Todo باید با عنوان و Context صریح ممکن باشد؛ بدون ساخت Project، Board، Pipeline یا Case توسط توسعه‌دهنده.

**استقلال:** Task Manager بدون Kanban/Calendar نصب و اجرا می‌شود؛ Kanban/Calendar نیز بدون Task Manager می‌توانند CRM/Order/Ticket را مستقیماً نمایش دهند. استفاده از Laravel، Facade، Service، DTO، Contracts، Eloquent و Queue مجاز است؛ Livewire/Filament/UI اجباری نیست.

## ۲. مسئله، مخاطبان و معیارهای موفقیت

| شناسه هدف | شرح | آزمون پذیرش |
|---|---|---|
| G-01 | Todo بدون پیچیدگی | با Actor، Scope و Title کار ساخته شود؛ هیچ Project/Pipeline از کاربر درخواست نشود |
| G-02 | پروژه واقعی | Workflow چندنوعی، زیرتسک، وابستگی، Sprint، Backlog، گزارش قابل استفاده باشد |
| G-03 | CRM بدون موتور موازی | مراحل Lead/Opportunity توسط Workflow Engine کنترل شود و CRM فقط مالک داده فروش بماند |
| G-04 | کمپین زمان‌دار | گروه ۱۰۰ رکورد با SLA و پایان شرطی، ایمن و قابل حسابرسی مدیریت شود |
| G-05 | ارجاع بین‌دپارتمانی | Ticket به فروش منتقل یا Opportunity مرتبط ایجاد شود، بدون پاک‌شدن سابقه |
| G-06 | ارجاع و بازگشت | فروش→قرارداد→فروش در حالت تأیید/رد/لغو با Instanceهای مستقل |
| G-07 | مسئولیت انعطاف‌پذیر | مالک ثابت، مسئول اجرایی متغیر، چند مسئول و چند Approver مستقل |
| G-08 | استقلال و قابلیت استفاده مجدد | چهار ترکیب Task-only، Kanban-only، Calendar-only، هر سه موفق باشند |
| G-09 | امنیت و صحت | عدم افشای داده در list/count/export/event و عدم فساد در race/Retry |
| G-10 | گزارش قابل دفاع | مدت حضور در Stage، Stage SLA، خروج کمپین، Pipeline conversion و Sprint تاریخی قابل بازسازی |
| G-11 | DX (تجربه توسعه‌دهنده) | Facade ساده + Context/DTO/Service پیشرفته با مثال و تست |

**پرسوناها:** کاربر Todo، کارشناس فروش، مدیر کمپین، کارشناس پشتیبانی، مدیر قرارداد/تأییدکننده، سرپرست دپارتمان، مدیر پروژه/تیم Agile، توسعه‌دهنده محصول میزبان، بازرس امنیت و اپراتور Queue.

## ۳. دامنه و مرز مالکیت

| مفهوم | مالک حقیقت | مسئول نمایش/واسط |
|---|---|---|
| Todo/Task، زیرتسک، امتیاز، زمان و مسئولیت | Task Manager | هر UI میزبان، Kanban، Calendar |
| Project، Sprint، Milestone، Release، dependency | Task Manager | UI میزبان |
| Workflow، Pipeline، Stage mapping، Instance، Handoff، Stage SLA | Task Manager | Kanban یا UI دلخواه |
| Campaign، محصول کمپین، شروط فروش، اعتبار رزرو | CRM | CRM، Kanban برای نمایش |
| Lead، Customer، Opportunity، Ticket، Contract، Order و فیلدهای تجاری | سرویس مالک (CRM/Ticket/Contract/Order) | Adapter مربوط |
| Auth/User/Team/Department/Tenant/Branch | میزبان | Actor/Scope/Organization/Team Resolver |
| فایل فیزیکی، ارسال پیام، ذخیره SMS/Email | میزبان یا پکیج مربوطه | Storage/Notification Contracts |
| Board و UI کارت/Drawer، drag/drop و ستون‌های نمایشی | `kanban-livewire` | Kanban |
| تقویم، Drag تاریخ، رخداد چندروزه | `laravel-calendar` | Calendar |

**ممنوع:** نگهداری وضعیت مستقل CRM در `crm_leads.stage_id` به‌عنوان حقیقت دوم؛ جایگزینی مدل Lead با Task؛ سخت‌کدن‌کردن `auth()` یا `current_branch` در هسته؛ FK اجباری به جداول میزبان؛ اجرای تغییر Order/Contract مستقیماً از جداول Task Manager؛ تبدیل خاموش Ticket به Opportunity.

**دامنه محدود:** موتور عمومی BPMN، حسابداری، مدیریت اسناد قراردادی/امضای حقوقی، تلفن و ارسال پیام، UI نهایی Trello/Jira، چت لحظه‌ای و اپ بومی موبایل خارج از پکیج‌اند. **قابلیت ارجاع به فرایند قرارداد درون محدوده هست؛ منطق امضای قرارداد متعلق به ماژول قرارداد می‌ماند.**

## ۴. واژه‌نامه دامنه و تفاوت مفاهیم

| اصطلاح | معنای دقیق |
|---|---|
| `Task` | کار قابل اقدام با شناسه پایدار؛ Todo، Bug، Story یا Epic نیز Task هستند |
| `Subject` | موجودیت مورد پردازش Workflow: Task داخلی یا مدل ثبت‌شده بیرونی |
| `WorkflowDefinition` | گراف نسخه‌دار وضعیت‌ها و انتقال‌های مجاز |
| `WorkflowInstance` | یک **اجرا** از Workflow برای یک Subject؛ وضعیت جاری و تاریخچه مخصوص خود |
| `Pipeline` | پیکربندی هدفمند نمایش/هدایت یک Workflow در فرآیند تجاری |
| `Stage` | مرحله Pipeline که به یک وضعیت Workflow نسخه مشخص یا نگاشت معتبر آن اشاره دارد |
| `Board` | نمای فیلترشده در Kanban؛ نه الزاماً معادل Pipeline و نه مالک وضعیت |
| `ProcessCase` | پرونده ردیابی گروهی چند Subject/Task/Instance مرتبط با یک مسئله واحد |
| `Handoff` | واگذاری/انتقال کنترل‌شده اجرای کار به تیم، Pipeline یا Workflow دیگر |
| `ReturnPolicy` | قانون ادامه فرایند مبدأ پس از نتیجه فرایند مقصد |
| `SLA` | مهلت پاسخ/انجام/حضور در مرحله با تقویم کاری و سیاست نقض |
| `StageVisit` | یک مرتبه ورود به Stage تا خروج از آن؛ حتی اگر بعداً بازگشت رخ دهد |
| `Owner` | مسئول مالکیت/پاسخ‌گویی پایدار، جدا از مجری مرحله |
| `Assignee` | مسئول یا مسئولان اجرا، با تاریخچه واگذاری |
| `Approver` | تأییدکننده مشخص یا نقش/گروه قابل Resolve؛ نه Assignee پیش‌فرض |
| `CampaignGroup` | گروهی از کارها/Instanceها برای اجرای سیاست چرخه‌عمر؛ کمپین CRM صرفاً یک Source خارجی است |
| `Scope` | مرز جداسازی امنیتی داده، مثل Tenant؛ نه لزوماً Branch |
| `Actor` | هویت مجاز انجام عملیات، شامل User/Service Actor از میزبان |
| `Operation ID` | شناسه منحصربه‌فرد درخواست برای جلوگیری از اجرای مضاعف |
| `Version` | نسخه optimistic concurrency برای جلوگیری از بازنویسی هم‌زمان |

## ۵. اصول معماری غیرقابل نقض

1. **یک منبع حقیقت برای هر وضعیت:** وضعیت Workflow در `tm_workflow_instances` ذخیره می‌شود؛ Stage در Pipeline صرفاً نمای نگاشت‌شده است. برای Task ساده نیز یک Instance پیش‌فرض به‌صورت خودکار ساخته می‌شود. فیلدهای وضعیت در Task یا CRM، در صورت نیاز فقط projection/read-model غیرقابل نوشتن مستقیم‌اند.
2. **یک Subject، چند Instance مستقل:** یک Subject می‌تواند چند فرآیند مجاز داشته باشد؛ برای هر `(scope,subject,slot_key)` فقط یک Instance فعال وجود دارد. Instanceهای تاریخی قابل بازگشت/حسابرسی‌اند.
3. **Board با Process یکی نیست:** تغییر فیلتر یا محل نمایش کارت، ذاتاً Transition یا Handoff انجام نمی‌دهد. انتقال فرآیندی فقط با فرمان صریح.
4. **قوانین میزبان محفوظ‌اند:** هر تغییر Workflow موجودیت بیرونی باید از Handler/Guard دامنه میزبان و Capability عبور کند.
5. **عدم تغییر هویت:** `Task.public_key` و مرجع Subject در انتقال بین Board، Pipeline یا Project ثابت می‌مانند؛ در تبدیل تجاری Ticket→Opportunity دو Subject مستقل، مرتبط با یک Case نگهداری می‌شوند.
6. **دسترسی پیش از Query:** فیلتر خواندن باید قبل از pagination/count/facet/aggregate اجرا شود. `view=false` بعد از hydrate کردن قابل قبول نیست.
7. **Context ایمن:** Actor، Scope، Permission و Correlation به‌صورت صریح؛ نبود Scope به معنی دسترسی جهانی نیست. Facade singleton وضعیت mutable ندارد.
8. **اتمیک بودن دامنه:** نسخه، قفل، Journal، Audit، StageVisit، Assignment/Return، Outbox در همان تراکنش DB مربوطه ثبت شوند. Side effect بیرونی از Outbox/Saga کنترل شود.
9. **هر مسیر برگشت تعریف‌شده:** success/reject/cancel/timeout/manual-recall در Handoff دارای ReturnPolicy نسخه‌دار یا وضعیت منتظر تصمیم انسانی‌اند؛ برگشت خاموش به مرحله حدسی ممنوع.
10. **کلید/مدت/قانون پایدار:** Stage و Workflow پس از استفاده بدون migration/versioning پاک یا به‌صورت خام mutate نشوند.
11. **استفاده ساده ارزان:** هسته پیش‌فرض بدون Queue خارجی، برد یا سرویس پیام جدا کار کند؛ سرویس dispatch داخلی برای Outbox قابل اجرا باشد.
12. **پشتیبانی از DB:** MySQL 8+، PostgreSQL و SQLite برای تست پایه؛ قفل‌های concurrency روی MySQL/PostgreSQL واقعی سنجیده شوند، وعده قابلیت قفل production روی SQLite داده نشود.

## ۶. پروفایل محصول و حدود وابستگی

| پروفایل | پیش‌فرض | چیزی که اجباری نیست |
|---|---|---|
| `personal` | Inbox، Todo، Owner، Workflow دوحالته، due/reminder | Project، Stage، Campaign، Case، چند مسئول |
| `team` | Project، Workflow ساده، عضویت، نقش، Subtask | Sprint، BPMN، Campaign |
| `agile` | Epic/Story/Bug، Backlog، Sprint، Story Points | CRM، Pipeline فروش |
| `process` | Subject خارجی، Pipeline/Instance، SLA، Handoff، Assignment | Project و Native Task در هر فرآیند |
| `enterprise` | همه قابلیت‌های پیکربندی‌شده، Audit و Coordinator | الزام نصب UI خاص |

قابلیت خاموش، داده موجود را حذف نمی‌کند؛ فعال‌سازی و مجوز عملیاتی در Backend کنترل می‌شوند. هر پروفایل Preset است و قابل Override در Context/Scope/Project/Pipeline.

## ۷. قواعد Scope، Actor، سازمان، تیم و مالکیت

- `scope_key` رشته غیرخالی و ثبت‌شده؛ در Single-Tenant مقدار `default` فقط با تنظیم صریح مجاز است. مقدار `null` هرگز global visibility نیست.
- Actor شامل `type` و `id` پایدار است؛ `ActorResolver` در لبه HTTP/CLI مجاز به استفاده از Auth میزبان است، اما هیچ Domain Service از `auth()` و session استفاده نمی‌کند.
- Department/Team مرجع بیرونی `(alias,id)` است و Resolver میزبان افراد واجد شرایط، سلسله‌مراتب، شیفت و مجوز را می‌دهد؛ هسته جدول کاربران/دپارتمان ندارد.
- Todo بدون Project دارای `owner_ref` اجباری است؛ assign کردن به شخص دیگر مالکیت یا مجوز را به‌طور ضمنی منتقل نمی‌کند. اشتراک صریح در `tm_task_shares` برقرار می‌شود.
- `source_type` و subject alias از Morph Map/Registry پایدار می‌آید؛ **هیچ نام کامل کلاس PHP در DB ذخیره نشود**. شناسه خارجی String معتبر با سقف طول ثابت و Schema ثبت‌شده است.
- مرز Cross-Scope در Task/Dependency/Handoff/Case/Comment/Attachment به‌صورت پیش‌فرض **ممنوع** است؛ ورود به Scope دیگر فقط از مسیر integration و ایجاد Subject/Case مجاز جدید توسط میزبان، نه انتقال بی‌سروصدا.
- مجوز مشاهده Subject بیرونی باید توسط Provider میزبان و Task Manager هر دو تأیید شود؛ داشتن Case ID یا Public Key مجوز ایجاد نمی‌کند.
- تعامل با چند Branch در یک Scope از طریق predicate مجوز میزبان انجام می‌شود؛ Branch manager داخل پکیج تعریف نمی‌شود.

## ۸. شناسه، کلید عمومی و هویت داده

- کلید داخلی جدول‌های هسته: BIGINT؛ `public_key` هر Task یک UUID/ULID سراسری با UNIQUE واقعی، حتی در Todo با `project_id=null`.
- `display_key` مانند `CRM-42` با Sequence تراکنشی Scope/Project تولید می‌شود و می‌تواند پس از تغییر پروژه عوض شود؛ `public_key` ثابت می‌ماند. Aliasهای قبلی در `tm_task_key_aliases` نگهداری می‌شوند.
- `case_key`, `instance_key`, `handoff_key`, `run_key`, `event_id` نیز شناسه‌های عمومی پایدار و یکتا دارند.
- برای نگاشت ارجاع به CRM، aliasهای versioned مانند `crm.lead`, `crm.opportunity`, `support.ticket`, `contract.contract`, `order.order` به‌عنوان **مثال Registry** عرضه می‌شوند، نه نیازمند کلاس ثابت.
- هر لینک/شناسه قابل resolve تنها پس از Scope+Policy مجاز؛ جست‌وجوی شناسه‌ای نباید شمار یا وجود رکورد غیرمجاز را افشا کند.

---

## ۹. مدل داده نهایی پیشنهادی و قرارداد ذخیره‌سازی

**قرارداد عمومی تمام جداول دامنه:** PK، `scope_key` در موجودیت‌های قابل دسترسی مستقل و/یا FK به ریشه scoped، `created_at_utc`/`updated_at_utc`، محدودیت FK داخلی معتبر، Unique حقیقی، Index خواندن و version در aggregateهای mutable. تمام FKهای داخلی Task Manager واقعی‌اند؛ FK اجباری به CRM/User/Department نداریم. جدول‌های append-only تاریخچه نه با ویرایش UI و نه با soft-delete تسک پاک نمی‌شوند، مگر retention/redaction مصوب.

### ۹.۱ هسته Task، پروژه و برنامه‌ریزی

| جدول | فیلدهای کلیدی | قیدهای قطعی |
|---|---|---|
| `tm_tasks` | `id, public_key, scope_key, project_id?, list_id?, parent_task_id?, owner_type/id, creator_type/id, reporter_type/id, type_key, title, title_search_normalized, description, priority, starts_at_utc?, ends_at_utc?, due_at_utc?, timezone, all_day, estimate_minutes?, story_points?, actual_started_at_utc?, completed_at_utc?, archived_at_utc?, deleted_at, version` | Project اختیاری؛ Status منبع حقیقت در این جدول **نیست**؛ Title اجباری؛ Row Scope ثابت؛ Owner برای Todo اجباری |
| `tm_projects` | `scope_key, key, name, owner_ref, default_workflow_id, status, settings, version` | Project بدون CRM ممکن است؛ key در Scope یکتا |
| `tm_project_members` | `scope_key,project_id,actor_ref,role,joined_at,left_at` | عضویت فعال یکتا؛ نقش→Capability |
| `tm_lists` | `scope_key,owner_ref,name,is_inbox,rank,archived_at,version` | Inbox یکتای فعال به ازای Owner/Scope از طریق جدول/کلید رزرو پایدار DB |
| `tm_task_shares` | `scope_key,task_id,actor_ref,capabilities,expires_at,granted_by,version` | فقط Todo/Share دارای Policy؛ محدود به capability مجاز |
| `tm_task_closure` | `scope_key,ancestor_task_id,descendant_task_id,depth` | Unique ancestor/descendant؛ Self depth=0 |
| `tm_task_key_aliases` | `scope_key,task_id,display_key,is_current,created_at` | کلید نمایشی یکتا، Resolve دارای Policy |
| `tm_task_positions` | `scope_key,task_id,context_kind,context_ref,rank_value,version` | Context مستقلاً versioned؛ tie breaker `task_id`؛ rank در Board میزبان فقط در صورت واگذاری مالکیت |
| `tm_task_labels`, `tm_labels` | `scope_key,task_id,label_id,name,color` | یکتایی و مجوز Scope |
| `tm_checklists`, `tm_checklist_items` | `task_id,sort,done,assignee_ref?,due_at?,converted_task_id?` | چند Checklist؛ تبدیل Item به Task با ID ثابت/idempotency |
| `tm_task_assignments` | `scope_key,task_id,actor_ref,kind,active_from,active_to?,assigned_by,reason,source_instance_id?,version` | برای kindهای primary/secondary؛ Active uniqueness مستقل و تعریف‌شده |
| `tm_task_watchers` | `task_id,actor_ref,preferences` | Watch دسترسی دیدن نمی‌دهد |
| `tm_task_dependencies`, `tm_task_links` | `predecessor_id,successor_id,type,lag_minutes,policy` | dependency جهت‌دار و بی‌چرخه؛ Link صرفاً ارتباط |
| `tm_comments` | `task_id,author_ref,body,edited_at,deleted_at,version` | Authorize روی هر Comment و Task |
| `tm_attachments` | `task_id,file_ref,uploader_ref,media_type,size,checksum,deleted_at` | فایل واقعی متعلق به میزبان؛ دانلود از مسیر مجوزدار |
| `tm_task_templates`, `tm_project_templates` | `scope/project,template_key,definition,version` | کلید/نسخه پایدار، Preview ایجاد |
| `tm_custom_fields`, `tm_custom_values`, `tm_custom_value_options` | `field.type, value_string/number/boolean/date/datetime/json, option_id` | نوع typed و ستون قابل Index؛ چندانتخابی جدا |
| `tm_task_time_entries`, `tm_active_timers`, `tm_task_stats` | `start/end/duration,actor,state,projection_source_version` | یک تایمر فعال برای Actor پیش‌فرض؛ Stats بازسازی‌پذیر |
| `tm_reminders` | `task_id,actor_ref,anchor,offset,scheduled_at_utc,timezone,dedupe_key,state,attempts` | باززمان‌بندی کنترل‌شده هنگام تغییر Deadline |
| `tm_recurrences`, `tm_recurrence_occurrences`, `tm_recurrence_exceptions` | `rule,calendar_system,timezone,occurrence_key,exception` | Gregorian/Jalali و Dedup وقوع |
| `tm_sprints`, `tm_sprint_tasks`, `tm_sprint_daily_snapshots` | `project_id,state,membership,points,revision` | Snapshot تاریخچه و شمارش برگ‌ها |
| `tm_milestones`, `tm_releases`, `tm_saved_filters` | `project/scope,dates,settings` | بدون نیاز به Sprint |

### ۹.۲ موتور Workflow و Pipeline (جدید و اصلی)

| جدول | فیلدهای کلیدی | قرارداد |
|---|---|---|
| `tm_workflows` | `scope_key,id,key,name,revision,state,blueprint_origin,version` | Definition متعلق به Scope؛ templateهای بسته کپی per-scope، Global mutable نداریم |
| `tm_workflow_states` | `workflow_revision_id,state_key,name,category,terminal_kind,color,sort` | Key و Category پایدار؛ terminal: success/failure/cancelled/none |
| `tm_workflow_transitions` | `revision,transition_key,from_state_key,to_state_key,guards,validators,actions,reason_schema,version` | Transition یک‌طرفه؛ self-transition مجاز؛ guard/action whitelist ثبت‌شده |
| `tm_workflow_revisions` | `workflow_id,revision_no,snapshot,created_by,activated_at` | Instance از snapshot/revision ثابت استفاده می‌کند؛ تغییر Definition فعلی Instance را خاموش تغییر نمی‌دهد |
| `tm_project_type_workflows` | `project_id,type_key,workflow_id` | نوع Bug می‌تواند Workflow جدا داشته باشد؛ Parent فقط Scope/Project یکسان |
| `tm_pipelines` | `scope_key,key,workflow_id,workflow_revision_policy,owner_team_ref?,type,settings,version` | تعریف Process، **نه برد فیزیکی**؛ هر Pipeline در یک Revision معتبر تنظیم می‌شود |
| `tm_pipeline_stages` | `pipeline_id,stage_key,state_key,rank,entry_rules,exit_rules,sla_policy_ref?,assignment_policy_ref?,version` | نگاشت صریح Stage→State معتبر؛ در صورت چند Stage به یک State تمایز با stage_key و guard معلوم شود |
| `tm_workflow_instances` | `id,instance_key,scope_key,subject_type,subject_id,case_id?,workflow_revision_id,pipeline_id?,current_state_key,active_stage_key?,lifecycle,slot_key,started_at,closed_at?,result_code?,version` | **تنها حقیقت وضعیت فرآیند**؛ برای Todo Instance خودکار؛ امکان چند Instance موازی در Slotهای مستقل |
| `tm_active_process_slots` | `scope_key,subject_type,subject_id,slot_key,instance_id` | UNIQUE روی ۴ جزء؛ فقط Instanceهای فعال؛ جایگزین unique index مشکل‌دار NULL/partial DB |
| `tm_instance_transitions` | `instance_id,from/to,state,stage,actor_ref,occurred_at_utc,operation_id,reason_code,workflow_revision` | append-only؛ اگر Subject=Task نیز همین منبع تاریخچه وضعیت است |
| `tm_stage_visits` | `scope_key,instance_id,stage_key,entered_at_utc,exited_at_utc?,paused_duration,elapsed_minutes?,business_minutes?,deadline_at_utc?,sla_version,result,visit_no` | هر ورود یک visit؛ دوبار برگشت دو Visit؛ Unique `(instance_id,visit_no)` |
| `tm_stage_sla_policies` | `scope/pipeline/stage,clock_kind,duration,warning_offsets,pause_states,breach_actions,working_calendar_ref,version` | clock_elapsed یا clock_business؛ مدت مثبت؛ سیاست در Visit Snapshot می‌شود |
| `tm_sla_events` | `visit_id,event_key,threshold_at,detected_at,action_state,dedupe_key,reason` | warning/breach/stale/escalated؛ Unique dedupe؛ هیچ «تسک مرده» به معنای حذف DB |
| `tm_assignment_policies` | `scope/pipeline/stage,mode,resolver_ref,primary_rule,multiple,max_assignees,return_policy,version` | Fixed/FollowOwner/Dynamic/TeamQueue/RoundRobin/Manual؛ Resolve سمت میزبان |
| `tm_instance_assignments` | `instance_id,actor_ref,kind,stage_visit_id,active_from,active_to,reason,assigned_by` | مسئولیت اجرایی فرآیند مستقل از Owner کار/Lead |
| `tm_approval_steps`, `tm_approval_votes` | `instance_id,step_key,mode,quorum,approver_ref,vote,result,decided_at` | sequential/parallel/quorum، ضد تأیید دوباره، عضویت و اختیار هنگام رأی |

### ۹.۳ ارجاع، پرونده فرآیند، تبدیل و پردازش گروهی

| جدول | فیلدهای کلیدی | قرارداد |
|---|---|---|
| `tm_process_cases` | `scope_key,case_key,title,owner_ref?,state,opened_at,closed_at?,version` | پرونده ردیابی چند موضوع، مستقل از یک Task خاص؛ ایجاد فقط در فرآیندهای نیازمند آن |
| `tm_case_subjects` | `case_id,subject_type,subject_id,relation_kind,linked_at,linked_by` | Case می‌تواند Ticket+Opportunity+Task+Contract را با هویت مستقل ردیابی کند |
| `tm_task_subject_links` | `scope_key,task_id,subject_type,subject_id,relation_key` | یک Task به چند رکورد بیرونی وصل می‌شود؛ `source_type/id` تک‌تایی قدیم نباید انحصاری باشد |
| `tm_process_handoffs` | `handoff_key,scope_key,case_id?,source_instance_id,destination_instance_id?,mode,origin_state/stage,return_policy_snapshot,owner/team refs,status,result_code,operation_id,version` | mode=`move`, `delegate`, `spawn`; history/identity حفظ؛ تکلیف بازگشت صریح |
| `tm_handoff_outcomes` | `handoff_id,result,reason_code,actor,occurred_at,correlation_id` | success/rejected/cancelled/timed_out/recalled/manual؛ append-only |
| `tm_process_groups` | `scope_key,group_key,source_type/source_id?,name,state,closed_at,closure_policy_version` | Cohort عمومی برای کمپین CRM یا دسته موارد تیکت؛ هسته خود کمپین را ذخیره نمی‌کند |
| `tm_process_group_members` | `group_id,subject_ref,instance_id?,joined_at,left_at?,enrollment_revision` | عضویت زمان‌دار؛ امکان ثبت ۱۰۰ یا ۱۰۰هزار مورد؛ Unique عضویت فعال با قید معتبر |
| `tm_group_lifecycle_policies` | `group_id,policy_revision,cutoff_type,protected_milestones,actions_by_condition,reason_schema` | سیاست پایان کمپین قابل Preview و Snapshot |
| `tm_group_operations` | `run_key,scope_key,group_id,policy_revision,cutoff_at_utc,mode,initiator_ref,state,counts,created_at,version` | اجرای Bulk قابل resume، وضعیت کلی و نتایج |
| `tm_group_operation_items` | `run_id,member_id,subject_ref,expected_instance_version,decision,result,error_code,attempts,processed_at` | Unique `(run_id,member_id)`؛ علت و نتیجه مستقل، retry-safe |
| `tm_process_milestones` | `instance_id,milestone_key,occurred_at_utc,stage_visit_id?,operation_id` | گذشتن از رزرو با رویداد واقعی؛ **نه مقایسه شماره ستون** |

### ۹.۴ عملیات، رویدادها، Audit و Integration

| جدول | فیلدهای کلیدی | قرارداد |
|---|---|---|
| `tm_audit_events` | `event_id,scope_key,aggregate_type/id,actor_ref,action,changed_field_paths,before_redacted,after_redacted,occurred_at_utc,operation_id,correlation_id` | append-only؛ رویداد فعالیت مستقل از لاگ سیستمی؛ retention/redaction |
| `tm_task_activity` | `task_id,event_id,summary_key,display_metadata` | Projection Timeline مرتبط با Task؛ حقیقت تغییرات با Audit و transitions |
| `tm_command_journal` | `scope_key,operation_id,payload_hash,status,result_ref,actor_ref,created_at` | عملیات یکسان/داده یکسان نتیجه قبلی؛ hash متفاوت conflict |
| `tm_outbox` | `event_id,scope_key,aggregate_ref,event_type,schema_version,payload,available_at,attempts,state,locked_at,published_at,last_error` | **اجباری** داخل تراکنش؛ Delivery حداقل یک بار |
| `tm_automation_rules`, `tm_automation_runs` | `trigger,conditions,actions,version,origin_event_id` | Action whitelist، bounded recursion، dedupe |
| `tm_webhook_endpoints`, `tm_webhook_deliveries` | `endpoint_ref,event_id,state,attempt` | HTTPS/HMAC/SSRF protection/retry |
| `tm_exports` | `scope_key,actor_ref,filter_hash,state,file_ref,expiry` | Export مجوزدار با revocation |

### ۹.۵ ایندکس، یکتایی و یکپارچگی

- ایندکس پایه: `(scope_key,project_id)`, `(scope_key,owner_type,owner_id)`, `(scope_key,due_at_utc)`, `(scope_key,title_search_normalized)`؛ برای Query پرمصرف compound index مبتنی بر Benchmark تعریف شود.
- Instance: `(scope_key,subject_type,subject_id)`, `(scope_key,pipeline_id,current_state_key)`, `(scope_key,case_id)`. Slot فعال با جدول رزرو اختصاصی Unique می‌شود.
- Visit/SLA: `(scope_key,deadline_at_utc)` با join scoped به Visit، `(instance_id,entered_at_utc)`, `(event_key,visit_id)`؛ index برای watchdog due.
- Group: `(scope_key,group_key)`, `(group_id,instance_id)`, `(run_id,result)`, `(scope_key,state)`. Query کمپین ۱۰۰هزاررکوردی بدون load همگی در RAM.
- متادیتای JSON جایگزین ستون‌های حیاتی Query و Index نیست. مقدار `typed_value` واحد وجود ندارد؛ انواع در ستون typed ذخیره شوند.
- Partial UNIQUE متکی بر NULL یا syntax اختصاصی DB برای active rows به‌عنوان راهکار مشترک سه DB استفاده نشود؛ جدول رزرو/تخصیص فعال یا قید portable جایگزین باشد.
- برای Lifecycle/Closure/Workflow و فرآیند خارجی از optimistic version + Locking و ترتیب ثابت قفل استفاده شود؛ immutable append-only events با حذف Task از بین نروند.
- کارایی و هزینه Schema قبل از Migration نهایی با MySQL/PostgreSQL ارزیابی شود؛ **تعداد جدول به‌تنهایی معیار شکست یا موفقیت نیست**؛ سرویس ساده Todo همه آن‌ها را لمس نکند.

## ۱۰. چرخه‌عمر Todo/Task و زیرتسک

- Create با `title`, `actor`, `scope`, `operation_id`؛ اگر Project نداده شد، `owner_ref=actor`، Inbox و Workflow دوحالته per-scope به‌طور idempotent ساخته/انتخاب می‌شوند.
- عملیات: `create/get/query/update/complete/reopen/archive/restore/softDelete/clone/convertToProject/changeType/changeOwner/reparent/reorder`، هر تغییر با VersionGuard و Audit.
- Taskهای پروژه‌ای نوع پیش‌فرض را از Project/Type map می‌گیرند؛ Parent فقط باید هم Scope و هم Project باشد، نه Workflow یکسان. Bug دارای Workflow ویژه می‌تواند فرزند Epic باشد.
- Closure Table حقیقت مشتق از `parent_task_id` است و هر Reparent subtree با update هماهنگ Closure انجام می‌شود؛ جلوگیری از Cycle در race با lock مستند.
- عمق پیش‌فرض ۳، سقف قابل تنظیم ۸. حذف والد: deny_if_children (پیش‌فرض)، cascade-soft-delete، detach؛ هرکدام نیازمند مجوز/Preview.
- Checklist مستقل از Task است، ولی هر Item با عملیات idempotent قابل تبدیل به Task مرتبط می‌شود.
- Progress پیش‌فرض والد از تعداد فرزندان قابل محاسبه، با سیاست leaf-weighted اختیاری؛ completion والد اتوماتیک **پیش‌فرض غیرفعال** و تغییر نیازمند Transition معتبر.
- Rollup مانع دوبارشماری Epic + برگ در Story Points/Velocity می‌شود. Stats projection نسخه‌دار قابل بازسازی است.
- انتقال Task به پروژه دیگر نیازمند mapping وضعیت Instance، تنظیم Assignment/Share/List و Preview اثر روی subtree/dependencies است؛ `public_key` ثابت و `display_key` جدید با Alias قبلی حفظ شود.
- Task می‌تواند به چند Subject تجاری لینک شود؛ لینک نباید Scope/Permission را دور بزند. حذف Source بیرونی حذف خودکار Task نیست.

## ۱۱. مسئولیت‌ها، مالکیت، جانشینی و تأیید چندنفره

| قابلیت | قرارداد |
|---|---|
| Owner ثابت | مرجع مالکیت پایدار Task/Process؛ تغییر با Capability جدا و Audit. Owner مساوی Assignee نیست |
| Primary Assignee | حداکثر یک مسئول پاسخ‌گوی اصلی فعال برای Task یا Instance؛ تعیین مجدد با VersionGuard |
| Multiple Assignees | صفر تا N مجری ثانویه/همکار، با سقف سیاست؛ Duplicate assignment ممنوع |
| Dynamic Assignment | تعیین مسئول از Team/Department Resolver، Queue، RoundRobin، workload یا Stage rule **قبل از فعال‌کردن مرحله** |
| Fixed Assignment | Owner/Actor تعیین‌شده در آغاز یا روی WorkItem؛ Stageها به دلخواه حفظ/override کنند |
| Watcher | مشاهده‌کننده با شرط `view` مستقل، بدون اعطای دسترسی صرفاً به‌دلیل Watch |
| Reporter/Creator | مرجع ثبت‌کننده، تاریخچه تغییر Reporter مجوز مستقل |
| Approver | نقش/شخص رأی‌دهنده در گام تأیید؛ مرحله sequential، parallel، quorum؛ رأی دوباره یا خودتأییدی ممنوع طبق Policy |
| Auto Reassign | در SLA breach، unavailable member یا ورود به Stage طبق قانون؛ با تاریخچه قبل/بعد، سبب تغییر و زمان |
| Return Assignee | هنگام برگشت از قرارداد، سیاست `restore_origin`, `keep_current`, `resolve_stage`, `manual` مشخص و Snapshot می‌شود |
| Member Departure | `reject`, `unassign`, `reassign_to_owner/team/queue` طبق سیاست، کاملاً Audit شود |

- مجوز انتقال خودکار بین Departmentها **برای Actor/ServiceActor** همان اندازه سخت‌گیر است که انتقال دستی؛ ServiceActor مطلق و فاقد Scope ممنوع.
- وظایف CRM می‌توانند Owner فروش ثابت داشته باشند و هم‌زمان در Process قرارداد Assignee قرارداد و سه Approver مستقل داشته باشند؛ مالک Lead تغییر نمی‌کند.
- سیاست تقسیم مسئولیت و تأیید در لحظه ورود به Stage روی `StageVisit` یا Approval Step snapshot می‌شود تا تغییر Definition رأی‌های قبلی را مخدوش نکند.
- خطای عدم وجود فرد واجد شرایط با `assignment_unresolvable` به صف بررسی/مرحله انتظار هدایت شود؛ **تسک به فرد تصادفی یا null غیرمجاز واگذار نشود**.

## ۱۲. موتور Workflow/State/Transition

### ۱۲.۱ تعریف و نگاشت Type

- Blueprintهای `personal`, `basic`, `agile`, `approval`, `sales`, `support` به‌صورت immutable در کد ارائه می‌شوند و هنگام اولین استفاده در هر Scope مستقل نصب/کپی می‌گردند.
- برای Task پروژه‌ای: `project × type → workflow` و در نبود آن Workflow پیش‌فرض Project؛ هنگام Create Workflow مؤثر تعیین می‌شود. برای Subject خارجی، CRM/Ticket Provider یکی از Workflowهای Scope را درخواست می‌کند.
- Workflow State دارای کلید، نام، ترتیب، category (`pending`, `in_progress`, `done`, `cancelled`, `blocked`)، terminal_kind و رنگ نمایشی است. Category سیاست عمومی است، نه نام وضعیت اختصاصی.
- Pipeline به یک Revision معتبر متصل است و Stageها به Stateهای همان Revision نگاشت می‌شوند. اگر یک State در دو Stage قرار گیرد، `active_stage_key` نیز جزو Instance است و جابه‌جایی نیازمند Transition/Route مجاز است.
- Instance همیشه Versioned و قابل Query است. Workflow Revision در حال استفاده immutable است؛ تغییر Workflow جدید نیازمند Preview/Migration Map/Approval است؛ **بازنویسی خام سابقه ممنوع**.

### ۱۲.۲ انتقال معتبر

جریان اتمیک هر Transition:

`Actor + Scope + CommandDTO → Resolve Subject/Instance → scoped SQL authorization → Lock + expected_version → allowed edge → guardهای میزبان/Task → validation/required reasons/dependency/WIP/SLA/approval → update instance/visit/assignment/milestones → audit + journal + outbox → commit → dispatch`.

- Transition می‌تواند self-transition باشد (ثبت تأیید/یادداشت) اما نباید بی‌دلیل شمار Visit را افزایش دهد.
- همه Guardها اولویت و شناسه پایدار دارند، side effect قبل از commit ممنوع؛ اقدامات خارجی در Outbox انجام شوند.
- `complete` برای Native Task به terminal-success معتبر می‌رود؛ برای Subject خارجی مثل Opportunity معادل «فروش موفق» تنها از Transition و Validator مجاز CRM انجام می‌شود.
- `reason_code` برای rejected/cancelled/failed، پایان کمپین یا مرجوعی باید از کاتالوگ دلیل Scope/Pipeline تعریف‌شده یا Registry معتبر باشد؛ متن آزاد مکمل دلیل است، نه جایگزین آن.
- برگشت از Done/Reopen فقط اگر Revision یال برگشتی و Permission دارد؛ `actual_started_at` و زمان چرخه جدید مطابق lifecycle-cycle policy ثبت شوند.
- حذف/جایگزینی Status استفاده‌شده تنها با Impact Preview، mapping تک‌تک Instanceها و تراکنش batch کنترل‌شده مجاز است.

### ۱۲.۳ قانون ورود/خروج مرحله و ظرفیت

- هر Stage شرایط ورود/خروج، فیلدهای لازم، مجوز، Assignment/Approval، ظرفیت WIP اختیاری و SLA مستقل دارد.
- شمار WIP براساس Instanceهای فعال و قابل شمارش تحت Scope/Pipeline همان Stage است؛ `closed`/`cancelled` و استثناهای مشخص از شمار خارج شوند.
- در جابه‌جایی هم‌زمان چند کار به Stage با WIP=1، یکی موفق و دیگر `capacity_exceeded` بگیرد؛ خواندن COUNT بدون lock/serialization کافی نیست.
- انتقال نمایشی بین دو Board مبتنی بر تغییر فیلتر یا assignment برد به‌صورت پیش‌فرض Transition ایجاد نمی‌کند؛ عملیات انتقال فرآیندی باید دستور صریح `moveInstance` یا `handoff` باشد.

## ۱۳. Pipeline، Board و انتقال بین آن‌ها

**قاعده:** Pipeline تعریف مسیر کار است، Board شیوه نمایش. یک Board می‌تواند چند Pipeline یا چند نوع Subject مجاز را نمایش دهد (بسته به Kanban Provider)، و یک Pipeline می‌تواند روی چند Board با فیلتر تیم/کمپین/مسئول نمایش داده شود.

سه نوع فرمان مجزا:

1. `changeBoardVisibility`: تغییر نمایش/عضویت نمایشی در Board بدون دستکاری وضعیت Domain؛ ذخیره ترتیب/فیلتر نزد Kanban یا Context رتبه مجاز.
2. `moveInstanceToPipeline`: تغییر Pipeline/Workflow برای **همان Subject و Instance** با Preview نگاشت وضعیت، Stage مقصد، Scope/Permission، SLA/Assignment و تاریخچه انتقال. اگر Workflow Revision با مقصد سازگار نیست، migration/map اتمیک لازم است؛ نمی‌توان State نامعلوم را حدس زد.
3. `handoff`: ایجاد یک جریان کاری مقصد مستقل (`delegate` یا `spawn`) و قرار دادن مبدأ در waiting/hold؛ بازگشت کنترل‌شده پس از نتیجه مقصد. برای انتقال مالکیت واحد بدون مسیر بازگشت `move` مناسب است.

**نمایش هم‌زمان در دو Board** همیشه مجاز به شرط منابع و مجوز؛ **پیشبرد هم‌زمان در دو Workflow** تنها در دو `slot_key` مستقل که از نظر قوانین تجاری تداخل ندارند مجاز است. Subject واحد به دو جایگاه فعال با `slot_key` یکسان نمی‌رود.

### ۱۳.۱ Rank و Order

- Rank با `tm_task_positions` و `context_kind/context_ref` نسخه‌دار ذخیره می‌شود. Board/Backlog/Sprint هر یک ترتیب مستقلی دارند.
- Fractional Indexing با مقدار Decimal دقیق؛ در تراکم بالا Rebalance محدود و تراکنشی؛ tie breaker `task_id`/`instance_id`.
- هر جابه‌جایی باید نسخه ترتیب Context و نسخه مرتبط را کنترل کند؛ دو Drag هم‌زمان بدون conflict خاموش آخرین نوشته را جایگزین نمی‌کنند.

## ۱۴. موتور SLA مرحله‌ای، تسک راکد و مدیریت مهلت

### ۱۴.۱ تعریف زمان و لحظه محاسبه

- SLA **به ازای StageVisit** فعال می‌شود نه فقط خود Task؛ بازگشت به Stage بازدید و ساعت جدید ایجاد می‌کند. سیاست تجمیع Visitهای قبلی (`reset_on_reentry` پیش‌فرض، `cumulative` اختیاری) نسخه‌دار است.
- `clock_kind=elapsed` بر دقیقه واقعی UTC و `clock_kind=business` بر WorkingCalendarProvider با timezone IANA، ساعات، تعطیلات و آخرهفته محاسبه می‌شود. Calendar انتخابی می‌تواند زمان شروع/پایان شیفت و تعطیلات میزبان را بدهد؛ نبود Provider با سیاست fail-closed یا default explicit تعیین شود.
- نقطه شروع پیش‌فرض لحظه Commit ورود به Stage است؛ امکان `start_on_assignment` یا `start_on_first_action` فقط با پیکربندی صریح و ثبت Anchor.
- خروج موفق، لغو، انتقال، آرشیو و Pause کنترل‌شده روی Visit زمان خروج ثبت می‌کند؛ مدت pause واقعی از Intervalهای بازه‌ای محاسبه می‌شود و محاسبه negative duration رد می‌شود.
- SLA شامل `due_at_utc` محاسبه‌شده و snapshot version است؛ تغییر تنظیمات بعدی نباید مهلت تاریخی یک Visit را بی‌صدا بازنویسی کند. تغییر عمدی SLA جاری با `recalculateWithPreview` و audit مجاز.
- ساعتِ انقضا همان `now >= deadline_at` است. تشخیص SLA به UI وابسته نیست؛ Scheduler/Queue با اسکن indexدار due eventها کار می‌کند. Realtime فقط اطلاع می‌دهد.

### ۱۴.۲ وضعیت‌های SLA در برابر وضعیت کار

| SLA state | تعریف و رفتار |
|---|---|
| `healthy` | زمان باقی‌مانده مثبت و کمتر از محدوده هشدار نشده |
| `warning` | به Threshold هشدار رسیده، هنوز مهلت پایان نیافته |
| `breached` | `now >= deadline` و Visit هنوز فعال؛ نقض ثبت شده |
| `stale` | عدم فعالیت معنادار برای `inactive_for_minutes`، مستقل از deadline کل |
| `dead_candidate` | مطابق سیاست چند شرطی (مثلاً breach+stale)، کاندیدای خروج/بازبینی، **نه پاک‌شده** |
| `resolved` | Visit پایان یافته یا نقض با عملیات مجاز حل شده |
| `paused` | SLA با دلیل مصوب متوقف؛ زمان باقی‌مانده حفظ |

`Task.status`، `WorkflowInstance.state` و `SLA state` سه مفهوم متفاوت‌اند؛ نباید صرفاً با انقضای SLA، حالت تجاری `failed` شود.

### ۱۴.۳ اقدامات زمان‌دار و ترتیب

- هر Stage صفر تا چند `warning_offset`، یک breach action و optional stale action دارد.
- Actionهای مجاز: `mark`, `notify`, `escalate`, `reassign`, `move_stage`, `fail_with_reason`, `cancel_with_reason`, `queue_manual_review`, `trigger_external_event`؛ پیش‌فرض **Mark + Notify** است؛ اقدام مخرب نیازمند rule مصوب و Permission سرویس.
- ترتیب هر اجرا: Resolve Actor/Scope → lock Visit/Instance → verify still active and threshold version → policy guard/CRM veto → action via service → journal/audit/outbox → commit. هر threshold با `(visit_id,policy_version,event_key)` dedupe شود.
- Retry و دو Worker هم‌زمان فقط یک alert/transition انجام دهند. اگر انتقال قبل از اجرای Watchdog رخ داده، اقدام انقضا نباید بر Stage جدید اثر بگذارد.
- Pause فقط با `sla.pause` Capability، دلیل، شروع/پایان و سقف مجاز. اقدامات خودکار نباید براساس محاسبه قدیمی بعد از Pause اجرا شوند.
- اگر stage deactivated/closed شد، Jobهای معلق آن فقط Mark stale/re-evaluate می‌شوند و هیچ انتقال جدیدی نمی‌سازند.

### ۱۴.۴ SLA در ارجاع و بازگشت

- هنگام Handoff، SLA مبدأ طبق سیاست `pause_origin` (پیش‌فرض برای قرارداد)، `continue_origin` یا `close_origin` رفتار می‌کند و Snapshot می‌شود.
- SLA مقصد مستقل است؛ زمانی که کار قرارداد طی می‌کند به‌صورت پیش‌فرض به SLA فروش مبدأ اضافه نمی‌شود.
- پس از موفق/لغو فرایند مقصد، Stage و Deadline مبدأ براساس ReturnPolicy از Snapshot بازیابی یا دوباره محاسبه می‌شود؛ **مفهوم Resume با Reset یکی نیست**.
- آمار `business_minutes`, `elapsed_minutes`, `breach_count`, `pause_duration`, `stale_count` باید به تفکیک Stage/Instance/Team/Campaign قابل استخراج باشند.

## ۱۵. پایان کمپین و سیاست تعیین تکلیف دسته‌ای (Group Lifecycle)

### ۱۵.۱ مرز دامنه کمپین

- Campaign متعلق به CRM است (محصول/دسته‌بندی، نرخ/رزرو/پیشنهاد، اعتبار فروش). Task Manager فقط یک Group عمومی شامل Subject و Instance ثبت می‌کند و قوانین اتمام/ادامه را اجرا می‌کند.
- Group می‌تواند تمام ۱۰۰ Task/Opportunity مربوط به یک Campaign را با `source_type='crm.campaign'` پیوند دهد؛ عضویت ثبت‌شده و مستقل از Query لحظه‌ای است. افزودن/خروج عضو بعد از Cutoff تابع policy.
- `campaign_finished` یک Event واردشده از CRM با `external_event_id`, `cutoff_at_utc`, `scope`, `group_key` و auth service محدود است؛ **زمان محلی یا کلاینت حق انتخاب Cutoff ندارد**.

### ۱۵.۲ نمونه قانون دقیق سناریوی فروش

پایپ‌لاین نمونه: `lead → contacted → negotiation → reservation → contract → won/lost`.

- اگر Instance **قبل از Milestone `reservation_passed`** باشد و terminal نباشد، نتیجه `failed` با `reason_code=campaign_ended` و `reason_source=crm.campaign`.
- اگر Stage رزرو را باز کرده اما هنوز Milestone معتبر `reservation_passed` را ثبت نکرده، به‌صورت پیش‌فرض ناموفق می‌شود؛ صرف نام Stage یا خروج ظاهری از ستون کافی نیست.
- اگر `reservation_passed` در تاریخچه معتبر **تا Cutoff** ثبت شده، ادامه مسیر قرارداد/فروش مجاز است؛ کمپین تمام شده باشد یا نه.
- Instanceهای terminal success/terminal failure/archived از تغییر مجدد مستثنی‌اند مگر policy صریح `include_terminal` (پیش‌فرض false).
- Instance فعال در قرارداد واگذار‌شده، مطابق snapshot Handoff و Protected Milestone ادامه می‌دهد؛ صرف بسته‌شدن Campaign مبدأ نباید آن را Cancel کند.
- در موارد دارای Transition اختصاصی CRM که شکست را ممنوع می‌کند، اجرای گروهی نتیجه `requires_manual_review` ثبت کند؛ دور زدن validator CRM ممنوع.

### ۱۵.۳ پیش‌نمایش، اجرای قطعی و رقابت هم‌زمان

1. مدیر مجاز `previewGroupClosure` را با policy revision، زمان Cutoff و Scope اجرا می‌کند؛ خروجی شامل تعداد *مجاز برای مشاهده*، نمونه دلایل و انواع اقدام است.
2. `executeGroupClosure` به همان Revision و Cutoff و یک Operation ID وابسته است؛ بعد از آغاز، Group به `closing` می‌رود و enrollment جدید یا رد یا با سیاست مشخص جداگانه ثبت می‌شود.
3. برای هر عضو، تصمیم با **تاریخچه Milestone و Transition تا لحظه Cutoff** ارزیابی و version هدف بررسی می‌شود؛ تغییر هم‌زمان بعد از Cutoff بدون جابه‌جایی ریشه‌ای سیاست مجاز نیست.
4. Workerها با chunk و Keyset Pagination و claim/lease ردیف (`tm_group_operation_items`) پردازش می‌کنند، نه OFFSET بر مجموعه در حال تغییر. کار حجیم async و قابل resume است.
5. در مرحله Commit هر Item: re-read + lock instance + guards + optimistic version + action + reason + audit/journal/outbox، سپس mark item result. شکست یک Item به‌صورت پیش‌فرض سایر اعضا را rollback نمی‌کند (`partial_success`).
6. نتیجه هر Item یکی از `failed`, `continued`, `already_terminal`, `skipped_protected`, `permission_denied`, `conflict_retryable`, `manual_review`, `error` است. `conflict_retryable` با backoff محدود دوباره بررسی می‌شود.
7. پایان Run: `completed`, `completed_with_exceptions` یا `failed` به همراه شمار دقیق و لیست Exceptionها؛ گزارش امنیتی only authorized؛ سیاست immutable و قابل Audit.
8. همان `run_key` با payload hash برابر نتیجه Run را می‌دهد و با payload متفاوت `idempotency_key_conflict`؛ Run مجدد آگاهانه نیازمند `new_run_key` و Preview تازه است.

### ۱۵.۴ مقررات ویژه و موارد گوشه

- `reservation_passed` باید با Transition معتبر یا رویداد مورد اعتماد CRM در `tm_process_milestones` ثبت شود؛ امکان جعل client-level ممنوع. تاریخچه قابل ویرایش عادی نیست.
- اگر Lead ساعت ۱۱:۵۹ Milestone را گذرانده و Cutoff ساعت ۱۲:۰۰ است، اجازه ادامه؛ اگر ساعت ۱۲:۰۱ باشد، ادامه خودکار بدون policy استثنا ممنوع. **سیاست اثرگذاری بر انتقال‌های late/in-flight** در زمان Cutoff: تراکنش Commitشده قبل از Cutoff معتبر، عملیات درحال اجرا بر اساس ترتیب commit DB و revision معتبر ارزیابی می‌شود.
- زمان قطع دسترسی داده بیرونی CRM یا فیلد فروش موردنیاز: fail-closed و manual-review، نه فرض ناموفق یا ادامه مجاز.
- قابلیت `dry_run`/Preview باید به همان predicateهای اجرا متصل باشد، اما تضمین یکسان‌بودن داده در فاصله Preview تا اجرا نمی‌دهد؛ هر تفاوت با `preview_stale` یا خلاصه Delta گزارش می‌شود.
- سیاست `undo` به‌طور پیش‌فرض **بازگردانی خام تراکنش‌های موفق نیست**؛ عملیات اصلاحی مستقل با مجوز، Workflow و Audit انجام می‌شود.
- عمر Campaign و Group بعد از Close می‌تواند خواندنی بماند؛ حذف Campaign نباید Activity/Transition مرتبط را از بین ببرد.

## ۱۶. ارجاع، انتقال، بازگشت و اجرای چندفرآیندی (Handoff)

### ۱۶.۱ انواع مدل اجرا

| Mode | کاربرد | رفتار شناسه و برگشت |
|---|---|---|
| `move` | تیکت همان Subject به Department مقصد با Workflow جدید منتقل می‌شود | یک Subject؛ Instance با mapping سازگار ادامه می‌یابد یا اجرای جانشین کنترل‌شده، مبدأ بسته می‌شود؛ برگشت خودکار ندارد مگر policy |
| `delegate` | فروش قرارداد را برای انجام موقت به تیم قرارداد واگذار می‌کند | مبدأ در waiting/paused؛ **Instance مقصد مستقل**، ReturnPolicy اجباری |
| `spawn` | برای فرآیند جدید Subject جدید ساخته می‌شود، مثل Ticket→Opportunity | **دو Subject مستقل** و Instanceهای مرتبط با یک Case؛ رابطه conversion ثبت، وضعیت Ticket طبق Policy تغییر می‌کند |
| `parallel` | چند فرآیند مستقل مجاز برای یک Subject (فروش+ریسک+حقوقی) | Slotهای مستقل و Exit/Join مشخص؛ بدون دو وضعیت برای یک Slot |

هر Handoff `origin`, `destination`, `initiator`, `reason`, `target_team`, `target_workflow_revision`, `return_policy_snapshot`, `expiry`, `operation_id` و `correlation_id` دارد. ایجاد مقصد با Permission و قرارداد پذیرش Department مقصد کنترل می‌شود.

### ۱۶.۲ قواعد ارجاع و برگشت

- `delegate` فقط وقتی مجاز است که مقصد، Subject/نوع Case لازم را بپذیرد و Pipeline مقصد با Scope و مجوز سازگار باشد.
- در شروع: مبدأ یک State `waiting_external`/on-hold معتبر یا pause flag ثبت‌شده می‌گیرد؛ State قبلی/StageVisit/Assignee/SLA/Workflow revision در Handoff snapshot می‌شود. حالت معلق نباید به پایان موفق اشتباه گرفته شود.
- مقصد در Slot مستقل باز می‌شود؛ برای Handoff تکراری هم‌زمان uniqueness و lock تضمین کند یک اجرای مقصد مضاعف ساخته نشود.
- Outcomeهای پایدار: `approved`, `rejected`, `cancelled`, `timed_out`, `recalled`, `manual_resolution`, `error`. جدول ReturnPolicy برای هر Outcome، destination state/transition در مبدأ، reason requirements، SLA resume/reset، assignment restore/resolve و actionهای میزبان دارد.
- Outcome پذیرفته‌شده با نتیجه تکراری idempotent است؛ Outcome متناقض با version یا `handoff_already_resolved` رد شود.
- اگر مبدأ پس از شروع Handoff آرشیو/لغو/تغییر یافته باشد، ReturnPolicy نمی‌تواند بی‌صدا وضعیت را Overwrite کند؛ `return_conflict` و صف بررسی ایجاد می‌شود.
- اگر چند Handoff موازی به یک مبدأ وابسته‌اند، قانون Join باید از ابتدا مشخص باشد: `all`, `any`, `quorum`, `manual`. هر Outcome مستقل ثبت و تنها با تحقق شرط، مبدأ Resume شود.
- Handoff چرخه‌ای بی‌نهایت رد شود: محدودیت `max_active_depth` پیش‌فرض ۵، lineage graph و cooldown/re-entry policy؛ ارجاع مشروع A→B→A فقط با تعریف چرخه/Return صحیح، نه حلقه خودکار نامحدود.
- نمایش کارت در Board مبدأ هنگام واگذاری می‌تواند «منتظر قرارداد» باشد و در Board مقصد هم کارت فرایند قرارداد نمایش داده شود؛ **کارت‌ها به دو Instance متفاوت اشاره دارند** و وضعیت هرکدام جدا خوانده می‌شود.
- برای گزارش واحد، هر دو Instance زیر Case و correlation واحد هستند؛ فیلتر منبع/Permission تعیین می‌کند کاربر چه جزئیاتی را می‌بیند.

### ۱۶.۳ عملیات در چند سرویس و چند پایگاه‌داده

- اگر CRM/Ticket/Task Manager از **همان اتصال DB و transaction boundary واقعی** استفاده کنند، Coordinator امکان Commit اتمیک تغییر Domain و Instance را دارد؛ این قابلیت با تست واقعی اثبات شود.
- اگر سرویس‌ها/DB جدا هستند، atomic commit سراسری ادعا نشود: الگوی Saga/Outbox با `pending_external`, `confirmed`, `compensating`, `needs_manual_repair` و retry idempotent لازم است. Status خارجی تا confirm نهایی به‌عنوان موفق قطعی اعلام نمی‌شود.
- تغییر `department_id` یا ایجاد Opportunity از **Domain Service مالک** انجام می‌شود؛ Task Manager فقط Route/Case/Outcome را نگه می‌دارد. اگر ایجاد Opportunity شکست خورد، Handoff به «موفق» تغییر نمی‌کند و Ticket بدون مسیر اصلاح رها نمی‌شود.
- هر transition خارجی ورودی باید source event ID قابل اعتماد و `actor_ref` مشخص داشته باشد؛ replay رویداد Outcome دوم تولید نمی‌کند.

### ۱۶.۴ سناریوی Ticket → Sales با قرارداد دقیق

1. مشتری Ticket در پشتیبانی ثبت می‌کند؛ Subject=`support.ticket#728`، Instance با Pipeline support، Case اختیاری/خودکار براساس Policy.
2. کارشناس با دلیل `sales_opportunity_identified` درخواست Handoff به Team فروش می‌دهد. `destination` باید Ticket را پشتیبانی کند یا سیاست `spawn_opportunity` انتخاب شود.
3. **روش پیشنهادی CRM:** سرویس CRM یک Opportunity جدید ایجاد می‌کند؛ `tm_case_subjects` هر دو را به `CASE-...` لینک می‌کند؛ Ticket از مسیر مجاز به `referred_to_sales` می‌رود؛ Opportunity وارد Pipeline فروش می‌شود.
4. Ticket اصلی، پیام‌ها، فایل‌ها و مجوزهای پشتیبانی حفظ می‌شوند؛ فروش تنها اطلاعات مجاز/خلاصه انتخابی دریافت می‌کند، نه کل گفت‌وگوی محرمانه.
5. Owner مشتری/Lead و Responsible در فروش با Resolver تعیین می‌شود؛ assignment پشتیبانی به‌عنوان history می‌ماند. ورودی‌ جدید فروش از راهنماهای CRM پیروی می‌کند.
6. شکست ایجاد Opportunity یا رد صلاحیت فروش: Handoff `failed`/`rejected` می‌شود و Ticket طبق سیاست به «در انتظار رسیدگی» برمی‌گردد؛ هیچ Opportunity نیمه‌ثبت‌شده‌ای بدون repair/correlation رها نمی‌شود.
7. اگر میزبان واقعاً بخواهد **همان Ticket** در برد فروش پیش رود، `move` فقط در صورت پذیرش Source Ticket/Workflow map/Permission فروش مجاز است؛ Ticket تبدیل ماهیتی نمی‌شود.

## ۱۷. Process Case و تاریخچه کامل فعالیت‌ها

### ۱۷.۱ چه چیزهایی ثبت شوند؟

- Created/Updated/Deleted/Restored/Archived؛ قبل و بعد فیلدهای مجاز؛ مالک/تخصیص مسئول و علت تغییر.
- ورود/خروج Stage، Duration، SLA warning/breach/pause/stale، Actorِ اقدام و ماشین/سرویس معتبر.
- هر Transition؛ Workflow revision، rule/guard decision و `reason_code`؛ *رد اعتبارسنجی* می‌تواند در Security/Decision Audit (با حذف داده حساس) ثبت شود، اما نباید به‌اشتباه «Transition موفق» شمرده شود.
- Handoff start/accept/reject/recall/return و وضعیت‌های موازی؛ Origin/Destination و Outcome.
- Conversion Ticket→Opportunity، پیوند Case به Subject و تغییر Department؛ عملیات Domain واقعی بیرونی با callback معتبر و correlation ثبت شوند.
- تغییر Checklist/Comment/Attachment/Dependency/Approval/Timer/Reminder؛ برای Comment و Attachment اصل محرمانه محتوا در Audit خام ذخیره نشود.
- Bulk policy Preview/Run/Item/Exception؛ Start/End Campaign گروه؛ شناسه Rule Revision.
- Automation و Jobهای خودکار با ServiceActor، زمان، Operation ID، دلیل و correlation.

### ۱۷.۲ ویژگی‌های Activity/Audit

- Append-only و Immutable از API عادی؛ تغییرات redaction/retention نیازمند مجوز و اثر مستقل Audit هستند.
- `tm_audit_events` تاریخچه فنی/حسابرسی؛ `tm_instance_transitions` برای گزارش وضعیت؛ `tm_stage_visits` برای مدت؛ `tm_task_activity` برای Timeline نمایش. JSON event عمومی تنها منبع گزارش زمانی نیست.
- `caseTimeline(caseId,...)` با Cursor پایدار و فیلتر مجوز روی تک‌تک subjectها/فیلدها؛ رخدادها cross-department به‌صورت خودکار قابل دیدن نیستند.
- قبل/بعد، actor, ip/userAgent اختیاری از Context ورودی trusted، correlation و زمان UTC؛ برای داده حساس Policy mask/redact اجباری.
- حفظ نسخه Schema رویداد؛ consumer قدیمی با payload جدید خطای شفاف دهد و منجر به حذف رویداد نشود.

## ۱۸. برنامه‌ریزی، تکرار، یادآور و تقویم کاری

- `starts_at_utc`, `ends_at_utc` بازه **نیمه‌باز** `[start,end)`؛ `due_at_utc` لحظه مهلت مستقل است؛ All-day در تقویم محلی با end exclusive تعریف می‌شود.
- زمان در DB UTC و همراه IANA timezone برای نمایش/محاسبه؛ DST ambiguous/nonexistent با policy `reject/earliest/latest` صریح. تاریخ «فقط روز» با timestamp اشتباه نشود.
- تکرار با `calendar_system=gregorian|jalali` واقعی؛ RRULE برای میلادی و موتور جدا برای شمسی (مثلاً هر ماه روز ۲۵ شمسی). روز ناموجود skip/last_day؛ هفته آغاز شنبه و روزهای تعطیل پیکربندی‌پذیر.
- وقوع‌های materialized با `recurrence_id+occurrence_key` یکتا، preview مجازی read-only تا در Calendar دوباره‌کاری نشود؛ Job هم‌زمان وقوع دوگانه نسازد.
- Reminderهای anchor-relative (`due/start/absolute`) با offset، quiet hours/notification provider، Dedupe و Reschedule در تغییر زمان؛ ارسال حقیقی بر عهده میزبان.
- تقویم کاری Worker با Provider میزبان (روزهای کاری، شیفت، تعطیلات، توقف)؛ SLA Business clock و lag وابستگی هر دو از همین قرارداد استفاده کنند.
- Normalized Persian Search: `ي/ی`, `ك/ک`, تبدیل اعداد، ZWNJ و فاصله؛ title اصلی ثابت و `title_search_normalized` versioned/indexable، در صورت تغییر الگوریتم Reindex کنترل‌شده.

## ۱۹. وابستگی Taskها و تقویم زمانی

چهار رابطه زمان‌دار و قابل enforce در Profile پیشرفته:

| نوع | زمان مجاز جانشین | شرط |
|---|---|---|
| `FS` | شروع | `succ.start >= pred.finish + lag` |
| `SS` | شروع | `succ.start >= pred.start + lag` |
| `FF` | تکمیل | `succ.finish >= pred.finish + lag` |
| `SF` | تکمیل | `succ.finish >= pred.start + lag` |

- برای Actual clock، `start/finish` از تاریخچه معتبر و چرخه جاری استخراج می‌شود؛ نبود مقدار پیش‌نیاز اجازه خودکار ایجاد نمی‌کند. Planned dates با Guard مستقل، warning/strict policy.
- `lag_minutes` می‌تواند مثبت/منفی و elapsed یا business مطابق policy باشد؛ در lag منفی و pre-start باید محدودیت و سناریو تعریف شود.
- گراف بی‌چرخه؛ race افزودن A→B و B→A با قفل/serialization و تست DB واقعی. cross-project در Scope و با مجوز دوطرفه؛ cross-scope ممنوع.
- روابط `relates_to/duplicates/clones` blocking نیستند. Reschedule وابستگان فقط با Preview و تأیید؛ ناتوانی در محاسبه Critical Path `insufficient_data` را بازمی‌گرداند.

## ۲۰. Sprint، Backlog، Time Tracking، Custom Fields و گزارش

- Project دارای انواع Task، Epic/Story/Bug؛ Sprint می‌تواند Planned/Active/Completed/Cancelled باشد؛ Velocity فقط Taskهای برگ/قابل شمارش را با Snapshot committed points حساب می‌کند، نه Epic+فرزند با هم.
- Backlog و Sprint Rank مستقل، scope-aware و versioned دارند؛ Story می‌تواند در Sprintهای تاریخی بوده و membership تاریخ‌دار باشد، اما هم‌زمانی Sprint فعال طبق policy.
- Milestone/Release با Taskهای مرتبط و ددلاین مستقل از Sprint؛ progress پیش‌فرض از Aggregate واقعی.
- Time Tracking با Start/Stop، `ended_at_utc`, Note، `duration_minutes` معتبر و یک تایمر فعال per-Actor در Scope پیش‌فرض؛ retries موجب TimeEntry تکراری نشود.
- Custom Fields از نوع Text/LongText/Number/Bool/Date/DateTime/SingleSelect/MultiSelect/ActorRef/URL و validation/read permissions؛ index برای sort/filter فقط روی Typed fields تعریف‌شده.
- Reports: My Tasks, overdue, blocked, workload, Cycle/Lead Time از `tm_instance_transitions`, Stage dwell/breach از `tm_stage_visits`, Pipeline conversion, Group closure outcomes, Sprint Snapshot/Burndown/Velocity, TimeSpent, Case Timeline, Actor workload و SLA escalation.
- Reportها براساس permitted SQL scope و As-of timestamp، نه صرفاً لیست کارت‌های بارگذاری‌شده؛ totals با loaded counts تفکیک شوند.

## ۲۱. API عمومی، Facade و تجربه توسعه‌دهنده

### ۲۱.۱ قواعد طراحی API

- یک Root Facade سبک و **Stateless**: `TaskManager`؛ سرویس‌های تخصصی type-safe برای Task, List, Project, Workflow, Pipeline, Instance, Handoff, Case, SLA, Group, Assignment, Approval, Planning, Reports.
- همه عملیات mutating از `CommandDTO` تایپ‌شده با `operationId`, `expectedVersion` در تغییرهای موجود، `reason`, `metadata` whitelist عبور کنند. خروجی موفق `ResultDTO` با شناسه، revision، changes و next-actions؛ خطا typed با code ثابت.
- Context immutable: `TaskManager::forActor($actor)->inScope($scope)`؛ امکان `withCorrelation()` و `withTimezone()`؛ chain هیچ mutable singleton cross-request نداشته باشد.
- `TaskManager::tasks()` به تنهایی می‌تواند سرویس را برگرداند، اما **اجرای read/write بدون Context باید fail-closed** شود. Resolver ambient فقط در Adapterهای لبه Laravel مجاز و هیچ‌وقت در Domain Service نیست.
- برای Todo در Single-Scope پیکربندی‌شده `TaskManager::personal($actor)->tasks()->create($dto)` میانبر مجاز است؛ نه اینکه Scope مبهم به همه داده‌ها برسد.
- Bulk و Workflowها فاقد API «تغییر مستقیم column `status_id`» هستند؛ Handler باید از Command معتبر استفاده کند.
- API عمومی facade براساس عمل domain نام‌گذاری شود، نه ساختار جداول؛ internal repository/transaction قابل تعویض ولی قرارداد پایدار.

### ۲۱.۲ الگوی فراخوانی مفهومی (نیازمند بازبینی سورس الگوی حسابداری/انبارداری)

| Facade / Service | نمونه قرارداد منطقی | اثر |
|---|---|---|
| `TaskManager::forActor(...)->inScope(...)->tasks()` | `create(CreateTaskData)`, `update(UpdateTaskData)`, `complete(CompleteTaskData)`, `query(TaskQuery)` | Todo/Task |
| `->lists()` | `create`, `moveTask`, `archive`, `query` | Inbox و Personal Lists |
| `->projects()` | `create`, `addMember`, `changeSettings`, `previewTaskMove` | پروژه و اعضا |
| `->workflows()` | `createDefinition`, `publishRevision`, `previewMigration`, `migrateInstances` | تعریف نسخه‌دار |
| `->pipelines()` | `create`, `defineStages`, `setStagePolicy`, `query` | Pipeline/Stage، نه Board UI |
| `->instances()` | `start(StartInstanceData)`, `transition(TransitionInstanceData)`, `query(InstanceQuery)` | تغییر وضعیت Task یا Subject بیرونی |
| `->handoffs()` | `preview`, `start`, `accept`, `resolve`, `recall`, `query` | حرکت و برگشت بین فرایندها |
| `->cases()` | `open`, `linkSubject`, `timeline`, `close` | Case چندمدلی |
| `->sla()` | `evaluate`, `pause`, `resume`, `recalculatePreview`, `listBreaches` | Visit SLA |
| `->groups()` | `register`, `enroll`, `previewClosure`, `executeClosure`, `resumeRun`, `report` | پایان کمپین گروهی |
| `->assignments()` | `setPrimary`, `addSecondary`, `reassignStage`, `history` | مسئولیت ثابت/پویا |
| `->approvals()` | `defineStep`, `vote`, `decision` | چندتأییدکننده |
| `->reports()` | `pipeline`, `stageAging`, `sla`, `groupOutcome`, `project`, `sprint`, `activity` | گزارش امن |
| `->integration()` | `registerSubject`, `registerActionHandler`, `mapExternalEvent` | CRM/Order/Ticket |

**این نام‌ها «امضای تثبیت‌شده PHP» نیستند**؛ در Design Review نام کلاس DTO، return type و exceptionها در ADR نهایی قفل شوند. عبارت‌های نمونه در این جدول به معنی وجود متد در نسخه نصب‌شده نیست.

### ۲۱.۳ Contracts اجباری برای میزبان

- `ActorResolver`, `ScopeResolver`, `AuthorizationProvider` با Query Scope و رکورد Authorization.
- `SubjectRegistry`, `SubjectResolver`, `SubjectActionHandler`, `SubjectQueryProvider`, `SourcePermissionProvider` برای External Subjects.
- `TeamDirectoryProvider`, `DepartmentResolver`, `AssignmentResolver`, `ApprovalResolver` برای مرحله و مسئولیت.
- `WorkingCalendarProvider`, `TimeZoneProvider`, `NotificationDispatcher`, `AttachmentStorageAdapter`, `QueueDispatchAdapter`.
- `ExternalDomainCoordinator` با دو حالت `same_db_atomic` یا `saga_outbox`; **Adapter بدون اعلام قابلیت atomicity نباید مدعی commit اتمیک باشد**.
- `PolicyGuardRegistry` و `ActionRegistry` با whitelist ثبت‌شده؛ پکیج کد arbitrary از DB eval نکند.

### ۲۱.۴ DTOها و خطاها

**Command DTOهای حداقلی:** `TaskCreate`, `TaskUpdate`, `TaskTransition`, `WorkflowPublish`, `PipelineMove`, `HandoffStart`, `HandoffResolve`, `SlaPause`, `AssignmentChange`, `GroupClosurePreview`, `GroupClosureExecute`, `CaseLinkSubject`, `ApprovalVote`, `ReminderSchedule`, `RecurrenceDefine`. همه typeهای enum/string-key نسخه‌پذیر و validate می‌شوند.

| Code | معنی |
|---|---|
| `context_missing`, `scope_mismatch`, `not_found`, `forbidden` | Context یا مجوز/محدوده نامعتبر؛ بدون افشای وجود رکورد |
| `validation_failed`, `reason_required`, `workflow_mapping_missing` | ورودی/نگاشت ناقص |
| `transition_not_allowed`, `subject_guard_denied`, `capacity_exceeded` | قوانین حرکت |
| `version_conflict`, `idempotency_key_conflict`, `duplicate_active_slot` | هم‌زمانی/تکرار |
| `task_hierarchy_cycle`, `dependency_cycle`, `task_blocked` | گراف |
| `assignment_unresolvable`, `approval_not_allowed`, `approval_already_cast` | مسئول/تأیید |
| `sla_policy_invalid`, `sla_visit_closed`, `stale_policy_version` | SLA |
| `handoff_already_resolved`, `return_conflict`, `handoff_loop`, `destination_not_accepted` | ارجاع |
| `campaign_group_closed`, `preview_stale`, `group_policy_conflict` | پایان کمپین |
| `integration_unavailable`, `external_action_pending`, `external_action_failed` | سرویس میزبان |
| `rate_limited`, `export_permission_revoked` | بارگذاری و گزارش |

## ۲۲. امنیت، Capability و مجوزهای جزئی

**قاعده عمومی:** `scopeQuery(actor,query,capability,context)` و `authorize(actor,subject,capability,context)` هر دو لازم‌اند؛ Count/Facet/Report/Export/CaseTimeline از همان predicate استفاده می‌کنند. داشتن عضویت Board یا Case permission اضافی ایجاد نمی‌کند.

### ۲۲.۱ Capability Catalog

| گروه | کلیدهای پایدار (حداقل) |
|---|---|
| Task | `task.view`, `task.create`, `task.update`, `task.delete`, `task.restore`, `task.archive`, `task.transition`, `task.assign`, `task.change_owner`, `task.change_reporter`, `task.reparent`, `task.move_project`, `task.share`, `task.clone`, `task.reorder` |
| Project | `project.view`, `project.create`, `project.update`, `project.archive`, `project.manage_members`, `project.manage_workflows`, `project.manage_types`, `project.manage_settings` |
| Process | `pipeline.view`, `pipeline.manage`, `workflow.define`, `workflow.publish`, `workflow.migrate`, `instance.view`, `instance.start`, `instance.transition`, `instance.move`, `process.case.view`, `process.case.link`, `handoff.start`, `handoff.accept`, `handoff.resolve`, `handoff.recall` |
| Responsibility | `assignment.manage`, `assignment.reassign`, `approvals.define`, `approvals.vote`, `approvals.override` |
| SLA & Group | `sla.view`, `sla.manage`, `sla.pause`, `sla.override`, `group.manage`, `group.preview`, `group.execute`, `group.review_exceptions` |
| Content | `checklist.manage`, `comment.create`, `comment.edit_own`, `comment.moderate`, `attachment.add`, `attachment.remove`, `attachment.download`, `label.manage`, `watcher.manage` |
| Planning | `dependency.manage`, `schedule.update`, `reminder.manage`, `recurrence.manage`, `sprint.manage`, `backlog.manage`, `milestone.manage`, `release.manage`, `time.log`, `time.manage_others` |
| Advanced | `template.manage`, `custom_field.manage`, `automation.manage`, `webhook.manage`, `export.create`, `report.view`, `audit.view`, `migration.manage` |

`owner/admin/manager/member/viewer` **Role preset** هستند نه bypass؛ Role→Capability به پروژه/Scope/Department نگاشت می‌شود. مجوزهای External Subject باید از مجوزهای CRM/Ticket/Order **تقاطع** گرفته شوند، نه اجتماع بدون قید.

### ۲۲.۲ شرایط امنیتی غیرقابل معامله

- IDOR در Task، Instance، Case، Approval، Reminder، Attachment، Group و Reports؛ دسترسی به Parent مجوز مستقل به Child و بالعکس نمی‌دهد.
- ریسک نشت Timeline: کارشناس فروش نباید بدون Scope/Role پیام‌ها یا ضمیمه‌های محرمانه پشتیبانی را در Case ببیند.
- Automation/Scheduler با ServiceActor و Scope محدود و Permissionهای whitelisted؛ استفاده از `system` نامحدود یا `auth()` در Queue ممنوع.
- تغییر عضویت، Share و Department باید caching permission را versioned invalidate کند؛ view در همان عملیات recheck شود.
- توضیح/Markdown/Comment هنگام رندر escaped و sanitize شوند؛ نام/label بدون اجرای HTML خام.
- URL فایل‌ها signed/short-lived و قابل revoke؛ metadata حساس از event/public API حذف یا redact شود.
- عملیات GroupClosure/Handoff/Bulk دارای authorization برای **هر Item** با گزارش خطاهای مجاز باشند؛ count کلی غیرمجاز می‌تواند leak باشد.
- Rate limit per Actor/Scope/API، سقف محتوای Comment/Metadata/Attachment و max_handoff_depth، max_items_per_bulk، max_children مشخص باشد.
- Schema reason_code و payload actions validated/whitelisted؛ هیچ callback دلخواه از DB اجرا نشود.
- عملیات external handler و webhook مقاوم به SSRF/redirect/private IP/rebinding باشد؛ secret در vault میزبان نه payload audit.

## ۲۳. هم‌زمانی، Atomicity، Journal و Outbox

- همه Commandهای ایجاد `operation_id` اجباری؛ هر Command ویرایش `expected_version` یا VersionGuard صریح + operation_id دارد؛ retry همان payload→نتیجه قبلی، همان ID با payload متفاوت→`idempotency_key_conflict`.
- Handoff/Return/Group Item/StageVisit/Assignment/Approval/Dependency/Recurrence در یک DB Transaction مربوطه atomic تغییر می‌کنند و رکوردهای مرتبط به ترتیب ID ثابت lock می‌شوند. Deadlock DB ممکن است رخ دهد؛ backoff/retry محدود روی Conflict طبقه‌بندی‌شده.
- `tm_outbox` **در همه mutationهای مورد انتظار Event دامنه اجباری** است؛ Dispatch sync/queue انتخاب می‌شود ولی Outbox persist می‌شود. Crash بین commit و dispatch قابل Recovery است.
- انتشار **at least once** است، مصرف‌کننده با `event_id` dedupe می‌کند؛ exactly-once سراسری ادعا نشود.
- Group closure با ۱۰۰هزار Member عملیات `partial_success` دارد؛ Atomicity بین کل اعضا تضمین نمی‌شود و گزارش transparent است. تغییر تعریف Pipeline/Workflow جمعی policy `all_or_nothing` در transaction/chunk همراه revision/rollback منطقی دارد.
- اگر تغییر Subject خارجی در تراکنش DB یکسان نیست، Outbox/Saga با state intermediate و compensation/repair؛ fail شدن external side effect نباید success نهایی جعلی بسازد.
- Ongoing Tasks و Workflow Instances تغییر هم‌زمان از Kanban/Calendar/CRM را با VersionGuard مدیریت می‌کنند؛ UI stale باید پیام Conflict و امکان reload داشته باشد.
- Eventهای `TaskChanged`, `InstanceTransitioned`, `StageVisitBreached`, `HandoffOpened/Resolved`, `GroupClosureStarted/Completed`, `CaseLinked` versioned هستند، private payload حداقلی.

## ۲۴. Automation، Notification، Webhook و Export

### ۲۴.۱ Automation

- Trigger→Condition→Action versioned؛ triggers: تغییر وضعیت، نزدیک‌سررسید، SLA breach، Campaign closed، Approver quorum، Handoff returned و موارد Task.
- Actions whitelist: assign, notify, add_label, create_subtask, request_approval, transition, handoff, mark_dead_candidate, export/report_event؛ همه از Service/Permission/VersionGuard عبور کنند.
- عمق cascade پیش‌فرض ۵، حداکثر Actions per event پیش‌فرض ۲۰، throttle و dedupe `(rule_id,origin_event_id,action_key)`؛ ممنوعیت Eval/PHP dynamic از DB.
- خطای Rule با Status و Audit مشخص ثبت شود؛ هیچ loop نامحدودی تولید نکند.

### ۲۴.۲ Notification و Reminder

- ارسال واقعی SMS/Email/Push متعلق به میزبان است؛ Task Manager PendingReminder/NotificationEvent/Outbox تولید می‌کند. quiet hours/channel preference از میزبان.
- تکمیل Task/Cancel Handoff/بسته‌شدن Stage باید pending reminderهای نامعتبر را Cancel کنند؛ rescheduling idempotent باشد.

### ۲۴.۳ Webhook

- Endpoint HTTPS، HMAC+timestamp+event_id، revalidation DNS/IP در Redirect، timeout و size limit، bounded retry/dead-letter و circuit breaker اختیاری.
- Subscription per Scope و Event؛ داده‌ها با Allowlist/redaction، secret reference نه secret در plain logs.

### ۲۴.۴ Export

- CSV/JSON streaming و async برای حجم بالا؛ خروجی Excel واقعی optional via host export adapter. فیلتر و مجوز در enqueue و زمان اجرا/دانلود revalidate؛ CSV injection protection و expiry.

## ۲۵. سازگاری Kanban، Calendar و CRM

### ۲۵.۱ اتصال Kanban (اختیاری)

- یک DataProvider/ActionHandler اختیاری برای Native Task یا External Instance با شناسه کارت پایدار تعریف شود؛ Kanban حق انتخاب sourceها *per-board* دارد، نصب Task Manager source خودکار در همه Boardها اضافه نمی‌کند.
- Stageهای برد با Workflow State/Instance Mapping صریح پر می‌شوند؛ در بردهای مخلوط Ticket/Opportunity/Task، Source/Action/Permission برای هر Card مستقل است.
- Drag کارت **فرمان انتقال معتبر** با expected_version/operation_id ارسال می‌کند؛ Kanban مستقیماً `status_id`, `stage_id`, `department_id` یا `workflow_instance` را mutate نمی‌کند.
- برای Handoff، Drawer بخش آماده `Activity`, `Stage Timeline`, `SLA`, `Assignees`, `Approvals`, `Subtasks`, `Dependencies`, `Case Links`, `Handoffs`, `Related CRM Records` دارد؛ دسترسی هر بخش مستقل و Lazy load.
- یک Subject/Instance می‌تواند در بردهای متعدد *نمایش* شود؛ `board_card_key` از `(subject_ref,instance_key,display_context)` ساخته شود تا UI دو نمای متفاوت را با state یکی فرض نکند.
- Rank مالکیت روشن: اگر Kanban Provider مرتب‌سازی Board را مدیریت می‌کند، Task Manager بدون توافق به Rank آن overwrite نمی‌کند.
- **تطبیق فنی لازم:** ZIP جدید Kanban دارای `HasKanbanCards`, `BoardBuilder::sources()`, `EloquentKanbanDataProvider`, `EloquentKanbanActionHandler` و Drawer Sectionهای اختیاری است (براساس گزارش بازبینی ارائه‌شده)؛ امضا و Version سازگار باید هنگام ساخت Adapter از سورس همان tag بررسی شود.

### ۲۵.۲ اتصال Calendar (اختیاری)

- منبع رویداد per-calendar opt-in؛ انواع `task_schedule`, `task_due`, `stage_deadline`, `approval_due`, `process_milestone`, `recurrence_occurrence` با نوع صریح و ID occurrence پایدار.
- SQL محدود به Date Range و Actor Scope؛ مرتب/صفحه‌بندی occurrence-safe؛ recurrence expansion نباید وقوع حذف کند.
- Drag/Resize فقط برای Eventهایی که Provider `canMove/canResize` اعلام و Action Handler مالک Domain اجازه داده باشد؛ تغییر `due` جایگزین transition CRM نمی‌شود.
- زمان‌های All-day/exclusive و Jalali calendar نمایش مستقل از UTC recurrence truth. Stage SLA می‌تواند فقط به‌صورت reminder/read-only روی Calendar ظاهر شود.
- **تطبیق فنی لازم:** ZIP Calendar از `HasCalendarEvents`, `CalendarModelRegistry`, `EloquentEventProvider`, `EloquentActionHandler`, `FullCalendar/MiniMonth/UpcomingAgenda` استفاده می‌کند (براساس گزارش بازبینی)؛ امضا و حل خطای page recurrence قبل از Adapter نهایی سنجیده شود.

### ۲۵.۳ CRM اولویت اول

- CRM می‌تواند تعاریف فعلی `pipeline`, `pipeline_stage`, `workflow` را به `tm_pipelines`, `tm_pipeline_stages`, `tm_workflows` مهاجرت دهد؛ **جداول CRM سرنخ/مشتری/فرصت/کمپین همچنان در CRM**.
- Lead و Opportunity هرکدام Subject مستقل با Workflow/Pipeline دلخواه، `subjectType` Alias معتبر و Handler CRM برای Guards و Side effects؛ Customer عموماً وضعیت «معامله» نیست و فقط در صورت نیاز دارای Workflow مستقل Onboarding است.
- هر بار تغییر مرحله در CRM از `TaskManager::instances()->transition(...)` و CRM Domain Validation عبور کند؛ حذف تدریجی writer موازی stage در CRM پس از Cutover و reconciliation.
- Taskهای پیگیری CRM می‌توانند به Lead/Opportunity/Campaign/Case لینک شوند و در Kanban Task Board یا در Drawer Opportunity نمایش داده شوند؛ تغییر Task به‌طور پیش‌فرض Opportunity را Won نمی‌کند.
- Ticket و Order حتی بدون Task Manager می‌توانند در Kanban/Calendar مستقیماً نمایش داده شوند؛ **داشتن قابلیت Workflow عمومی Task Manager الزام استفاده همه ماژول‌ها از آن نیست**.

### ۲۵.۴ ماتریس استقلال و ترکیب

| نصب | رفتار مورد انتظار |
|---|---|
| فقط Task Manager | Todo، Project، Workflow، Process API با Facade/Service؛ بدون UI |
| فقط Kanban | Task/Ticket/Order/CRM از منابع میزبان بدون Task Manager |
| فقط Calendar | سفارش، کلاس، CRM و زمان‌های سفارشی بدون Task Manager |
| Task Manager + Kanban | برد Task و Pipeline CRM در صورت Adapter، Transition امن و Drawer |
| Task Manager + Calendar | Due/Schedule/Instance SLA نمایشی بدون Kanban |
| Kanban + Calendar | دو نما مستقل از همان Source خارجی؛ بدون Task Manager |
| هر سه | Task/Instance یک source truth، refresh امن after-commit و بدون double transition |
| CRM + Task Manager | Pipeline/Stage/Workflow در Task Manager؛ Lead/Opportunity/Customer در CRM |
| CRM + Task Manager + Kanban/Calendar | نمایش و عملیات متصل با مجوز و یک حقیقت |

## ۲۶. مهاجرت CRM و داده‌های قدیمی

**مهاجرت اجباری CRM روی پروژه بدون مشتری عملیاتی فرصت بازطراحی است، ولی باز هم داده تست/توسعه را بدون Backfill و تست حذف نکنیم.**

1. Inventory: فهرست جدول‌ها، Stateها، transitions، قوانین pipeline، actor/department، ForeignKeys فعلی CRM و داده‌های مرتبط استخراج شود.
2. Mapping Matrix: هر `crm_pipeline/stage/workflow` قدیمی به `tm_pipeline/stage/workflow_revision` با کلیدهای versioned/alias؛ حالات unmatched با Preview/Manual Map.
3. Data migration: Workflow و Pipeline جدید per-scope ساخته، برای هر Lead/Opportunity فعال Instance ایجاد و state مطابق mapping تنظیم شود؛ شناسه قدیمی در link table/alias برای rollback محدود نگه داشته شود.
4. Shadow Read: CRM مدتی خروجی موتور جدید را با قدیم مقایسه کند؛ **double write ممنوع**، یک writer مجاز؛ dual-read فقط برای تفاوت‌سنجی.
5. Cutover: سرویس CRM برای move pipeline/stage به Task Manager Coordinator/Guard متصل شود. UI Kanban از Provider جدید استفاده کند.
6. Backfill Transition & Duration: تاریخچه موجود تبدیل شود؛ زمان‌های نامعلوم با `unknown` و منشأ `legacy` ثبت شوند؛ **ساخت مدت ساختگی ممنوع**.
7. Enable SLA/Handoff/Group Closure: پس از صحت mapping و اختبار مجوز، خطاهای Policy قبل از فعال‌سازی AutoFail در Mode مشاهده‌ای ثبت شوند.
8. Reconciliation: تعداد Subject/Instance، current state، terminal، assignee، activity، Index و Pipeline reports با داده مرجع مقایسه؛ mismatch block release.
9. Rollback: تا قبل از حذف مسیر قدیم، read/handler قابل برگشت باشد؛ داده جدید بعد Cutover با برنامه reconciliation/extract امن نگه داشته شود، rollback کورکورانه ممنوع.
10. از Taskهای legacy میزبان با `TaskImport` idempotent پشتیبانی شود؛ `.php` class alias بر اساس نسخه و Scope نه نام کلاس خام؛ Report import failures اجباری.

## ۲۷. عملیات جست‌وجو، فیلتر، مجوز و کارایی

- Query API برای Task: Scope/Owner/Project/List/Type/Parent/Epic/Assignee/Watcher/Reporter/Status Category/Workflow/Label/Sprint/Priority/Due/Overdue/Custom typed fields/Dependency/Blocked/Full-text normalized؛ برای Process: Pipeline/State/Stage/SLA state/Department/Team/Case/Handoff outcome/Group/Campaign source.
- فیلترها در SQL یا Provider Query-level اعمال می‌شوند و **قبل از pagination** مجوز احراز می‌گردد. هیچ `filter(Collection)` بعد از صفحه‌بندی برای Authorization مجاز نیست.
- Sort پایدار با tie-breaker Unique، Cursor مرتبط با Scope/Actor/Filter/Sort/Workflow revision و expiration/version؛ در تغییر visibility/query cursor معتبر قبلی نباید محتوای غیرمجاز دهد.
- پیش‌فرض pageSize=30، سقف API عادی=100 (قابل تنظیم با امن‌سازی)، Keyset برای dataset بزرگ و page سرراست برای UI ساده؛ Export جدا Async.
- query read-model نباید برای لیست ۱۰هزار/۱۰۰هزار Task، به ازای هر Task تمام comment/attachment/activity را hydrate کند؛ جزئیات Drawer lazy و تعدادها Aggregate مجوزدار.
- `Stage Aging` با clock و timezones مورد استفاده گزارش به‌صورت روشن نام‌گذاری شود؛ هر metric باید تعریف، واحد، بازه، منبع حقیقت و شیوه اعمال Scope داشته باشد.
- Cache permission/aggregate با policy version و invalidate after-commit؛ Projection عقب‌افتاده مقدار `stale=true/source_version` را برگرداند یا در گزارش حساس Recompute کند.

## ۲۸. الزامات غیرفانکشنال و کیفیت Production

| کد | الزام | معیار/روش سنجش |
|---|---|---|
| NFR-01 | PHP/Laravel | مبنای پیشنهادی PHP 8.3+ و Laravel 13؛ Composer constraint با CI واقعی تأیید شود |
| NFR-02 | DB | MySQL 8+/PostgreSQL + SQLite Unit؛ تست قفل واقعی روی MySQL/PostgreSQL |
| NFR-03 | نصب مستقل | `composer require` بدون UI/CRM و اجرای fresh migrations/Facade tests |
| NFR-04 | امنیت Query | Permission قبل از Count/Page/Facet/Export؛ Test IDOR و Department leakage |
| NFR-05 | دوام | After-commit Outbox بازیابی‌پذیر، Journal Idempotent، با Chaos tests |
| NFR-06 | کارایی Query | روی Dataset ۱۰k/۱۰۰k، پرفورمنس p95 و شمار SQL با محیط ثابت ثبت؛ **هدف، نه ادعای اثبات‌شده** |
| NFR-07 | کارایی SLA | Watchdog روی Due Index، پردازش chunk بدون scan همه Visits در هر نوبت |
| NFR-08 | کارایی Group | بسته‌شدن ۱۰۰ و ۱۰۰هزار مورد با bounded memory، resumable worker و شمار درست |
| NFR-09 | سازگاری زمانی | UTC/IANA + Jalali/Gregorian + DST + Business clock با تست Cross TZ |
| NFR-10 | دسترس‌پذیری/بین‌المللی | Headless؛ خطاها و reason code قابل محلی‌سازی، فارسی/انگلیسی مستند |
| NFR-11 | Observability | Metrics: outbox lag, dead letters, active handoffs, SLA due lag, group item retry, error rate |
| NFR-12 | پایایی API | Semantic Versioning، Migration Guide، DTO contract tests و Upgrade tests |
| NFR-13 | تست مستقل | Unit/Feature/Integration/Security/Concurrency/Benchmark/Browser-host suites |
| NFR-14 | امنیت Webhook | امضا، replay prevention، SSRF/DNS-rebinding control، محدودیت payload |
| NFR-15 | حذف داده | retention/Audit redaction، Archive و soft-delete طبق Policy و قانون میزبان |

**Benchmark پیشنهادی نه تعهد فعلی:** `p95 query 30 Tasks < 250ms`, `p95 transition same-DB < 350ms`، `100k group processed within operational batch target` فقط پس از مشخص‌شدن سخت‌افزار و الگوی بار معتبرند. نباید به‌عنوان SLA محصول انتشار داده شوند تا Benchmark ثبت نشده باشد.

## ۲۹. سناریوهای End-to-End الزامی قبل از انتشار

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

## ۳۰. ماتریس ردیابی نیازمندی‌ها (Traceability)

هر Requirement یک کد `FR` پایدار دارد؛ تغییر معنا بدون تغییر نسخه PRD ممنوع. کدهای TM در فصل تست تعریف شده‌اند. ردیف‌های مربوط به E2E به تست ترکیبی نیز متصل‌اند.

| Requirement | شرح | Acceptance scenarios | مالک اجرایی |
|---|---|---|---|
| FR-01 | Task/Todo/Inbox/Lists/Share | TM-001..012,091..095, E2E-01 | Task |
| FR-02 | Project/Type/Workflow Revision | TM-013..026,096..100 | Task/Workflow |
| FR-03 | Hierarchy/Rank/Reparent | TM-027..032,101..104 | Task |
| FR-04 | Dependency/Guard | TM-033..040,105..107 | Task |
| FR-05 | Checklist/Comment/File/Activity | TM-041..048,108,148..151 | Task/Audit |
| FR-06 | Time Tracking/Rollup | TM-049..050,109..111 | Planning |
| FR-07 | Scheduling/Date/Timezone | TM-051..053,112..114 | Planning |
| FR-08 | Templates/Custom Fields | TM-054..056,115..117 | Task |
| FR-09 | Recurrence میلادی و شمسی | TM-057..064,118..121 | Planning |
| FR-10 | Sprint/Velocity/Historical Reporting | TM-065..072,122..125 | Planning |
| FR-11 | Scope/Permission/Outbox/Journal | TM-073..080,126..129,205..208 | Security |
| FR-12 | Kanban/Calendar/CRM Integration | TM-081..088,130..132,209..213 | Integrations |
| FR-13 | Query/Performance/Normalized Search | TM-089..090,133..134,214..215 | Queries |
| FR-14 | Automation | TM-135..137,216 | Enterprise |
| FR-15 | Webhook | TM-138..140,217 | Enterprise |
| FR-16 | Export | TM-141..142,218 | Enterprise |
| FR-17 | Legacy CRM/Task migration | TM-143..145,219..220 | Integrations |
| FR-18 | Member departure/Watchers/Assignments | TM-014,022,146..147,188..195 | Task/Process |
| FR-19 | External Subject + Process Instance | TM-152..159, E2E-04 | Process |
| FR-20 | Pipeline/Stage mapping و Board separation | TM-160..165, E2E-05 | Process |
| FR-21 | Handoff/Return/Multi-instance/Case | TM-166..187, E2E-03..06 | Process |
| FR-22 | Fixed/Dynamic/Multi Assignment + Approval | TM-188..195, E2E-03 | Process |
| FR-23 | Stage SLA/Stale/Dead Candidate | TM-196..204, E2E-02 | SLA |
| FR-24 | Group Closure/Campaign/Reason | TM-221..236, E2E-02 | Group |
| FR-25 | Process Audit/Timeline/Visits/Reason | TM-148..151,160,184,199,229 | Audit |


## ۳۱. ماتریس سناریوهای پذیرش — TM-001 تا TM-236

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

## ۳۲. نقشه راه توسعه در پنج فاز داخلی و Gateها

**پنج فاز، تعهد تکمیل داخلی هستند نه پنج محصول عمومی ناقص.** هر Gate فقط سناریوهای قابلیتی را می‌خواهد که در همان فاز یا فاز قبلی واقعاً ساخته شده‌اند. Gateهای آینده در CI به‌عنوان Pending تعریف شوند؛ Pass جعلی یا Mark-Skip بی‌دلیل ممنوع.

### فاز ۱ — Foundation و Todo قابل استفاده مستقل

**ساخت:** Composer، Config، ServiceProvider، Facade Stateless، Actor/Scope/Authorization، DTO و Error Contract، `tm_tasks`, `tm_lists`, Inbox/Share، Project پایه، Workflow Personal پایه + Instance، PublicKey/Alias، Journal، Outbox، Query پایه و Data Migration قابل نصب.

**Gate:** TM-001..012، TM-073، TM-076، TM-078..080، TM-091..095، TM-098..099، TM-126، TM-128..129، TM-153 و TM-212؛ fresh install بدون Kanban/Calendar/CRM، Zero Scope leakage و اجرای Todo بدون Queue خارجی. TM-127 مربوط به تغییر عضویت/Cache پس از تکمیل مجوزها در فاز ۲ است.

### فاز ۲ — Workflow/CRM Process Core

**ساخت:** Workflow Revisions، type mapping، Pipeline/Stage، External Subject Registry/Handler، Instance/Active Slot، Transition Guards، StageVisit، Case، Process basic audit، Owner/Assignee، Member/Role، Query/Report پایه و `moveInstanceToPipeline`.

**Gate:** TM-014..026، TM-096..100، TM-127، TM-148..165، TM-188..189، TM-205 و TM-207..208؛ CRM sample Lead/Opportunity با نمونه Adapter دامنه از Pipeline جدید حرکت کند. **TM-013 (Project Template)، TM-206 (Group Item)، TM-209 (Kanban Adapter) و TM-213 (Cutover واقعی) در فازهای بعد تست می‌شوند.**

### فاز ۳ — Handoff + SLA + Campaign (اولویت کسب‌وکار CRM)

**ساخت:** `delegate/move/spawn/parallel`, ReturnPolicy, Join, Approval step, Case coordination, Team/Department resolvers, SLA Clock/Visit/Watchdog, Stale rules, Group/Policy/Run/Items, Bulk worker, Reason Catalog, External same-DB coordinator و Saga path.

**Gate:** TM-166..204، TM-206، TM-221..236، E2E-02..06 (منهای تست تصویری Kanban/Calendar که در فاز ۴ تکمیل می‌شود)؛ تست concurrent workers و crash/resume، Ticket→Opportunity و برگشت قرارداد با approved/rejected/cancelled روی MySQL/PostgreSQL.

### فاز ۴ — مدیریت پروژه کامل و Enterprise

**ساخت:** Hierarchy Closure Table، Dependency، Sprint/Backlog/Release/Milestone، Scheduling/Gregorian/Jalali recurrence/Reminder، Time Tracking، Templates/Custom Fields، Labels/Comments/Files، Notifications/Automation/Webhook/Export، گزارش تاریخی/Projection، Kanban/Calendar Adapters کامل و CRM data migration.

**Gate:** TM-013، TM-027..072، TM-074..075، TM-077، TM-081..090، TM-101..125، TM-130..147، TM-209..215، TM-216..220 و E2E-01؛ اجرای مستقل/ترکیبی، Migration/Cutover و پروژه Agile چند Workflow واقعی. تست‌های مشترک شماره تکراری ندارند، فقط در Gate نهایی دوباره اجرا می‌شوند.

### فاز ۵ — Production Hardening و Release

**ساخت:** تست ماتریس DB/Version، load/performance benchmark، migration/reconciliation/rollback، security audit، CI، Upgrade Guide، مستندات API، Facade، مثال‌های CRM/Ticket/Task، تست UX Integration در میزبان، chaos/queue/outbox recovery، release candidate.

**Gate:** **تمام TM-001..236 و E2E-01..06** با گزارش مستقل تست و زمان اجرا؛ خطای امنیتی Critical/High باز وجود نداشته باشد؛ پوشش سناریوی بازیابی پیام/Group؛ CI روی ماتریس مصوب سبز، بدون نیاز به Livewire در نصب Headless.

> **دقت:** بعضی TMها بخش‌های چندمرحله‌ای دارند و در فازهای مختلف تکمیل می‌شوند؛ Gate فاز میانی تنها وقتی Pass است که تمام پیش‌شرط‌های آن مورد واقعاً پیاده شده باشد. ماتریس Traceability بر شماره متوالیِ تست اولویت دارد.

## ۳۳. نسخه‌بندی انتشار و مدیریت محدوده

- **پیش‌فرض اجرایی سند:** تمام قابلیت‌های الزام‌شده این PRD به همراه Gate نهایی، قبل از انتشار عمومی نسخه‌ای که «Enterprise Complete» نام می‌گیرد باید پیاده و تست شوند؛ صرف تکمیل فاز ۱ تا ۳ «محصول کامل» نیست.
- پیشنهاد بازبینی تیم برای انتشار مرحله‌ای معتبر است، ولی **هنوز به‌عنوان تصمیم محصول تصویب نشده**؛ نباید بدون تأیید مالک محصول Automation، Webhook، Sprint، Jalali Recurrence یا Critical Path را از دامنه الزام خارج کنیم.
- تیم می‌تواند Alpha/Beta داخلی داشته باشد؛ برچسب آن‌ها «غیر Production» باشد و بدون ادعای Complete منتشر شوند.
- اگر پس از تخمین واقعی تصمیم به انتشار عمومی 1.0/1.1/1.2 گرفته شود، هر قابلیت انتقالی باید با `FR`, `TM`, Migration/Feature Flag و Change Approval ثبت گردد. هیچ قابلیت به‌صورت سکوت از برنامه حذف نشود.
- تاریخ تحویل و نفر-هفته تا مشخص‌شدن تیم، repo baseline و نمونه CRM واقعی **تخمین معتبر ندارد**؛ جلسه Scope/Architecture Spike و ظرفیت‌سنجی برای تخمین لازم است.

## ۳۴. تنظیمات پیش‌فرض و سیاست‌های قابل Override

| Key مفهومی | پیش‌فرض پیشنهادی | دامنه Override |
|---|---|---|
| `scope.default_key` | فقط در حالت single-scope صریح | App deployment |
| `task.default_profile` | `personal` | Actor/Project/Scope |
| `task.default_priority` | `normal` | Project/Type |
| `task.max_depth` | 3، سقف قابل تنظیم 8 | Scope/Project |
| `task.parent_completion` | manual | Project/Type |
| `task.delete_parent` | `deny_if_children` | Project/Type |
| `workflow.default_blueprint` | personal/basic per scope | Scope/Project/Type |
| `pipeline.max_active_slots` | per Subject/slot یک Instance فعال | Subject Type |
| `handoff.max_depth` | 5 | Scope/Pipeline |
| `handoff.default_origin_behavior` | `pause_origin` برای delegate | Pipeline/Transition |
| `handoff.default_return` | **اجباری/بدون حدس** | Handoff Definition |
| `assignment.mode` | `fixed` یا stage-specific | Pipeline/Stage |
| `approval.default_quorum` | all برای یک Step تعریف‌شده | Approval Step |
| `sla.default_breach_action` | mark + notify | Pipeline/Stage |
| `sla.reentry_policy` | `reset_on_reentry` | Stage Policy |
| `sla.clock` | elapsed؛ business فقط با Provider صریح | Scope/Stage |
| `group.default_execution` | `partial_success` با Item report | Group Policy |
| `group.default_terminal_policy` | unchanged | Group Policy |
| `bulk.max_chunk` | 250 (پیشنهاد اولیه، بنچمارک شود) | Deployment |
| `query.page_size/max` | 30 / 100 | Deployment |
| `calendar.timezone` | Asia/Tehran فقط با انتخاب میزبان؛ هسته UTC | User/Scope |
| `calendar.week_start` | شنبه در Preset ایران؛ قابل تنظیم | Scope/User |
| `recurrence.invalid_day` | skip | Rule |
| `outbox` | Persist always؛ Dispatch sync/queue configurable | Deployment |
| `audit.retention_policy` | نیازمند تعیین قانونی میزبان؛ حذف خودسرانه ممنوع | Deployment/Scope |

این‌ها **پیش‌فرض پیشنهادی طراحی** هستند، نه Config فعلی پکیج. برای هر Override اعتبارسنجی سطح/Scope و ثبت snapshot/Revision لازم است.

## ۳۵. ساختار پوشه‌ای و قراردادهای نگه‌داری

```text
src/
  Facades/TaskManager.php
  TaskManagerServiceProvider.php
  Contracts/{Actor,Scope,Authorization,Subject,Team,WorkingCalendar,...}
  Context/{ExecutionContext,ActorReference,ScopeReference}
  DTO/{Commands,Queries,Results}
  Domain/
    Tasks/{TaskService,HierarchyService,DependencyService}
    Projects/{ProjectService,SprintService}
    Workflow/{WorkflowService,WorkflowInstanceService,PipelineService}
    Process/{CaseService,HandoffService,ReturnPolicyService,ApprovalService}
    Responsibility/{AssignmentService,AssigneeResolutionService}
    SLA/{StageSlaService,SlaScheduler,StaleDetector}
    Groups/{GroupService,GroupClosureService,GroupRunWorker}
    Planning/{RecurrenceService,TimeTrackingService}
    Activity/{AuditService,TimelineQuery}
  Application/{Commands,Handlers,Policies,Queries}
  Infrastructure/{Eloquent,Outbox,Journal,Locks,Search,Clock,Registry}
  Integrations/{Kanban,Calendar,CRM}
  Exceptions/
config/task-manager.php
database/migrations/
tests/{Unit,Feature,Integration,Concurrency,Security,Scenarios}
docs/{PRD,ARCHITECTURE,USAGE,API,INTEGRATIONS,SCENARIOS,UPGRADE,DECISIONS}
```

این **نقشه منطقی** است؛ نام پوشه‌ها ممکن است در Design Review اصلاح شود ولی مرز Domain/Application/Integration نباید از بین برود. **کلاس‌های Integrations نباید با نصب مستقل و غیاب Kanban/Calendar سبب Class Load Error شوند**؛ dependency اختیاری/feature detection یا Adapter جدا ارائه شود.

## ۳۶. مستندات تحویل تیم توسعه

- `README.md`: نصب بدون UI، Quick Start یک‌خطی Todo، نمونه Project، مثال CRM Workflow، لینک به اسناد.
- `ARCHITECTURE.md`: مرز مالکیت، Scope/Actor/Subject/Instance/Case، Diagram و Write Path، Error codes.
- `DATABASE.md`: Schema کامل/Unique/Indexes/Migrations/Constraints و State Truth Rule.
- `FACADE.md` + `USAGE.md`: DTOها، typed service signatures، examples برای Actor/Scope/Queue/Transaction.
- `WORKFLOW-PIPELINE.md`: تعریف Blueprint، Version Publish، Stage Mapping، WIP و Reason Catalog.
- `HANDOFF.md`: move/delegate/spawn/parallel، ReturnPolicy و Source Converter.
- `SLA-GROUPS.md`: calendar clocks، stale، warning، batch closure، rollback و operation reports.
- `CRM-INTEGRATION.md`: Lead/Opportunity/Ticket/Contract/Order مثال‌های واقعی؛ Migration و No Double Write.
- `KANBAN-INTEGRATION.md`, `CALENDAR-INTEGRATION.md`: dependency optional و Compatibility Matrix همان release.
- `SECURITY.md`, `PERFORMANCE.md`, `OPERATIONS.md`: capability، IDOR، Watchdog/Queue و recovery.
- `TEST-SCENARIOS/`: **۲۳۶ فایل Markdown فارسی** با مراحل و انتظار دقیق مطابق TM-IDs، همراه Test PHP قابل اجرا؛ سناریوها صرفاً عنوان نباشند.
- `CHANGELOG.md`, `UPGRADE.md`, ADR و Decision Log: هر تغییر شکستن API یا Migration ثبت شود.

## ۳۷. ریسک‌ها و راهبرد کنترل

| ریسک | احتمال/اثر | راهکار |
|---|---|---|
| افزایش محدوده و تأخیر انتشار | بالا/بالا | Gateهای مستقل، MVP داخلی معتبر، اندازه‌گیری نفر-هفته پس از Spike؛ هیچ ادعای آماده‌بودن پیش از Gate |
| دو حقیقت CRM stage و Workflow Instance | بالا/بحرانی | Single Writer + Shadow Read + Reconciliation + Cutover |
| ارجاع بین DBها با Commit ناقص | متوسط/بحرانی | Saga/Outbox/Idempotency، state pending و repair dashboard |
| Cross-Department data leak | متوسط/بحرانی | Subject + Case visibility intersection، tests با پیام محرمانه |
| Race در Campaign close و انتقال کار | بالا/بالا | cutoff revision + per-item lock + historical milestone + Preview delta |
| SLA در DST/Working Calendar | متوسط/بالا | سنجش مرجع UTC/IANA، snapshot و تست چند timezone |
| قراری که در دو Board متضاد شده | متوسط/بالا | Instance Slot truth؛ عدم direct stage mutation |
| چند Handoff دایره‌ای | متوسط/بالا | lineage/max depth، Join و ReturnPolicy نسخه‌دار |
| Automation loops/unsafe actions | متوسط/بالا | whitelist، سقف depth/action، ServiceActor محدود |
| ۱۰۰هزار عضو گروه و رویدادهای فراوان | متوسط/بالا | Keyset/chunk/lease، Index، report async و backpressure |
| Facade بیش از حد پیچیده | بالا/متوسط | چند متد ساده Todo، سرویس تخصصی پشت Facade، DTOهای روشن |
| تغییر Workflow در حال اجرا | متوسط/بالا | immutable revisions، migration preview و mapping امن |
| SQL/DB اختلاف سازگاری | متوسط/بالا | CI MySQL/PostgreSQL + تست concurrency واقعی |

## ۳۸. ماتریس تصمیم تیم و وضعیت تصویب

| کد تصمیم | تصمیم پیشنهادی قطعی در این پیش‌نویس | صاحب تصمیم | وضعیت |
|---|---|---|---|
| ADR-01 | یک Workflow Instance منبع truth وضعیت Task/External Subject | معمار + CRM lead | پیشنهاد جهت تصویب |
| ADR-02 | Board ≠ Pipeline ≠ Workflow؛ سه نوع move/view/delegate جدا | معمار + Frontend | پیشنهاد جهت تصویب |
| ADR-03 | Subject خارجی بدون تبدیل به Task، Morph Alias و Scope | CRM lead + Security | پیشنهاد جهت تصویب |
| ADR-04 | Personal Todo با Workflow/Instance خودکار؛ Project nullable | Product + Backend | پیشنهاد جهت تصویب |
| ADR-05 | Closure Table برای Hierarchy، depth ۳ پیش‌فرض/۸ سقف | Backend + DB | پیشنهاد جهت تصویب |
| ADR-06 | هویت `public_key` سراسری و display alias با تاریخچه | Backend | پیشنهاد جهت تصویب |
| ADR-07 | Handoff mode و ReturnPolicy snapshot اجباری | CRM + Process owner | پیشنهاد جهت تصویب |
| ADR-08 | Owner جدا از Assignee/Approver؛ Dynamic Assignment Policy | CRM + Security | پیشنهاد جهت تصویب |
| ADR-09 | SLA Visit-based با Mark+Notify پیش‌فرض، Fail فقط با Policy | CRM + Operations | پیشنهاد جهت تصویب |
| ADR-10 | Campaign closure بر اساس Milestone as-of Cutoff، not stage index | CRM + Product | پیشنهاد جهت تصویب |
| ADR-11 | Outbox اجباری persisted + Journal Idempotent | Backend + Operations | پیشنهاد جهت تصویب |
| ADR-12 | DB مختلف=Saga؛ یک DB=atomic فقط با تست | Backend + Integration | پیشنهاد جهت تصویب |
| ADR-13 | Jalali recurrence واقعی و normalized Persian search | Product + Planning | پیشنهاد جهت تصویب |
| ADR-14 | Capability query-level/read-level برای همه نما/آمار | Security | پیشنهاد جهت تصویب |
| ADR-15 | اولویت CRM، اما انتشار کامل با Gate؛ version splitting نیازمند Change Request | Product owner | نیازمند تصمیم تجاری |
| ADR-16 | Pipeline Ticket و CRM migration با alias/Shadow Read/no double write | CRM + Backend | پیشنهاد جهت تصویب |

**شیوه تصویب:** هر ADR باید `approved/rejected/amended`، نام مسئول، تاریخ، دلیل، اثر بر Schema/Facade/FR/TM و Release Target داشته باشد. این سند بدون امضای ADRهای هسته‌ای مبنای «شروع توسعه بدون سوال» نیست؛ ولی برای جلسه تیم، پیشنهادها و تبعات هر تصمیم مشخص‌اند.

## ۳۹. چک‌لیست آمادگی Production و عدم پذیرش ناقص

- [ ] تمام ۲۳۶ تست TM و شش E2E با خروجی Run واقعی سبز و CI قابل تکرار؛ Skipهای مجاز مستند.
- [ ] هیچ Security High/Critical باز در Review مستقل وجود ندارد؛ Tenant/Case/Department/Export/Count leak تست شده.
- [ ] دسترسی Root Facade بدون Context fail-closed؛ ServiceActorهای Queue محدوده دقیق دارند.
- [ ] Transition/Visit/Assignment/Group Closure به‌صورت اتمیک در DB مربوطه و Outbox persist می‌شوند.
- [ ] Crash Recovery برای Group worker، Watchdog و Outbox اثبات شده؛ idempotency hash conflict درست است.
- [ ] Ticket→Opportunity, فروش→قرارداد→فروش و پایان کمپین ۱۰۰تایی با Reason/SLA آزمایش شده.
- [ ] Schema/Indexes/Unique واقعی روی MySQL/PostgreSQL اعمال و race تست شده‌اند.
- [ ] Kanban و Calendar standalone باقی‌اند؛ با هم/Task Manager ترکیبی کار می‌کنند.
- [ ] CRM مرحله دوگانه writable ندارد و Migration/Reconciliation/rollback plan تأیید شده.
- [ ] Package با Composer نصب و بدون Livewire/Filament/CRM run می‌شود؛ مثال Todo تنها با Title عملی است.
- [ ] benchmark با مشخصات محیط، seed و SQL Count/p95 اندازه‌گیری و منتشر شده؛ Target به جای نتیجه جا زده نشده.
- [ ] مستندات و سناریوهای فارسی، API/Facade و Upgrade Guide با کد واقعی sync هستند.
- [ ] نسخه انتشار و Capabilityهای الزام/تعویق با Change Approval روشن‌اند.

## ۴۰. منابع، سابقه تغییر و وضعیت نهایی سند

### اسناد مبنای بازنگری

- `KARNOWEB-TASK-MANAGER-ENTERPRISE-PRD-v1.0-FA.md`: قابلیت‌های Todo→Project، ۹۰ سناریوی پایه و Facade.
- `TASK-MANAGER-PRD-REVIEW-RESOLUTION-v1.1-FA.md`: رفع تناقض‌های Schema/Workflow/Scope/Jalali/Security و سناریوهای TM-091..147.
- سناریوهای تکمیلی این گفتگو: CRM اولویت اول؛ مالکیت Pipeline/Workflow توسط Task Manager؛ Handoff فروش/قرارداد با بازگشت؛ Ticket→Opportunity؛ SLA مرحله‌ای و «تسک مرده»؛ پایان کمپین با اجازه ادامه پس از رزرو.
- گزارش بازبینی بسته‌های Kanban و Calendar تاریخ ۲۰۲۶/۱۰/۱۹؛ برای تطبیق نهایی Contractها همچنان باید آخرین نسخه نصب‌شده آن‌ها در زمان توسعه Adapter بررسی شود.

### تغییرات اصلی نسبت به نسخه ۱.۰

1. Workflow فراتر از Task و قابل استفاده روی Subjectهای CRM/Ticket/Order شد.
2. Single source of truth وضعیت به Workflow Instance منتقل شد؛ Task.status در مدل جدید حقیقت دوم نیست.
3. تفکیک Board/Pipeline/Workflow/Stage/Instance/Case رسمی شد.
4. Handoff با modes و ReturnPolicy، Join و عملیات Saga تعریف شد.
5. Stage SLA و stale/dead_candidate با Visits و Policy وارد Domain شد.
6. Campaign Group Closure با Milestone تاریخی، cutoff، chunk/Retry/Preview و reasons به Domain اضافه شد.
7. Owner/Assignee/Approver/Watcher، چند مسئول و Assignment history تفکیک شدند.
8. Data Schema و Security/Facade/Outbox با بازبینی ۱.۱ یکپارچه شدند.
9. ماتریس سناریو از ۹۰ به ۱۴۷ و سپس به **۲۳۶** سناریوی قابل آزمون رسید.
10. مهاجرت CRM به‌عنوان مصرف‌کننده اول با سیاست No Double Write برنامه‌ریزی شد؛ Kanban/Calendar مستقل ماندند.

**وضعیت:** نسخه ۲.۰ یک **PRD ادغام‌شده و آماده بازبینی تیم** است؛ Featureهای داخل آن «planned» هستند و هیچ ادعای پیاده‌سازی نشده است. پس از امضای ADRها و بررسی سورس واقعی Facade الگو، این نسخه می‌تواند به Baseline اجرایی تبدیل شود.
