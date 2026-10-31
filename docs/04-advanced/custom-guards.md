# ثبت و اجرای محافظ سفارشی

[فهرست](../README.md) · [گردش‌کار](workflow.md)

Guard (محافظ انتقال) شرط دامنه میزبان است. اکنون `TransitionGuards` یک مجموعه تغییرناپذیر از نام به callable (تابع قابل فراخوانی) دارد. در Provider (ثبت‌کننده سرویس) میزبان، پیش از ساخت InstanceService آن را به Container (سامانه تزریق وابستگی) متصل کنید؛ زمینه کاربر را در متغیر سراسری یا closure (تابع بی‌نام) ذخیره نکنید.

## اتصال کامل

بلوک زیر فایل مستقل `storage/app/private/task-manager-docs/guards.php` است؛ در Provider میزبان، بدنه اتصال را می‌توان در register گذاشت. این فایل را در نشست Laravel توسعه با require اجرا کنید. خروجی، همان مجموعه ثبت‌شده است؛ بدون جدول یا Migration تازه.

<!-- example: host-transition-guards -->
```php
<?php
namespace App\TaskManagerDocs;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Workflow\TransitionGuards;

$guards = new TransitionGuards([
    'host.approved' => static function (ExecutionContext $context, array $instance, array $payload): bool {
        return $instance['scope_key'] === $context->scope && ($payload['approved'] ?? false) === true;
    },
]);
app()->instance(TransitionGuards::class, $guards);

return $guards;
```

ورودی callback زمینه جاری، آرایه Instance و payload (داده فرمان) است؛ فقط true اجازه ادامه می‌دهد. در محصول، approved را به‌تنهایی از مرورگر دلیل مجوز ندانید؛ این مثال شرط داده را نشان می‌دهد و احراز مجوز همچنان از Context و Subject انجام می‌شود. قاعده تجاری حساس باید داده معتبر سرور را بخواند. مجموعه تغییرناپذیر است، ولی callback میزبان نیز نباید اطلاعات کاربر قبلی را capture (در خود نگه‌داری) کند؛ هر فراخوان زمینه مستقل می‌گیرد.

## استفاده اجرایی

بعد از ثبت guards.php و [Gate آموزشی](../03-basic-usage/tasks-projects.md)، فایل `storage/app/private/task-manager-docs/guarded-workflow.php` را مانند شروع سریع با کاربر احرازهویت‌شده و UUID (شناسه یکتای استاندارد) عملیات اجرا کنید:

<!-- example: guarded-workflow -->
```php
<?php
namespace App\TaskManagerDocs;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Facades\TaskManager;

return static function (Authenticatable $user, string $operation): array {
    Gate::forUser($user)->authorize('task-manager.docs.configure');
    if (! Str::isUuid($operation)) {
        throw new \InvalidArgumentException('operation must be a UUID');
    }
    $actor = (string) $user->getAuthIdentifier();
    $context = new ExecutionContext('personal:'.$actor, $actor, (string) Str::uuid(), capabilities: [
        'task.create', 'task.view', 'task.edit', 'workflow.manage', 'workflow.view', 'workflow.transition',
    ]);
    $revision = TaskManager::workflows()->publish($context, 'docs.guarded.'.str_replace('-', '', strtolower($operation)), 0, $operation.':publish', [
        'initial' => 'open',
        'states' => ['open' => ['terminal' => 'none'], 'done' => ['terminal' => 'success']],
        'transitions' => ['finish' => ['from' => 'open', 'to' => 'done', 'guards' => ['host.approved']]],
    ]);
    $task = TaskManager::tasks()->inContext($context)->create('بررسی محافظ', $operation.':task');
    $started = TaskManager::instances()->start($context, 'task', $task->publicKey, $revision['id'], $operation.':start', slot: 'review');
    $finished = TaskManager::instances()->transition($context, $started['instance_key'], $started['version'], $operation.':finish', 'finish', ['approved' => true]);

    return compact('task', 'started', 'finished');
};
```

نتیجه: review از open/نسخه ۱ به done/نسخه ۲ می‌رود؛ Todo main همچنان pending است. داده‌ها و رویدادها مانند [Workflow مستقل](workflow.md) ثبت می‌شوند. بدون ثبت نام، guard_unregistered؛ با نتیجه غیر true، guard_veto؛ با استثنا، تراکنش انتقال و journal (دفتر عملیات) برمی‌گردند. پس از رفع شرط، قصد تازه شناسه تازه می‌خواهد. محافظ جایگزین مجوز و SubjectHandler::guard و StageLifecycle (زنجیره قواعد چرخه حیات) نمی‌شود؛ آن‌ها همچنان اجرا می‌شوند.

آرایه guards در سازنده قدیمی InstanceService حفظ شده و برای نام مشترک تقدم دارد؛ مقدار نامعتبر آن هم با خطا متوقف می‌شود. آزمون‌های [DeveloperExperienceTest](../../tests/DeveloperExperienceTest.php) فقدان/رد/استثنا، بازگشت تراکنش و تقدم قرارداد قدیمی را می‌سنجند. هر دو بلوک این صفحه مستقیماً در [TechnicalDocumentationExamplesTest](../../tests/TechnicalDocumentationExamplesTest.php) اجرا می‌شوند. زمان ثبت در پردازشگر طولانی‌عمر مهم است: سرویس ساخته‌شده پیشین خودکار اتصال تازه را نمی‌گیرد؛ مجموعه را در راه‌اندازی میزبان ثبت کنید.
