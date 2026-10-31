# اتصال هویت، شعبه و قلمرو میزبان

هدف: ساخت ExecutionContext (زمینه اجرای تغییرناپذیر) از هویت معتبر Laravel 13، بدون تکرار ساخت دستی آن در هر فرمان. پیش‌نیاز: [نصب و Migration](../installation.md)، middleware احراز هویت و حفظ نگاشت scope_key داده‌های قبلی.

## استفاده سریع

در یک Action (کلاس اجرای عملیات) پشت middleware احراز هویت، بلوک زیر مستقیماً قابل اجراست. فایل پیشنهادی: `app/Actions/CreatePersonalTodo.php`؛ خروجی TaskResult شامل publicKey، version و state است. این بلوک در آزمون مستندات مستقیماً اجرا می‌شود.

<!-- example: resolved-todo -->
```php
<?php
use Illuminate\Support\Str;
use Karnoweb\TaskManager\Facades\TaskManager;

$operation = (string) Str::uuid();
return TaskManager::tasks()->inContext()->create('تماس با مشتری', $operation);
```

شناسه عملیات را یک بار برای فرمان بسازید و در تلاش مجدد همان مقدار و همان ورودی را نگه دارید؛ تولید UUID (شناسه تصادفی یکتا) تازه در هر retry (تلاش مجدد) جلوی تکرار را نمی‌گیرد. correlationId برای هر ساخت زمینه تازه تولید می‌شود و شناسه عملیات نیست. برای فرمان‌های بعدی شناسه عملیات تازه بسازید.

UserResolver (حل‌کننده هویت) باید در میزبان ثبت شود؛ پکیج به Auth یا Request وابسته نیست. نبود حل‌کننده یا کاربر، context_user_missing می‌دهد؛ زمینه صریح معتبر همچنان قابل استفاده است. scope.default برابر `default` است؛ این مقدار تضمین نگاشت به اطلاعات قبلی شما نیست. برای پروژه موجود دقیقاً همان scope قبلی را تنظیم کنید. Todoها علاوه بر Scope (قلمرو جداسازی داده) با مالک و AuthorizationProvider (کنترل مجوز و محدودیت پرس‌وجو) محدود می‌شوند؛ پروژه مشترک باید Scope مشترک معتبر و عضویت پروژه داشته باشد.

## اتصال کامل با کلاس و Container

سه قرارداد امضاهای متفاوت دارند و در سه فایل مستقل پیاده می‌شوند. در برنامه واقعی user.model را به کلاس مدل معتبر میزبان متصل کنید؛ جدول branch_user باید منبع معتبر عضویت میزبان باشد؛ شناسه Actor و درخواست شعبه به‌تنهایی اثبات عضویت نیستند. با شعبه فعال، قلمرو دقیقاً branch:<شناسه> است؛ ScopeResolver اختیاری باید همین مقدار را برگرداند. شناسه شعبه تا ۱۱۳ بایت است. شناسه صفر، UUID و رشته حفظ می‌شوند.

فایل `app/TaskManager/DocumentedHostUser.php`:

<!-- example: host-user-class -->
```php
<?php
namespace App\TaskManager;

use Illuminate\Support\Facades\Auth;
use Karnoweb\TaskManager\Contracts\UserResolver;

final class DocumentedHostUser implements UserResolver
{
    public function resolve(): ?object
    {
        return Auth::user();
    }
}
return DocumentedHostUser::class;
```

فایل `app/TaskManager/DocumentedHostBranch.php`:

<!-- example: host-branch-class -->
```php
<?php
namespace App\TaskManager;

use Karnoweb\TaskManager\Contracts\BranchResolver;
use Illuminate\Support\Facades\DB;

final class DocumentedHostBranch implements BranchResolver
{
    public function resolve(object $user, int|string|null $requested): object|int|string|null
    {
        if ($requested === null) {
            return null;
        }
        // Host-owned membership table; never prefixed by the package.
        return DB::table('branch_user')->where('user_id', (string) $user->getAuthIdentifier())
            ->where('branch_id', (string) $requested)->exists() ? $requested : null;
    }
}
return DocumentedHostBranch::class;
```

