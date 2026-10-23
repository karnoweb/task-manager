# مدیریت Task و Project

[فهرست](../README.md) · قبلی: [Todo](../01-getting-started/quick-start.md) · بعدی: [Workflow](../04-advanced/workflow.md)

پیش‌نیاز این صفحه نصب Laravel 13، جدول‌های پکیج و یک مدل کاربر معتبر است. مثال‌های مستقل زیر را مانند صفحه شروع در مسیر خصوصی میزبان ذخیره کنید. هر فایل Namespace (فضای نام) و use (کلاس‌های مصرفی) خودش را دارد و یک تابع بی‌نام با ورودی user و operation برمی‌گرداند. برای هر اجرای تازه UUID (شناسه یکتای استاندارد) تازه تولید کنید؛ برای تلاش مجدد همان فرمان، شناسه و داده ثابت بمانند.

## ویرایش، بازگشایی، آرشیو و فهرست Todo

`TaskSession` فقط create، get و complete دارد. برای عملیات دیگر، DTO (شیء انتقال داده) موجود MutateTask و TaskQuery را به TaskService می‌دهیم. مثال در `storage/app/private/task-manager-docs/tasks.php`:

<!-- example: manage-tasks -->
```php
<?php

namespace App\TaskManagerDocs;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\DTO\MutateTask;
use Karnoweb\TaskManager\DTO\TaskQuery;
use Karnoweb\TaskManager\Facades\TaskManager;

return static function (Authenticatable $user, string $operation): array {
    if (! Str::isUuid($operation)) {
        throw new \InvalidArgumentException('operation must be a UUID');
    }
    $actor = (string) $user->getAuthIdentifier();
    $context = new ExecutionContext(
        'personal:'.$actor, $actor, (string) Str::uuid(),
        capabilities: ['task.create', 'task.view', 'task.edit'],
    );
    $tasks = TaskManager::tasks();
    $session = $tasks->inContext($context);
    $task = $session->create('بررسی پیشنهاد', $operation.':create');
    $updated = $tasks->change(new MutateTask(
        $context, $task->publicKey, $task->version, $operation.':edit',
        'update', ['priority' => 'high', 'description' => 'تماس با مشتری'],
    ));
    $done = $session->complete($updated->publicKey, $updated->version, $operation.':complete');
    $reopened = $tasks->change(new MutateTask(
        $context, $done->publicKey, $done->version, $operation.':reopen', 'reopen',
    ));
    $archived = $tasks->change(new MutateTask(
        $context, $reopened->publicKey, $reopened->version, $operation.':archive', 'archive',
    ));
    $restored = $tasks->change(new MutateTask(
        $context, $archived->publicKey, $archived->version, $operation.':restore', 'restore',
    ));
    $rows = $tasks->findTasks(new TaskQuery($context, page: 1, limit: 30));

    return compact('updated', 'done', 'reopened', 'archived', 'restored', 'rows');
};
```

اجرا در Tinker (محیط تعاملی Laravel)، پس از ورود معتبر: `$run = require storage_path('app/private/task-manager-docs/tasks.php');`، `$operation = (string) \Illuminate\Support\Str::uuid();` و `$result = $run(auth()->user(), $operation);`.

خروجی updated تا restored از نوع `Karnoweb\TaskManager\DTO\TaskResult` و rows فهرست همان نوع است. در اجرای تازه نسخه‌ها به ترتیب ۲، ۳، ۴، ۵ و ۶ هستند؛ حالت نهایی pending است. rows ممکن است کارهای مجاز قبلی کاربر را هم داشته باشد؛ در پایگاه خالی یک کار دارد. TaskQuery فقط context، page و limit دارد؛ فیلتر جست‌وجو در این DTO وجود ندارد. حد واقعی صفحه ۱ تا ۱۰۰ است؛ تنظیم page_size/max_page_size فعلاً این امضاها را تغییر نمی‌دهد.

update فقط title، description و priority می‌پذیرد. مقادیر واقعی priority عبارت‌اند از low، normal، high و urgent. تغییر عنوان و اولویت در `tm_tasks` نسخه Task را افزایش می‌دهد؛ complete/reopen علاوه بر Task، وضعیت و نسخه نمونه اصلی را نیز تغییر می‌دهند. archive/restore زمان archived_at را تغییر می‌دهند و حذف فیزیکی نیستند. عملیات با رویداد و دفتر ثبت فرمان همراه‌اند؛ آرشیو، کار را از query عادی خارج می‌کند و restore آن را برمی‌گرداند.

## اجازه تنظیم پروژه در نمونه آموزشی

