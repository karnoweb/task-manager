<?php

declare(strict_types=1);

$codesFile = __DIR__.'/error_codes.txt';
if (! is_file($codesFile)) {
    fwrite(STDERR, "Run extract_error_codes.php first.\n");
    exit(1);
}
$codes = array_filter(array_map(trim(...), file($codesFile)));

$en = [];
$fa = [];
foreach ($codes as $code) {
    [$en[$code], $fa[$code]] = messages($code);
}

ksort($en);
ksort($fa);

writeLang(dirname(__DIR__).'/lang/en/errors.php', $en);
writeLang(dirname(__DIR__).'/lang/fa/errors.php', $fa);

echo 'Wrote ', count($en), ' keys to lang/en and lang/fa.', PHP_EOL;

/** @return array{0: string, 1: string} */
function messages(string $code): array
{
    $curated = curated();
    if (isset($curated[$code])) {
        return $curated[$code];
    }

    if (str_ends_with($code, '_invalid')) {
        $subject = label(substr($code, 0, -8));

        return [
            "The {$subject} is invalid.",
            "«{$subject}» نامعتبر است.",
        ];
    }
    if (str_ends_with($code, '_required')) {
        $subject = label(substr($code, 0, -9));

        return [
            "{$subject} is required.",
            "«{$subject}» الزامی است.",
        ];
    }
    if (str_ends_with($code, '_denied')) {
        $subject = label(substr($code, 0, -7));

        return [
            "{$subject} was denied.",
            "دسترسی «{$subject}» رد شد.",
        ];
    }
    if (str_ends_with($code, '_conflict')) {
        $subject = label(substr($code, 0, -9));

        return [
            "{$subject} conflicts with the current state.",
            "تعارض «{$subject}» با وضعیت فعلی.",
        ];
    }
    if (str_ends_with($code, '_unavailable') || str_ends_with($code, '_unregistered')) {
        $subject = label(preg_replace('/_(unavailable|unregistered)$/', '', $code) ?? $code);

        return [
            "{$subject} is not available.",
            "«{$subject}» در دسترس نیست.",
        ];
    }
    if (str_ends_with($code, '_not_found')) {
        $subject = label(substr($code, 0, -10));

        return [
            "{$subject} was not found.",
            "«{$subject}» یافت نشد.",
        ];
    }
    if (str_ends_with($code, '_limit') || str_ends_with($code, '_exceeded')) {
        $subject = label($code);

        return [
            "{$subject} limit exceeded.",
            "از حد مجاز «{$subject}» فراتر رفتید.",
        ];
    }

    $subject = label($code);

    return [
        "{$subject}.",
        "«{$subject}».",
    ];
}

function label(string $code): string
{
    return str_replace('_', ' ', $code);
}

/** @return array<string, array{0: string, 1: string}> */
function curated(): array
{
    return [
        'not_found' => ['The requested resource was not found or is not accessible.', 'رکورد وجود ندارد یا قابل مشاهده نیست.'],
        'version_conflict' => ['The resource was changed elsewhere. Refresh and retry with the current version.', 'نسخه قدیمی است؛ داده را دوباره بخوانید و با نسخه فعلی تلاش کنید.'],
        'idempotency_key_conflict' => ['The same operation id was already used with different input.', 'این شناسه عملیات قبلاً با ورودی دیگری استفاده شده است.'],
        'capability_denied' => ['You do not have permission for this action.', 'مجوز این عملیات کافی نیست.'],
        'access_denied' => ['Access was denied.', 'دسترسی رد شد.'],
        'context_invalid' => ['Execution context (actor, scope, or trace) is invalid.', 'زمینه اجرا (کاربر، قلمرو یا ردیابی) نامعتبر است.'],
        'transition_invalid' => ['This workflow transition is not allowed.', 'این انتقال گردش‌کار مجاز نیست.'],
        'transition_policy_denied' => ['Transition is not allowed. Allowed destinations: :allowed_destinations.', 'انتقال مجاز نیست. مقصدهای مجاز: :allowed_destinations.'],
        'transition_role_denied' => ['Your role may not perform this transition.', 'نقش شما اجازه این انتقال را ندارد.'],
        'instance_on_hold' => ['The workflow instance is on hold.', 'نمونه گردش‌کار متوقف (hold) است.'],
        'workflow_command_required' => ['This task belongs to a project workflow; use the instances API.', 'این کار به گردش‌کار پروژه تعلق دارد؛ از مسیر instances استفاده کنید.'],
        'preview_stale' => ['The preview is stale; refresh the preview and retry.', 'پیش‌نمایش کهنه است؛ دوباره بخوانید و تلاش کنید.'],
        'dispatch_before_commit' => ['Outbox dispatch must run after the database transaction commits.', 'ارسال outbox باید پس از commit تراکنش باشد.'],
        'title_invalid' => ['The title is invalid.', 'عنوان نامعتبر است.'],
        'pagination_invalid' => ['Pagination parameters are invalid.', 'پارامترهای صفحه‌بندی نامعتبر است.'],
        'reminder_in_past' => ['The reminder time is in the past.', 'زمان یادآور در گذشته است.'],
        'reminder_recipient_denied' => ['The reminder recipient is not allowed.', 'گیرنده یادآور مجاز نیست.'],
        'host_guard_veto' => ['The host guard rejected this action.', 'نگهبان میزبان این عمل را رد کرد.'],
        'lifecycle_veto' => ['A lifecycle hook rejected this action.', 'قلاب چرخه عمر این عمل را رد کرد.'],
        'destination_not_accepted' => ['The handoff destination did not accept the item.', 'مقصد واگذاری آیتم را نپذیرفت.'],
        'handoff_already_resolved' => ['This handoff is already resolved.', 'این واگذاری قبلاً بسته شده است.'],
        'handoff_timeout_not_due' => ['Handoff timeout actions are not due yet.', 'اقدام انقضای واگذاری هنوز سررسید نشده است.'],
        'handoff_cycle_or_depth' => ['Handoff would create a cycle or exceed depth.', 'واگذاری چرخه یا عمق مجاز را نقض می‌کند.'],
        'group_source_invalid' => ['The group source is invalid.', 'منبع گروه نامعتبر است.'],
        'sla_due_requires_detection' => ['SLA due handling requires a prior detection event.', 'رسیدگی SLA نیاز به رویداد شناسایی قبلی دارد.'],
        'commit_evidence_requires_committed_boundary' => ['Commit evidence requires a committed group boundary.', 'گواهی commit به مرز گروه commit‌شده نیاز دارد.'],
        'external_action_requires_committed_boundary' => ['External action requires a committed group boundary.', 'عمل بیرونی به مرز گروه commit‌شده نیاز دارد.'],
        'service_capabilities_required' => ['Service capabilities must be declared for this call.', 'قابلیت‌های سرویس برای این فراخوانی باید تعریف شوند.'],
        'same_connection_required' => ['Host and package must share the same database connection.', 'میزبان و پکیج باید یک اتصال پایگاه‌داده داشته باشند.'],
        'ui_action_unsupported' => ['This UI action is not supported.', 'این عمل رابط کاربری پشتیبانی نمی‌شود.'],
    ];
}

/** @param  array<string, string>  $lines */
function writeLang(string $path, array $lines): void
{
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $out = "<?php\n\nreturn [\n";
    foreach ($lines as $code => $message) {
        $out .= '    '.var_export($code, true).' => '.var_export($message, true).",\n";
    }
    $out .= "];\n";
    file_put_contents($path, $out);
}