فایل `app/TaskManager/DocumentedHostScope.php`:

<!-- example: host-scope-class -->
```php
<?php
namespace App\TaskManager;

use Karnoweb\TaskManager\Contracts\ScopeResolver;

final class DocumentedHostScope implements ScopeResolver
{
    public function resolve(object $user, ?string $branch): int|string|null
    {
        return $branch === null ? data_get($user, 'tm_scope') : 'branch:'.$branch;
    }
}
return DocumentedHostScope::class;
```

در `AppServiceProvider::register()` می‌توانید هر قرارداد را به کلاس متناظر bind کنید. اتصال Container (سامانه تزریق وابستگی) بر مقدار تنظیمات تقدم دارد. روش جایگزینِ قابل config:cache، قرار دادن نام کلاس در config/task-manager.php است؛ این بلوک آرایه تنظیمات اجرایی را برمی‌گرداند و به Closure (تابع بی‌نام) در config نیاز ندارد:

<!-- example: host-resolver-config -->
```php
<?php
use App\TaskManager\DocumentedHostBranch;
use App\TaskManager\DocumentedHostScope;
use App\TaskManager\DocumentedHostUser;

return [
    'task-manager.user.resolver' => DocumentedHostUser::class,
    'task-manager.user.model' => null,
    'task-manager.user.identifier' => null,
    'task-manager.branch.enabled' => true,
    'task-manager.branch.model' => null,
    'task-manager.branch.resolver' => DocumentedHostBranch::class,
    'task-manager.branch.default_id' => 'north',
    'task-manager.scope.resolver' => DocumentedHostScope::class,
];
```

این آرایه برای config([...]) در bootstrap (راه‌اندازی برنامه) است؛ داخل فایل config اصلی، نام‌های user/branch/scope را در ساختار موجود قرار دهید. پس از اتصال، `TaskManager::context(branch: 'north')` زمینه‌ای با Actor کاربر و Scope معتبر می‌دهد. ورودی صریح شعبه ابتدا توسط Resolver (حل‌کننده مقدار) اعتبارسنجی می‌شود؛ شعبه نامجاز با پیش‌فرض جایگزین نمی‌شود. default_id نیز بدون Resolver معتبر پذیرفته نیست. با شعبه غیرفعال، Todo معمولی کار می‌کند و شعبه صریح context_branch_disabled می‌دهد.

user.model و branch.model اختیاری و محدودیت نوع واقعی‌اند. اگر Branch Model تنظیم شود، Resolver باید شیء همان مدل را برگرداند؛ مقدار شناسه مستقیم کافی نیست. user.identifier/branch.identifier مسیر ویژگی را تعیین می‌کنند؛ در حالت null از getAuthIdentifier/getKey و سپس id استفاده می‌شود. شناسه عددی یا رشته‌ای، حتی صفر و UUID، به متن تبدیل می‌شود و به integer تبدیل اجباری نمی‌شود.

## مجوز و عملیات پس‌زمینه

context.capabilities فهرست توانایی‌های پایه Todo است. برای مجوزهای متفاوت از context.capabilities_resolver با نام کلاس invokable (قابل فراخوانی) استفاده کنید؛ امضا __invoke(object $user, string $scope, ?string $branch): array است. قرارداد AuthorizationProvider داخل سرویس‌ها همچنان مجوز و محدودیت داده را اعمال می‌کند؛ Resolver جانشین آن نیست. مجوز Workflow و پروژه به صورت خودکار به همه داده نمی‌شود.

در Queue (صف پردازش) و CLI (خط فرمان)، زمینه صریح و مجوز حداقلی بسازید؛ زمینه صف را از مرورگر deserialize نکنید. فایل پیشنهادی: بخشی از handle در Job میزبان، با Scope و هویت خدمت از تنظیمات معتبر سرور:

<!-- example: explicit-worker-context -->
```php
<?php
use Illuminate\Support\Str;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Facades\TaskManager;

$context = new ExecutionContext('branch:north', 'reminder-worker', (string) Str::uuid(), serviceActor: true, capabilities: ['task.view']);
return TaskManager::context($context);
```

زمینه صریح پس از اعتبارسنجی شعبه بازگردانده می‌شود؛ هم‌زمان branch ندهید. serviceActor دسترسی کامل نیست؛ مجوز و AuthorizationProvider باید اجازه بدهند. زمینه را در Singleton (نمونه سراسری ماندگار) یا Facade ذخیره نکنید؛ برای هر کار تازه زمینه تازه بسازید. TaskSession قبلی عمداً هویت همان نشست را نگه می‌دارد.

## پایگاه داده، خطا و اعتبارسنجی

ساخت زمینه هیچ رکوردی نمی‌نویسد. ساخت Todo یک Task، Inbox لازم، نمونه اصلی گردش‌کار شخصی، ثبت فرمان، ممیزی و Outbox (صف رویداد ماندگار) می‌سازد. خطای Resolver پیش از نوشتن رخ می‌دهد. شعبه در scope_key با مقدار branch:<شناسه> ذخیره می‌شود؛ ستون یا مدل تازه‌ای لازم نیست. در زمینه صریح، حل‌کننده شعبه ActorIdentity با id، getAuthIdentifier و serviceActor می‌گیرد؛ عضویت را براساس شناسه از جدول میزبان branch_user با ستون‌های user_id و branch_id بررسی کنید. برای خدمت پس‌زمینه نیز عضویت صریح لازم است. تغییر ناگهانی Scope داده قدیمی را نامرئی می‌کند؛ به صورت خودکار بازنگاشت نمی‌کنیم.

| خطا | راه رفع |
|---|---|
| context_user_missing | ثبت UserResolver میزبان یا زمینه صریح Job |
| context_branch_scope_conflict | قلمرو دقیق branch:<شناسه معتبر> |
| context_identifier_invalid / context_model_invalid | ویژگی شناسه معتبر، نوع مدل درست و طول حداکثر ۱۲۰ بایت |
| context_branch_denied | عضویت معتبر، بدون اعتماد به داده مرورگر |
| context_branch_resolver_required | ثبت Resolver برای شعبه پیش‌فرض یا صریح |
| context_branch_conflict | استفاده از زمینه صریح یا ساخت با شعبه، یکی در هر فراخوان |
| context_capabilities_invalid / context_resolver_invalid | فهرست رشته‌های معتبر و کلاس یا قرارداد قابل فراخوانی |
| forbidden / not_found | بررسی مجوز واقعی، Scope، مالک و عضویت؛ افزایش مصنوعی capabilities راه‌حل نیست |

اجرا در ریشه مخزن: `php vendor/phpunit/phpunit/phpunit --filter 'ContextFactoryTest|TechnicalDocumentationExamplesTest'`. آزمون‌ها شناسه صفر/رشته/UUID، config قابل کش، شاخه پیش‌فرض/صریح/غایب، منع دسترسی، زمینه صریح و تغییر کاربران در پردازشگر ماندگار را بررسی می‌کنند. نتیجه واقعی اجرای جاری در [راهنمای آزمون](../testing.md) ثبت می‌شود.

در AppServiceProvider::register قرارداد UserResolver را با DocumentedHostUser و BranchResolver را با DocumentedHostBranch متصل کنید؛ نمونه بالا روش جایگزین با نام کلاس در تنظیمات را اجرا می‌کند. تمام کلاس‌های App\TaskManager متعلق به میزبان هستند. مثال Auth فقط در میزبان اجرا می‌شود. حل‌کننده شعبه در هر عملیات عضویت را دوباره بررسی می‌کند؛ داده عضویت را در نمونه سراسری نگه ندارید.