برای Todo شخصی، مجوزها به قلمرو همان کاربر محدود بودند. ساخت تعریف و پروژه مجوز بیشتری دارد؛ قبل از اعطای آن‌ها، میزبان باید کاربر مجاز را تشخیص دهد. برای اجرای کنترل‌شده این راهنما، فایل `storage/app/private/task-manager-docs/permissions.php` را بسازید:

<!-- example: configure-permission -->
```php
<?php

namespace App\TaskManagerDocs;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

Gate::define('task-manager.docs.configure', static function (Authenticatable $user): bool {
    return (string) $user->getAuthIdentifier() === '1';
});
```

این Gate (قاعده مجوز Laravel) عمداً فقط کاربر ۱ را برای مثال‌های تنظیم پروژه و Workflow مجاز می‌کند. نام آن متعلق به برنامه نمونه است، نه مجوز داخلی آماده پکیج. پیش از اجرای آن مثال‌ها، یک بار در همان نشست Tinker، `require storage_path('app/private/task-manager-docs/permissions.php');` را اجرا کنید. در محصول، این قاعده را با سیاست واقعی نقش/عضویت خودتان جایگزین و در Provider (ثبت‌کننده سرویس) میزبان تعریف کنید؛ بررسی مجوز نباید از ورودی مرورگر استخراج شود.

## ساخت پروژه و قرار دادن Task در آن

در سورس فعلی، متد ساخت مستقیم Task در Project وجود ندارد. مسیر موجود: انتشار تعریف ← ساخت پروژه ← ساخت Todo ← پیش‌نمایش انتقال ← انتقال. Pipeline و برد برای این زنجیره لازم نیستند. فایل `storage/app/private/task-manager-docs/project.php`:

<!-- example: create-project-task -->
```php
<?php

namespace App\TaskManagerDocs;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Facades\TaskManager;

return static function (Authenticatable $user, string $operation, ?array $savedPreview = null): array {
    Gate::forUser($user)->authorize('task-manager.docs.configure');
    if (! Str::isUuid($operation)) {
        throw new \InvalidArgumentException('operation must be a UUID');
    }
    $actor = (string) $user->getAuthIdentifier();
    $context = new ExecutionContext(
        'personal:'.$actor, $actor, (string) Str::uuid(),
        capabilities: [
            'task.create', 'task.view', 'task.edit',
            'workflow.manage', 'workflow.view',
            'project.manage', 'project.view', 'project.edit',
        ],
    );
    $suffix = str_replace('-', '', strtolower($operation));
    $definition = [
        'initial' => 'open',
        'states' => ['open' => ['terminal' => 'none'], 'done' => ['terminal' => 'success']],
        'transitions' => ['finish' => ['from' => 'open', 'to' => 'done']],
    ];
    $revision = TaskManager::workflows()->publish(
        $context, 'docs.project.'.$suffix, 0, $operation.':publish', $definition,
    );
    $project = TaskManager::projects()->create(
        $context, 'D'.strtoupper(substr($suffix, 0, 12)), 'پروژه مستندات',
        $revision['id'], $operation.':project',
    );
    $personal = TaskManager::tasks()->create($context, 'بازبینی قرارداد', $operation.':task');
    $preview = $savedPreview ?? TaskManager::projects()->previewTaskMove(
        $context, $personal['public_key'], $project['project_key'],
        'task', ['pending' => 'open'],
    );
    $task = TaskManager::projects()->moveTask($context, $preview, $operation.':move');

    return compact('revision', 'project', 'personal', 'preview', 'task');
};
```

بعد از ثبت Gate و ورود کاربر ۱، `$run = require storage_path('app/private/task-manager-docs/project.php');`، `$operation = (string) \Illuminate\Support\Str::uuid();` و `$result = $run(auth()->user(), $operation);` را اجرا کنید. خروجی همه کلیدها آرایه است؛ برخلاف TaskResult، نام شناسه `public_key` است.

برای بازپخش همین زنجیره پس از موفقیت، ورودی پیش‌نمایش اصلی را هم حفظ کنید: `$again = $run(auth()->user(), $operation, $result['preview']);`. savedPreview فقط ورودی تابع آموزشی میزبان است، نه پارامتر تازه پکیج؛ آن را از ذخیره معتبر سرور بگیرید. اجرای دوباره کل زنجیره بدون پیش‌نمایش ذخیره‌شده، پیش‌نمایش تازه با نسخه‌های متفاوت می‌سازد و بازپخش همان move محسوب نمی‌شود. در میزبان دارای بازیابی پس از قطع پردازش، preview باید پیش از move در وضعیت پایدار همان فرمان ذخیره شود؛ این تابع آموزشی چنین ذخیره‌سازی ماندگاری پیاده نمی‌کند. عملیات بازپخش‌شده همچنان مجوز جاری را بررسی می‌کند.

revision.id شناسه عددی نسخه تعریف و revision.revision شماره انتشار آن است؛ انتشار اول expectedRevision برابر صفر می‌خواهد و شماره خروجی ۱ می‌شود. project.project_key شناسه عمومی پروژه و project.key کد نمایشی آن است. کار اولیه pending و شخصی است؛ پس از انتقال، public_key ثابت، project_id پر و state برابر open می‌شود. display_key به کد پروژه وابسته می‌شود و کلید قبلی در جدول alias (نام جایگزین) باقی می‌ماند.

در `tm_projects` یک پروژه و در `tm_project_members` عضویت manager سازنده ثبت می‌شود. انتقال، Task و همان نمونه اصلی در `tm_workflow_instances` را به نسخه تعریف پروژه می‌برد؛ نمونه main دوم نمی‌سازد. نگاشت pending → open بخشی از تصمیم دامنه است. preview نسخه Task و Project را حمل می‌کند و اجرای انتقال تازگی آن را می‌سنجد. اگر چند فرمان باید همگی با هم برگردند، تراکنش (واحد اجرای یکپارچه پایگاه داده) میزبان باید آگاهانه طراحی شود؛ این مثال کل زنجیره را یک تراکنش واحد نمی‌کند.

مجوزهای صریح مثال همان فهرست داخل کد هستند. previewTaskMove پروژه را با edit می‌خواند ولی moveTask، علاوه بر project.edit، manage پروژه را نیز بررسی می‌کند؛ عضویت member به‌تنهایی جای manager را نمی‌گیرد. مجوز و عضویت در زمان اجرا و بازپخش دوباره بررسی می‌شوند.

## تکمیل Task پروژه و نسخه‌ها

برای Task شخصی `TaskSession::complete` با نسخه Task کار می‌کند؛ برای Task پروژه همین مسیر خطای workflow_command_required دارد. مسیر صحیح `instances()->transition` با شناسه و نسخه نمونه اصلی است. [صفحه Workflow](../04-advanced/workflow.md) این عملیات و تفاوت شمارنده‌ها را با کد اجرایی توضیح می‌دهد. workflow.transition در مجوزهای مثال ساخت پروژه بالا نیست، چون آن مثال انتقال وضعیت اجرا نمی‌کند.

## خطاها و رفع

خطاهای دامنه `Karnoweb\TaskManager\Exceptions\DomainError` هستند؛ خطای Gate از نوع `Illuminate\Auth\Access\AuthorizationException` است. در برنامه وب، نگاشت وضعیت HTTP را میزبان انجام می‌دهد.

| خطا | اقدام |
|---|---|
| AuthorizationException | کاربر برای Gate آموزشی مجاز نیست؛ سیاست معتبر میزبان را بررسی کنید، نه دورزدن Gate. |
| field_invalid / priority_invalid | فقط فیلدهای update و مقادیر priority مستندشده را بفرستید. |
| pagination_invalid | page حداقل ۱ و limit بین ۱ و ۱۰۰ باشد. |
| version_conflict | رکورد را بخوانید، تغییر را بازبینی و فرمان تازه با نسخه فعلی ارسال کنید. |
| preview_stale | پیش‌نمایش را مجدد از وضعیت فعلی بگیرید و پس از تأیید با شناسه تازه اجرا کنید. |
| state_mapping_required | برای وضعیت فعلی، مقصد موجود در تعریف انتخاب کنید. |
| project_invalid | کد پروژه: حرف بزرگ آغازین، سپس حرف بزرگ/رقم، حداکثر ۲۰ نویسه؛ نام غیرخالی تا ۲۵۵ نویسه. |
| project_archived | پروژه فعال مناسب را انتخاب یا از مسیر مجاز بازیابی کنید. |
| subtree_preview_required | کار دارای فرزند است؛ مسیر previewSubtreeMove/moveSubtree را با نگاشت کامل به کار ببرید. |
| pipeline_move_required | نمونه به Pipeline متصل است؛ مسیر previewMove/moveToPipeline لازم است. |
| active_timer_blocks_archive / active_sprint_blocks_archive / active_process_blocks_archive | ابتدا فعالیت مانع را از مسیر مجاز حل کنید؛ کنترل را حذف نکنید. |
| capability_denied / not_found | مجوز، عضویت، scope و شناسه عمومی را بررسی کنید. |

آزمون [TechnicalDocumentationExamplesTest](../../tests/TechnicalDocumentationExamplesTest.php) هر سه بلوک این صفحه، نسخه‌ها، خروجی فهرست، اثر انتقال، منع دسترسی و بازیابی پس از پیش‌نمایش قدیمی را بررسی می‌کند. [پیشنهادهای اصلاح](../API-IMPROVEMENT-PROPOSALS.md) شکاف ساخت مستقیم Task پروژه و محدودیت نشست را ثبت کرده‌اند.
