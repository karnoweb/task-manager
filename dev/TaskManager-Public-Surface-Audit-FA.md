# پیوست ممیزی سطح عمومی فعلی Task Manager

تاریخ: ۲۰۲۶-۱۰-۲۲. منبع: اعلان واقعی تمام فایل‌های PHP در `src/`؛ شامل سازنده‌ها، قراردادها، ابزارهای داخلیِ دارای متد public (قابل فراخوانی عمومی) و اتصال‌های اختیاری. عمومی بودن در زبان به معنی توصیه برای مصرف روزمره نیست. متدهای ارث‌رسیده از Laravel در این شمارش نیستند.

این پیوست مکمل [گزارش تحلیل](TaskManager-Stage-2-DX-Review-FA.md) است؛ امضاها (نام، ورودی و خروجی متد) از متن سورس گرفته شده‌اند. مقادیر پیش‌فرض و نام پارامترها حفظ شده‌اند؛ فاصله‌ها یکسان‌سازی شده‌اند. فایل JSON همراه، اثر انگشت SHA-256 (شناسه محتوای فایل) را برای بازبینی بعدی نگه می‌دارد.

پوشش: 148 فایل؛ 592 اعلان صریح public. این عدد شامل متدهای private (خصوصی) نیست؛ برچسب is_exported در گراف برای این تفکیک کافی نبود و متن سورس مبنا قرار گرفت.

## `src/Approval/ApprovalEngine.php`

[سورس](../src/Approval/ApprovalEngine.php)

| خط | امضای فعلی |
|---|---|
| [16](../src/Approval/ApprovalEngine.php#L16) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly ResponsibilityResolver $resolver)` |
| [20](../src/Approval/ApprovalEngine.php#L20) | `public function validate(array $policy, array $workflow, string $state): array` |
| [66](../src/Approval/ApprovalEngine.php#L66) | `public function create(ExecutionContext $context, array $instance, int $policyId, string $operation, ?int $visitId = null): array` |
| [88](../src/Approval/ApprovalEngine.php#L88) | `public function policy(object $step): array` |
| [98](../src/Approval/ApprovalEngine.php#L98) | `public function assertVoter(ExecutionContext $context, object $step, array $policy): void` |
| [106](../src/Approval/ApprovalEngine.php#L106) | `public function beforeTransition(ExecutionContext $context, array $instance, array $edge, array $payload): void` |
| [135](../src/Approval/ApprovalEngine.php#L135) | `public function entered(ExecutionContext $context, int $instanceId, int $visitId, object $stage, string $operationId): void` |
| [144](../src/Approval/ApprovalEngine.php#L144) | `public function leaving(ExecutionContext $context, int $instanceId): void` |
| [157](../src/Approval/ApprovalEngine.php#L157) | `public function activated(ExecutionContext $context, int $instanceId, string $operationId): void` |
| [161](../src/Approval/ApprovalEngine.php#L161) | `public function activity(ExecutionContext $context, int $instanceId): void` |

## `src/Approval/ApprovalService.php`

[سورس](../src/Approval/ApprovalService.php)

| خط | امضای فعلی |
|---|---|
| [19](../src/Approval/ApprovalService.php#L19) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly ApprovalEngine $engine, private readonly InstanceService $instances, private readonly PipelineService $pipelines)` |
| [40](../src/Approval/ApprovalService.php#L40) | `public function get(ExecutionContext $context, string $key): array` |
| [56](../src/Approval/ApprovalService.php#L56) | `public function active(ExecutionContext $context, string $instanceKey): ?array` |
| [67](../src/Approval/ApprovalService.php#L67) | `public function begin(ExecutionContext $context, string $key, int $version, string $operation, array $policy): array` |
| [88](../src/Approval/ApprovalService.php#L88) | `public function configureStage(ExecutionContext $context, string $pipelineKey, string $stageKey, int $version, string $operation, array $policy): array` |
| [117](../src/Approval/ApprovalService.php#L117) | `public function vote(ExecutionContext $context, string $stepKey, int $stepVersion, int $instanceVersion, string $operation, string $vote, ?string $reason = null): array` |
| [174](../src/Approval/ApprovalService.php#L174) | `public function execute(ExecutionContext $context, string $stepKey, int $stepVersion, int $instanceVersion, string $operation): array` |
| [232](../src/Approval/ApprovalService.php#L232) | `public function cancel(ExecutionContext $context, string $stepKey, int $stepVersion, int $instanceVersion, string $operation, string $reason): array` |

## `src/AuditRedactor.php`

[سورس](../src/AuditRedactor.php)

| خط | امضای فعلی |
|---|---|
| [7](../src/AuditRedactor.php#L7) | `public static function redact(array $values): array` |

## `src/Collaboration/AttachmentService.php`

[سورس](../src/Collaboration/AttachmentService.php)

| خط | امضای فعلی |
|---|---|
| [18](../src/Collaboration/AttachmentService.php#L18) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly TaskResourceService $resources, private readonly AttachmentStorage $storage)` |
| [28](../src/Collaboration/AttachmentService.php#L28) | `public function attach(ExecutionContext $context, string $taskKey, string $reference, string $operation): array` |
| [48](../src/Collaboration/AttachmentService.php#L48) | `public function issueTicket(ExecutionContext $context, string $taskKey, string $attachmentKey, int $lifetimeSeconds = 120): array` |
| [73](../src/Collaboration/AttachmentService.php#L73) | `public function download(ExecutionContext $context, string $taskKey, string $attachmentKey, string $token): mixed` |

## `src/Collaboration/ChecklistService.php`

[سورس](../src/Collaboration/ChecklistService.php)

| خط | امضای فعلی |
|---|---|
| [22](../src/Collaboration/ChecklistService.php#L22) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly ResponsibilityResolver $resolver, private readonly HierarchyService $hierarchy, private readonly ProjectService $projects, private readonly WorkflowService $workflows)` |
| [40](../src/Collaboration/ChecklistService.php#L40) | `public function create(ExecutionContext $context, string $taskKey, string $title, string $operation): array` |
| [61](../src/Collaboration/ChecklistService.php#L61) | `public function get(ExecutionContext $context, string $key): array` |
| [75](../src/Collaboration/ChecklistService.php#L75) | `public function change(ExecutionContext $context, string $key, int $version, string $title, bool $deleted, string $operation): array` |
| [103](../src/Collaboration/ChecklistService.php#L103) | `public function addItem(ExecutionContext $context, string $listKey, int $version, string $title, ?string $assignee, ?\DateTimeImmutable $due, string $operation): array` |
| [128](../src/Collaboration/ChecklistService.php#L128) | `public function item(ExecutionContext $context, string $key): array` |
| [142](../src/Collaboration/ChecklistService.php#L142) | `public function changeItem(ExecutionContext $context, string $key, int $version, array $changes, string $operation): array` |
| [194](../src/Collaboration/ChecklistService.php#L194) | `public function convert(ExecutionContext $context, string $key, int $version, string $operation): array` |
| [230](../src/Collaboration/ChecklistService.php#L230) | `public function items(ExecutionContext $context, string $listKey, ?string $assignee = null, ?\DateTimeImmutable $dueBefore = null, int $afterId = 0, int $limit = 100): array` |
| [253](../src/Collaboration/ChecklistService.php#L253) | `public function progress(ExecutionContext $context, string $taskKey): array` |

## `src/Collaboration/CollaborationService.php`

[سورس](../src/Collaboration/CollaborationService.php)

| خط | امضای فعلی |
|---|---|
| [17](../src/Collaboration/CollaborationService.php#L17) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly TaskResourceService $resources)` |
| [27](../src/Collaboration/CollaborationService.php#L27) | `public function comment(ExecutionContext $context, string $taskKey, string $body, array $mentions, ?string $parentKey, string $operation): array` |
| [66](../src/Collaboration/CollaborationService.php#L66) | `public function editComment(ExecutionContext $context, string $key, int $version, ?string $body, string $operation): array` |
| [73](../src/Collaboration/CollaborationService.php#L73) | `public function history(ExecutionContext $context, string $key, int $afterVersion = 0, int $limit = 100): array` |
| [88](../src/Collaboration/CollaborationService.php#L88) | `public function watch(ExecutionContext $context, string $taskKey, int $version, string $actor, bool $enabled, string $operation): array` |
| [117](../src/Collaboration/CollaborationService.php#L117) | `public function notificationRecipients(ExecutionContext $context, string $taskKey, ?string $commentKey = null): array` |
| [140](../src/Collaboration/CollaborationService.php#L140) | `public function restore(ExecutionContext $context, string $kind, string $key, int $version, string $operation): array` |

## `src/Collaboration/RecipientAccess.php`

[سورس](../src/Collaboration/RecipientAccess.php)

| خط | امضای فعلی |
|---|---|
| [11](../src/Collaboration/RecipientAccess.php#L11) | `public static function permitted(TaskService $tasks, ExecutionContext $context, int $taskId, string $actor): bool` |

## `src/Collaboration/UnavailableAttachmentStorage.php`

[سورس](../src/Collaboration/UnavailableAttachmentStorage.php)

| خط | امضای فعلی |
|---|---|
| [11](../src/Collaboration/UnavailableAttachmentStorage.php#L11) | `public function inspect(ExecutionContext $context, string $reference): array` |
| [16](../src/Collaboration/UnavailableAttachmentStorage.php#L16) | `public function read(ExecutionContext $context, string $reference): mixed` |

## `src/CommandExecutor.php`

[سورس](../src/CommandExecutor.php)

| خط | امضای فعلی |
|---|---|
| [11](../src/CommandExecutor.php#L11) | `public function __construct(private readonly Connection $db)` |
| [29](../src/CommandExecutor.php#L29) | `public function execute(ExecutionContext $context, string $operation, array $payload, callable $execute, callable $authorizeReplay): array` |

## `src/Context/ExecutionContext.php`

[سورس](../src/Context/ExecutionContext.php)

| خط | امضای فعلی |
|---|---|
| [12](../src/Context/ExecutionContext.php#L12) | `public function __construct(public string $scope, public string $actor, public string $correlationId, public bool $serviceActor = false, ?array $capabilities = null, public ?string $originEventId = null, public int $automationDepth = 0)` |
| [28](../src/Context/ExecutionContext.php#L28) | `public function require(string $capability): void` |

اعلان‌های ویژگی عمومی خارج از خط اصلی سازنده (ویژگی‌های ارتقایافته نیز در امضای سازنده آمده‌اند): `public array $capabilities;`

## `src/ContextAuthorization.php`

[سورس](../src/ContextAuthorization.php)

| خط | امضای فعلی |
|---|---|
| [11](../src/ContextAuthorization.php#L11) | `public function authorize(ExecutionContext $context, string $capability): void` |
| [16](../src/ContextAuthorization.php#L16) | `public function constrain(Builder $query, ExecutionContext $context, string $resource, string $capability): void` |

## `src/Contracts/AttachmentStorage.php`

[سورس](../src/Contracts/AttachmentStorage.php)

| خط | امضای فعلی |
|---|---|
| [10](../src/Contracts/AttachmentStorage.php#L10) | `public function inspect(ExecutionContext $context, string $reference): array` |
| [13](../src/Contracts/AttachmentStorage.php#L13) | `public function read(ExecutionContext $context, string $reference): mixed` |

## `src/Contracts/AuthorizationProvider.php`

[سورس](../src/Contracts/AuthorizationProvider.php)

| خط | امضای فعلی |
|---|---|
| [11](../src/Contracts/AuthorizationProvider.php#L11) | `public function authorize(ExecutionContext $context, string $capability): void` |
| [14](../src/Contracts/AuthorizationProvider.php#L14) | `public function constrain(Builder $query, ExecutionContext $context, string $resource, string $capability): void` |

## `src/Contracts/AuthorizationRevision.php`

[سورس](../src/Contracts/AuthorizationRevision.php)

| خط | امضای فعلی |
|---|---|
| [9](../src/Contracts/AuthorizationRevision.php#L9) | `public function revision(ExecutionContext $context): string` |

## `src/Contracts/Clock.php`

[سورس](../src/Contracts/Clock.php)

| خط | امضای فعلی |
|---|---|
| [7](../src/Contracts/Clock.php#L7) | `public function now(): \DateTimeImmutable` |

## `src/Contracts/CommitEvidenceProvider.php`

[سورس](../src/Contracts/CommitEvidenceProvider.php)

| خط | امضای فعلی |
|---|---|
| [11](../src/Contracts/CommitEvidenceProvider.php#L11) | `public function committedAt(ExecutionContext $context, array $intent): ?\DateTimeImmutable` |

## `src/Contracts/DnsResolver.php`

[سورس](../src/Contracts/DnsResolver.php)

| خط | امضای فعلی |
|---|---|
| [8](../src/Contracts/DnsResolver.php#L8) | `public function addresses(string $host): array` |

## `src/Contracts/EntryRoutingProvider.php`

[سورس](../src/Contracts/EntryRoutingProvider.php)

| خط | امضای فعلی |
|---|---|
| [10](../src/Contracts/EntryRoutingProvider.php#L10) | `public function authorize(ExecutionContext $context, string $routingKey): void` |
| [12](../src/Contracts/EntryRoutingProvider.php#L12) | `public function parent(ExecutionContext $context, string $routingKey): ?string` |

## `src/Contracts/ExportStorage.php`

[سورس](../src/Contracts/ExportStorage.php)

| خط | امضای فعلی |
|---|---|
| [10](../src/Contracts/ExportStorage.php#L10) | `public function store(ExecutionContext $context, iterable $chunks): string` |
| [12](../src/Contracts/ExportStorage.php#L12) | `public function read(ExecutionContext $context, string $reference): mixed` |
| [14](../src/Contracts/ExportStorage.php#L14) | `public function delete(ExecutionContext $context, string $reference): void` |

## `src/Contracts/GroupSourceProvider.php`

[سورس](../src/Contracts/GroupSourceProvider.php)

| خط | امضای فعلی |
|---|---|
| [10](../src/Contracts/GroupSourceProvider.php#L10) | `public function authorize(ExecutionContext $context, string $sourceId, string $action): void` |
| [13](../src/Contracts/GroupSourceProvider.php#L13) | `public function closure(ExecutionContext $context, string $sourceId): array` |

## `src/Contracts/LocalSubjectRouter.php`

[سورس](../src/Contracts/LocalSubjectRouter.php)

| خط | امضای فعلی |
|---|---|
| [11](../src/Contracts/LocalSubjectRouter.php#L11) | `public function connection(): Connection` |
| [13](../src/Contracts/LocalSubjectRouter.php#L13) | `public function move(ExecutionContext $context, array $origin, string $targetPipeline, string $operation): void` |
| [16](../src/Contracts/LocalSubjectRouter.php#L16) | `public function spawn(ExecutionContext $context, array $origin, string $operation): array` |

## `src/Contracts/NotificationPreferences.php`

[سورس](../src/Contracts/NotificationPreferences.php)

| خط | امضای فعلی |
|---|---|
| [10](../src/Contracts/NotificationPreferences.php#L10) | `public function nextAllowed(ExecutionContext $context, string $channel, \DateTimeImmutable $at): ?\DateTimeImmutable` |

## `src/Contracts/NotificationTransport.php`

[سورس](../src/Contracts/NotificationTransport.php)

| خط | امضای فعلی |
|---|---|
| [10](../src/Contracts/NotificationTransport.php#L10) | `public function deliver(ExecutionContext $context, string $eventId, string $recipient, string $channel, array $safeEnvelope): void` |

## `src/Contracts/ReminderRecipients.php`

[سورس](../src/Contracts/ReminderRecipients.php)

| خط | امضای فعلی |
|---|---|
| [10](../src/Contracts/ReminderRecipients.php#L10) | `public function suggest(ExecutionContext $context, ?string $type, ?string $id): string` |
| [12](../src/Contracts/ReminderRecipients.php#L12) | `public function authorize(ExecutionContext $context, string $recipient, ?string $type, ?string $id): void` |

## `src/Contracts/RemoteSubjectRouter.php`

[سورس](../src/Contracts/RemoteSubjectRouter.php)

| خط | امضای فعلی |
|---|---|
| [11](../src/Contracts/RemoteSubjectRouter.php#L11) | `public function prepare(ExecutionContext $context, array $origin): array` |
| [14](../src/Contracts/RemoteSubjectRouter.php#L14) | `public function create(ExecutionContext $context, array $payload, string $operation): array` |
| [17](../src/Contracts/RemoteSubjectRouter.php#L17) | `public function lookup(ExecutionContext $context, string $operation): ?array` |
| [19](../src/Contracts/RemoteSubjectRouter.php#L19) | `public function compensate(ExecutionContext $context, array $receipt, string $operation): bool` |

## `src/Contracts/ResponsibilityResolver.php`

[سورس](../src/Contracts/ResponsibilityResolver.php)

| خط | امضای فعلی |
|---|---|
| [10](../src/Contracts/ResponsibilityResolver.php#L10) | `public function candidates(ExecutionContext $context, string $reference, string $purpose): array` |
| [12](../src/Contracts/ResponsibilityResolver.php#L12) | `public function eligible(ExecutionContext $context, string $actor, string $purpose): bool` |

## `src/Contracts/SecretVault.php`

[سورس](../src/Contracts/SecretVault.php)

| خط | امضای فعلی |
|---|---|
| [9](../src/Contracts/SecretVault.php#L9) | `public function resolve(ExecutionContext $context, string $reference): string` |

## `src/Contracts/StageLifecycle.php`

[سورس](../src/Contracts/StageLifecycle.php)

| خط | امضای فعلی |
|---|---|
| [9](../src/Contracts/StageLifecycle.php#L9) | `public function activated(ExecutionContext $context, int $instanceId, string $operationId): void` |
| [11](../src/Contracts/StageLifecycle.php#L11) | `public function entered(ExecutionContext $context, int $instanceId, int $visitId, object $stage, string $operationId): void` |
| [13](../src/Contracts/StageLifecycle.php#L13) | `public function leaving(ExecutionContext $context, int $instanceId): void` |
| [15](../src/Contracts/StageLifecycle.php#L15) | `public function activity(ExecutionContext $context, int $instanceId): void` |
| [17](../src/Contracts/StageLifecycle.php#L17) | `public function beforeTransition(ExecutionContext $context, array $instance, array $edge, array $payload): void` |

## `src/Contracts/SubjectHandler.php`

[سورس](../src/Contracts/SubjectHandler.php)

| خط | امضای فعلی |
|---|---|
| [11](../src/Contracts/SubjectHandler.php#L11) | `public function query(ExecutionContext $context, string $capability): Builder` |
| [13](../src/Contracts/SubjectHandler.php#L13) | `public function idColumn(): string` |
| [15](../src/Contracts/SubjectHandler.php#L15) | `public function scopeColumn(): string` |
| [18](../src/Contracts/SubjectHandler.php#L18) | `public function guard(ExecutionContext $context, string $subjectId, array $instance, string $action, array $payload): void` |

## `src/Contracts/WebhookTransport.php`

[سورس](../src/Contracts/WebhookTransport.php)

| خط | امضای فعلی |
|---|---|
| [10](../src/Contracts/WebhookTransport.php#L10) | `public function post(WebhookTarget $target, string $body, array $headers): array` |

## `src/Contracts/WorkingCalendar.php`

[سورس](../src/Contracts/WorkingCalendar.php)

| خط | امضای فعلی |
|---|---|
| [7](../src/Contracts/WorkingCalendar.php#L7) | `public function addSeconds(\DateTimeImmutable $start, int $seconds): \DateTimeImmutable` |
| [9](../src/Contracts/WorkingCalendar.php#L9) | `public function secondsBetween(\DateTimeImmutable $start, \DateTimeImmutable $end): int` |

## `src/DTO/CreateTodo.php`

[سورس](../src/DTO/CreateTodo.php)

| خط | امضای فعلی |
|---|---|
| [9](../src/DTO/CreateTodo.php#L9) | `public function __construct(public ExecutionContext $context, public string $title, public string $operationId)` |

## `src/DTO/MutateTask.php`

[سورس](../src/DTO/MutateTask.php)

| خط | امضای فعلی |
|---|---|
| [9](../src/DTO/MutateTask.php#L9) | `public function __construct(public ExecutionContext $context, public string $publicKey, public int $expectedVersion, public string $operationId, public string $action, public array $values = [])` |

اعلان‌های ویژگی عمومی خارج از خط اصلی سازنده (ویژگی‌های ارتقایافته نیز در امضای سازنده آمده‌اند): `public string $operationId, public string $action, public array $values = [])`

## `src/DTO/TaskQuery.php`

[سورس](../src/DTO/TaskQuery.php)

| خط | امضای فعلی |
|---|---|
| [9](../src/DTO/TaskQuery.php#L9) | `public function __construct(public ExecutionContext $context, public int $page = 1, public int $limit = 30)` |

## `src/DTO/TaskResult.php`

[سورس](../src/DTO/TaskResult.php)

| خط | امضای فعلی |
|---|---|
| [7](../src/DTO/TaskResult.php#L7) | `public function __construct(public string $publicKey, public string $displayKey, public string $title, public string $scope, public string $owner, public string $state, public int $version, public ?string $completedAt)` |
| [12](../src/DTO/TaskResult.php#L12) | `public static function fromArray(array $task): self` |

## `src/Enterprise/CustomFieldService.php`

[سورس](../src/Enterprise/CustomFieldService.php)

| خط | امضای فعلی |
|---|---|
| [24](../src/Enterprise/CustomFieldService.php#L24) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly ResponsibilityResolver $resolver)` |
| [34](../src/Enterprise/CustomFieldService.php#L34) | `public function create(ExecutionContext $context, string $name, string $type, array $options, ?string $readCapability, string $operation): array` |
| [53](../src/Enterprise/CustomFieldService.php#L53) | `public function get(ExecutionContext $context, string $key): array` |
| [70](../src/Enterprise/CustomFieldService.php#L70) | `public function archive(ExecutionContext $context, string $key, int $version, bool $archived, string $operation): array` |
| [87](../src/Enterprise/CustomFieldService.php#L87) | `public function forms(ExecutionContext $context, int $afterId = 0, int $limit = 100): array` |
| [107](../src/Enterprise/CustomFieldService.php#L107) | `public function set(ExecutionContext $context, string $taskKey, int $taskVersion, string $fieldKey, int $fieldVersion, mixed $value, string $operation): array` |
| [193](../src/Enterprise/CustomFieldService.php#L193) | `public function value(ExecutionContext $context, string $taskKey, string $fieldKey): array` |
| [207](../src/Enterprise/CustomFieldService.php#L207) | `public function filter(Builder $query, ExecutionContext $context, string $fieldKey, string $operator, mixed $value): Builder` |
| [228](../src/Enterprise/CustomFieldService.php#L228) | `public function sortQuery(ExecutionContext $context, string $fieldKey): array` |

## `src/Enterprise/ExportService.php`

[سورس](../src/Enterprise/ExportService.php)

| خط | امضای فعلی |
|---|---|
| [23](../src/Enterprise/ExportService.php#L23) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly AuthorizationRevision $revision, private readonly QueryService $queries, private readonly TaskService $tasks, private readonly InstanceService $instances, private readonly GroupService $groups, private readonly ExportStorage $storage)` |
| [59](../src/Enterprise/ExportService.php#L59) | `public function enqueue(ExecutionContext $context, array $input, string $operation, int $lifetimeSeconds = 3600): array` |
| [82](../src/Enterprise/ExportService.php#L82) | `public function get(ExecutionContext $context, string $key): array` |
| [110](../src/Enterprise/ExportService.php#L110) | `public function stream(ExecutionContext $context, array $input): \Generator` |
| [166](../src/Enterprise/ExportService.php#L166) | `public function build(ExecutionContext $context, string $key, int $version): array` |
| [243](../src/Enterprise/ExportService.php#L243) | `public function download(ExecutionContext $context,string $key): mixed` |

## `src/Enterprise/FieldValue.php`

[سورس](../src/Enterprise/FieldValue.php)

| خط | امضای فعلی |
|---|---|
| [9](../src/Enterprise/FieldValue.php#L9) | `public static function number(mixed $value): int` |
| [21](../src/Enterprise/FieldValue.php#L21) | `public static function format(int $scaled): string` |

## `src/Enterprise/LocalExportStorage.php`

[سورس](../src/Enterprise/LocalExportStorage.php)

| خط | امضای فعلی |
|---|---|
| [11](../src/Enterprise/LocalExportStorage.php#L11) | `public function __construct(private string $directory, private int $maxBytes = 104857600)` |
| [39](../src/Enterprise/LocalExportStorage.php#L39) | `public function store(ExecutionContext $context, iterable $chunks): string` |
| [71](../src/Enterprise/LocalExportStorage.php#L71) | `public function read(ExecutionContext $context, string $reference): mixed` |
| [84](../src/Enterprise/LocalExportStorage.php#L84) | `public function delete(ExecutionContext $context, string $reference): void` |

## `src/Enterprise/PackageAuthorizationRevision.php`

[سورس](../src/Enterprise/PackageAuthorizationRevision.php)

| خط | امضای فعلی |
|---|---|
| [11](../src/Enterprise/PackageAuthorizationRevision.php#L11) | `public function revision(ExecutionContext $context): string` |

## `src/Enterprise/PresetService.php`

[سورس](../src/Enterprise/PresetService.php)

| خط | امضای فعلی |
|---|---|
| [18](../src/Enterprise/PresetService.php#L18) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly ChecklistService $checklists)` |
| [28](../src/Enterprise/PresetService.php#L28) | `public function create(ExecutionContext $context, string $name, array $definition, string $operation): array` |
| [60](../src/Enterprise/PresetService.php#L60) | `public function get(ExecutionContext $context, string $key): array` |
| [73](../src/Enterprise/PresetService.php#L73) | `public function instantiate(ExecutionContext $context, string $key, string $operation): array` |

## `src/Enterprise/QueryService.php`

[سورس](../src/Enterprise/QueryService.php)

| خط | امضای فعلی |
|---|---|
| [18](../src/Enterprise/QueryService.php#L18) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly AuthorizationRevision $revision, private readonly TaskService $tasks, private readonly CustomFieldService $fields)` |
| [22](../src/Enterprise/QueryService.php#L22) | `public function base(ExecutionContext $context, array $filters): Builder` |
| [89](../src/Enterprise/QueryService.php#L89) | `public function tasks(ExecutionContext $context, array $filters = [], string $sort = 'id', string $direction = 'asc', int $limit = 30, ?string $cursor = null): array` |
| [148](../src/Enterprise/QueryService.php#L148) | `public function reindex(ExecutionContext $context, int $afterId = 0, int $limit = 1000): array` |

## `src/Enterprise/ReportService.php`

[سورس](../src/Enterprise/ReportService.php)

| خط | امضای فعلی |
|---|---|
| [22](../src/Enterprise/ReportService.php#L22) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly AuthorizationRevision $revision, private readonly InstanceService $instances, private readonly ProjectService $projects, private readonly GroupService $groups)` |
| [149](../src/Enterprise/ReportService.php#L149) | `public function read(ExecutionContext $context, array $input = [], bool $truthFallback = true): array` |
| [163](../src/Enterprise/ReportService.php#L163) | `public function rebuild(ExecutionContext $context, array $input, string $operation): array` |

## `src/Enterprise/TextNormalizer.php`

[سورس](../src/Enterprise/TextNormalizer.php)

| خط | امضای فعلی |
|---|---|
| [9](../src/Enterprise/TextNormalizer.php#L9) | `public static function normalize(string $value): string` |

## `src/Enterprise/VisibilityEpoch.php`

[سورس](../src/Enterprise/VisibilityEpoch.php)

| خط | امضای فعلی |
|---|---|
| [10](../src/Enterprise/VisibilityEpoch.php#L10) | `public static function bump(Connection $db, string $scope): void` |
| [16](../src/Enterprise/VisibilityEpoch.php#L16) | `public static function fingerprint(Connection $db, ExecutionContext $context, string $hostRevision): string` |

## `src/EventRecorder.php`

[سورس](../src/EventRecorder.php)

| خط | امضای فعلی |
|---|---|
| [13](../src/EventRecorder.php#L13) | `public function __construct(private readonly Connection $db, private readonly Clock $clock = new SystemClock)` |
| [17](../src/EventRecorder.php#L17) | `public function append(ExecutionContext $context, string $type, string $key, string $action, string $operation, array $safePayload, ?int $taskId = null): void` |

## `src/Exceptions/DomainError.php`

[سورس](../src/Exceptions/DomainError.php)

| خط | امضای فعلی |
|---|---|
| [7](../src/Exceptions/DomainError.php#L7) | `public function __construct(string $message, public readonly array $details = [], ?\Throwable $previous = null)` |

## `src/Facades/TaskManager.php`

[سورس](../src/Facades/TaskManager.php)

اعلان صریح متد public ندارد؛ نوع/قرارداد آن در سورس بررسی شود.

## `src/Group/GroupService.php`

[سورس](../src/Group/GroupService.php)

| خط | امضای فعلی |
|---|---|
| [19](../src/Group/GroupService.php#L19) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly InstanceService $instances, private readonly GroupSourceRegistry $sources)` |
| [30](../src/Group/GroupService.php#L30) | `public function get(ExecutionContext $context, string $key): array` |
| [69](../src/Group/GroupService.php#L69) | `public function register(ExecutionContext $context, string $sourceType, string $sourceId, array $policy, string $operation): array` |
| [91](../src/Group/GroupService.php#L91) | `public function publishPolicy(ExecutionContext $context, string $key, int $version, array $policy, string $operation): array` |
| [110](../src/Group/GroupService.php#L110) | `public function enroll(ExecutionContext $context, string $key, int $version, array $instances, string $operation): array` |
| [199](../src/Group/GroupService.php#L199) | `public function previewClosure(ExecutionContext $context, string $key): array` |
| [237](../src/Group/GroupService.php#L237) | `public function previewDelta(ExecutionContext $context, array $previous): array` |
| [251](../src/Group/GroupService.php#L251) | `public function executeClosure(ExecutionContext $context, array $preview, string $runKey): array` |
| [287](../src/Group/GroupService.php#L287) | `public function runInfo(ExecutionContext $context, string $key): array` |
| [304](../src/Group/GroupService.php#L304) | `public function process(ExecutionContext $context, string $runKey, int $limit = 25): array` |
| [425](../src/Group/GroupService.php#L425) | `public function report(ExecutionContext $context, string $key, int $limit = 100, int $afterId = 0): array` |

## `src/Group/GroupSourceRegistry.php`

[سورس](../src/Group/GroupSourceRegistry.php)

| خط | امضای فعلی |
|---|---|
| [11](../src/Group/GroupSourceRegistry.php#L11) | `public function __construct(private array $providers = [])` |
| [21](../src/Group/GroupSourceRegistry.php#L21) | `public function provider(string $key): GroupSourceProvider` |

## `src/Group/MilestoneService.php`

[سورس](../src/Group/MilestoneService.php)

| خط | امضای فعلی |
|---|---|
| [18](../src/Group/MilestoneService.php#L18) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly InstanceService $instances, private readonly CommitEvidenceProvider $evidence)` |
| [29](../src/Group/MilestoneService.php#L29) | `public function get(ExecutionContext $context, string $intentKey): array` |
| [49](../src/Group/MilestoneService.php#L49) | `public function verify(ExecutionContext $context, string $intentKey, int $instanceVersion, string $operation): array` |
| [79](../src/Group/MilestoneService.php#L79) | `public function run(ExecutionContext $context, int $limit = 100): array` |

## `src/Group/PostgresCommitEvidence.php`

[سورس](../src/Group/PostgresCommitEvidence.php)

| خط | امضای فعلی |
|---|---|
| [13](../src/Group/PostgresCommitEvidence.php#L13) | `public function __construct(private readonly Connection $db)` |
| [17](../src/Group/PostgresCommitEvidence.php#L17) | `public function committedAt(ExecutionContext $context, array $intent): ?\DateTimeImmutable` |

## `src/Group/UnverifiedCommitEvidence.php`

[سورس](../src/Group/UnverifiedCommitEvidence.php)

| خط | امضای فعلی |
|---|---|
| [11](../src/Group/UnverifiedCommitEvidence.php#L11) | `public function committedAt(ExecutionContext $context, array $intent): ?\DateTimeImmutable` |

## `src/Handoff/ExternalSagaService.php`

[سورس](../src/Handoff/ExternalSagaService.php)

| خط | امضای فعلی |
|---|---|
| [20](../src/Handoff/ExternalSagaService.php#L20) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly InstanceService $instances, private readonly CaseService $cases, private readonly SubjectRouteRegistry $routes)` |
| [38](../src/Handoff/ExternalSagaService.php#L38) | `public function get(ExecutionContext $context, string $key): array` |
| [62](../src/Handoff/ExternalSagaService.php#L62) | `public function begin(ExecutionContext $context, string $originKey, int $version, string $route, string $transition, string $reason, string $operation): array` |
| [97](../src/Handoff/ExternalSagaService.php#L97) | `public function execute(ExecutionContext $context, string $key): array` |
| [210](../src/Handoff/ExternalSagaService.php#L210) | `public function repair(ExecutionContext $context, string $key, int $version): array` |
| [248](../src/Handoff/ExternalSagaService.php#L248) | `public function run(ExecutionContext $context, int $limit = 50): array` |

## `src/Handoff/HandoffLifecycle.php`

[سورس](../src/Handoff/HandoffLifecycle.php)

| خط | امضای فعلی |
|---|---|
| [11](../src/Handoff/HandoffLifecycle.php#L11) | `public function activated(ExecutionContext $context, int $instanceId, string $operationId): void` |
| [15](../src/Handoff/HandoffLifecycle.php#L15) | `public function entered(ExecutionContext $context, int $instanceId, int $visitId, object $stage, string $operationId): void` |
| [19](../src/Handoff/HandoffLifecycle.php#L19) | `public function leaving(ExecutionContext $context, int $instanceId): void` |
| [23](../src/Handoff/HandoffLifecycle.php#L23) | `public function activity(ExecutionContext $context, int $instanceId): void` |
| [27](../src/Handoff/HandoffLifecycle.php#L27) | `public function beforeTransition(ExecutionContext $context, array $instance, array $edge, array $payload): void` |

## `src/Handoff/HandoffService.php`

[سورس](../src/Handoff/HandoffService.php)

| خط | امضای فعلی |
|---|---|
| [23](../src/Handoff/HandoffService.php#L23) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly InstanceService $instances, private readonly WorkflowService $workflows, private readonly CaseService $cases, private readonly SlaService $sla, private readonly AssignmentEngine $assignments)` |
| [36](../src/Handoff/HandoffService.php#L36) | `public function get(ExecutionContext $context, string $key): array` |
| [90](../src/Handoff/HandoffService.php#L90) | `public function delegate(ExecutionContext $context, string $originKey, int $version, string $pipelineKey, int $revisionId, string $slot, array $policy, string $operation): array` |
| [95](../src/Handoff/HandoffService.php#L95) | `public function parallel(ExecutionContext $context, string $originKey, int $version, array $destinations, array $policy, array $join, string $operation): array` |
| [207](../src/Handoff/HandoffService.php#L207) | `public function settle(ExecutionContext $context, string $key, int $version, string $operation): array` |
| [230](../src/Handoff/HandoffService.php#L230) | `public function recall(ExecutionContext $context, string $key, int $version, string $operation, bool $timeout = false): array` |
| [259](../src/Handoff/HandoffService.php#L259) | `public function getGroup(ExecutionContext $context, string $key): array` |
| [278](../src/Handoff/HandoffService.php#L278) | `public function manualJoin(ExecutionContext $context, string $groupKey, int $version, string $operation): array` |
| [315](../src/Handoff/HandoffService.php#L315) | `public function retryReturn(ExecutionContext $context, string $key, int $version, int $originVersion, string $reason, string $operation): array` |
| [349](../src/Handoff/HandoffService.php#L349) | `public function closeConflict(ExecutionContext $context, string $key, int $version, string $operation): array` |
| [394](../src/Handoff/HandoffService.php#L394) | `public function run(ExecutionContext $context, int $limit = 100): array` |

## `src/Handoff/LocalRouteService.php`

[سورس](../src/Handoff/LocalRouteService.php)

| خط | امضای فعلی |
|---|---|
| [19](../src/Handoff/LocalRouteService.php#L19) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly InstanceService $instances, private readonly CaseService $cases, private readonly SubjectRouteRegistry $routes)` |
| [30](../src/Handoff/LocalRouteService.php#L30) | `public function move(ExecutionContext $context, string $route, array $preview, string $reason, string $operation): array` |
| [53](../src/Handoff/LocalRouteService.php#L53) | `public function spawn(ExecutionContext $context, string $route, string $originKey, int $version, string $pipelineKey, int $revisionId, string $slot, string $sourceTransition, string $reason, string $operation): array` |

## `src/Handoff/SubjectRouteRegistry.php`

[سورس](../src/Handoff/SubjectRouteRegistry.php)

| خط | امضای فعلی |
|---|---|
| [12](../src/Handoff/SubjectRouteRegistry.php#L12) | `public function __construct(private array $local = [], private array $remote = [])` |
| [28](../src/Handoff/SubjectRouteRegistry.php#L28) | `public function local(string $key): LocalSubjectRouter` |
| [33](../src/Handoff/SubjectRouteRegistry.php#L33) | `public function remote(string $key): RemoteSubjectRouter` |

## `src/Integration/AutomationActions.php`

[سورس](../src/Integration/AutomationActions.php)

| خط | امضای فعلی |
|---|---|
| [28](../src/Integration/AutomationActions.php#L28) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly InstanceService $instances, private readonly AssignmentService $assignments, private readonly ApprovalService $approvals, private readonly HandoffService $handoffs, private readonly HierarchyService $hierarchy, private readonly ProjectService $projects, private readonly WorkflowService $workflows, private readonly ExportService $exports, private readonly SlaService $sla)` |
| [32](../src/Integration/AutomationActions.php#L32) | `public function validate(array $action): void` |
| [85](../src/Integration/AutomationActions.php#L85) | `public function execute(ExecutionContext $context, array $event, array $source, array $action, string $operation): array` |

## `src/Integration/AutomationService.php`

[سورس](../src/Integration/AutomationService.php)

| خط | امضای فعلی |
|---|---|
| [20](../src/Integration/AutomationService.php#L20) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly EventAccess $access, private readonly AutomationActions $actions)` |
| [30](../src/Integration/AutomationService.php#L30) | `public function get(ExecutionContext $context, string $key): array` |
| [43](../src/Integration/AutomationService.php#L43) | `public function publish(ExecutionContext $context, ?string $key, int $version, string $trigger, array $definition, string $operation): array` |
| [87](../src/Integration/AutomationService.php#L87) | `public function toggle(ExecutionContext $context, string $key, int $version, bool $active, string $operation): array` |
| [104](../src/Integration/AutomationService.php#L104) | `public function run(ExecutionContext $context, string $eventId, int $limit = 100): array` |

## `src/Integration/ConsumerRegistry.php`

[سورس](../src/Integration/ConsumerRegistry.php)

| خط | امضای فعلی |
|---|---|
| [13](../src/Integration/ConsumerRegistry.php#L13) | `public function register(string $key, callable $consumer): void` |
| [21](../src/Integration/ConsumerRegistry.php#L21) | `public function require(string $key): void` |
| [28](../src/Integration/ConsumerRegistry.php#L28) | `public function deliver(string $key, ExecutionContext $context, array $event): void` |

## `src/Integration/CurlWebhookTransport.php`

[سورس](../src/Integration/CurlWebhookTransport.php)

| خط | امضای فعلی |
|---|---|
| [10](../src/Integration/CurlWebhookTransport.php#L10) | `public function post(WebhookTarget $target, string $body, array $headers): array` |

## `src/Integration/DeferredDelivery.php`

[سورس](../src/Integration/DeferredDelivery.php)

| خط | امضای فعلی |
|---|---|
| [7](../src/Integration/DeferredDelivery.php#L7) | `public function __construct(public readonly \DateTimeImmutable $until)` |

## `src/Integration/EventAccess.php`

[سورس](../src/Integration/EventAccess.php)

| خط | امضای فعلی |
|---|---|
| [16](../src/Integration/EventAccess.php#L16) | `public function __construct(private readonly Connection $db, private readonly TaskService $tasks, private readonly InstanceService $instances, private readonly GroupService $groups, private readonly HandoffService $handoffs, private readonly ?ReminderService $reminders = null)` |
| [20](../src/Integration/EventAccess.php#L20) | `public function source(ExecutionContext $context, array $event): array` |
| [66](../src/Integration/EventAccess.php#L66) | `public function envelope(array $event): array` |

## `src/Integration/EventPump.php`

[سورس](../src/Integration/EventPump.php)

| خط | امضای فعلی |
|---|---|
| [17](../src/Integration/EventPump.php#L17) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly EventAccess $access, private readonly ConsumerRegistry $consumers)` |
| [27](../src/Integration/EventPump.php#L27) | `public function subscribe(ExecutionContext $context, string $eventType, string $consumer, string $operation): array` |
| [56](../src/Integration/EventPump.php#L56) | `public function toggle(ExecutionContext $context, string $key, int $version, bool $active, string $operation): array` |
| [71](../src/Integration/EventPump.php#L71) | `public function enqueue(ExecutionContext $context, int $limit = 100, ?int $replayAfter = null): array` |
| [119](../src/Integration/EventPump.php#L119) | `public function deliveries(ExecutionContext $context, int $afterId = 0, int $limit = 100): array` |
| [129](../src/Integration/EventPump.php#L129) | `public function run(ExecutionContext $context, int $limit = 100): array` |

## `src/Integration/NativeDnsResolver.php`

[سورس](../src/Integration/NativeDnsResolver.php)

| خط | امضای فعلی |
|---|---|
| [10](../src/Integration/NativeDnsResolver.php#L10) | `public function addresses(string $host): array` |

## `src/Integration/NotificationConsumer.php`

[سورس](../src/Integration/NotificationConsumer.php)

| خط | امضای فعلی |
|---|---|
| [13](../src/Integration/NotificationConsumer.php#L13) | `public function __construct(private readonly Connection $db, private readonly NotificationPreferences $preferences, private readonly NotificationTransport $transport, private readonly Clock $clock)` |
| [17](../src/Integration/NotificationConsumer.php#L17) | `public function deliver(ExecutionContext $context, array $event): void` |

## `src/Integration/UnavailableNotificationTransport.php`

[سورس](../src/Integration/UnavailableNotificationTransport.php)

| خط | امضای فعلی |
|---|---|
| [11](../src/Integration/UnavailableNotificationTransport.php#L11) | `public function deliver(ExecutionContext $context, string $eventId, string $recipient, string $channel, array $safeEnvelope): void` |

## `src/Integration/UnavailableSecretVault.php`

[سورس](../src/Integration/UnavailableSecretVault.php)

| خط | امضای فعلی |
|---|---|
| [11](../src/Integration/UnavailableSecretVault.php#L11) | `public function resolve(ExecutionContext $context, string $reference): string` |

## `src/Integration/WebhookService.php`

[سورس](../src/Integration/WebhookService.php)

| خط | امضای فعلی |
|---|---|
| [19](../src/Integration/WebhookService.php#L19) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly EventAccess $access, private readonly WebhookUrlPolicy $urls, private readonly SecretVault $vault, private readonly WebhookTransport $transport)` |
| [54](../src/Integration/WebhookService.php#L54) | `public function get(ExecutionContext $context, string $key): array` |
| [67](../src/Integration/WebhookService.php#L67) | `public function create(ExecutionContext $context, string $url, string $secretRef, array $types, string $operation): array` |
| [83](../src/Integration/WebhookService.php#L83) | `public function change(ExecutionContext $context, string $key, int $version, string $url, string $secretRef, array $types, bool $active, string $operation): array` |
| [99](../src/Integration/WebhookService.php#L99) | `public function queue(ExecutionContext $context, string $eventId): int` |
| [121](../src/Integration/WebhookService.php#L121) | `public function deliveries(ExecutionContext $context, int $afterId = 0, int $limit = 100): array` |
| [136](../src/Integration/WebhookService.php#L136) | `public function run(ExecutionContext $context, int $limit = 100): array` |
| [202](../src/Integration/WebhookService.php#L202) | `public function retry(ExecutionContext $context, int $deliveryId, int $version, string $operation): array` |
| [228](../src/Integration/WebhookService.php#L228) | `public function verifyIncoming(ExecutionContext $context, string $key, string $timestamp, string $eventId, string $body, string $signature): bool` |

## `src/Integration/WebhookSignature.php`

[سورس](../src/Integration/WebhookSignature.php)

| خط | امضای فعلی |
|---|---|
| [10](../src/Integration/WebhookSignature.php#L10) | `public static function sign(string $secret, string $timestamp, string $eventId, string $body): string` |
| [15](../src/Integration/WebhookSignature.php#L15) | `public static function verify(string $secret, string $timestamp, string $eventId, string $body, string $signature, \DateTimeImmutable $now): void` |

## `src/Integration/WebhookTarget.php`

[سورس](../src/Integration/WebhookTarget.php)

| خط | امضای فعلی |
|---|---|
| [7](../src/Integration/WebhookTarget.php#L7) | `public function __construct(public string $url, public string $host, public string $address, public int $port = 443)` |

## `src/Integration/WebhookUrlPolicy.php`

[سورس](../src/Integration/WebhookUrlPolicy.php)

| خط | امضای فعلی |
|---|---|
| [10](../src/Integration/WebhookUrlPolicy.php#L10) | `public function __construct(private readonly DnsResolver $dns, private readonly array $allowedHosts = [])` |
| [26](../src/Integration/WebhookUrlPolicy.php#L26) | `public static function publicAddress(string $address): bool` |
| [51](../src/Integration/WebhookUrlPolicy.php#L51) | `public function validate(string $url): WebhookTarget` |

## `src/OutboxDispatcher.php`

[سورس](../src/OutboxDispatcher.php)

| خط | امضای فعلی |
|---|---|
| [12](../src/OutboxDispatcher.php#L12) | `public function __construct(private readonly Connection $db, private readonly Clock $clock = new SystemClock)` |
| [16](../src/OutboxDispatcher.php#L16) | `public function dispatch(string $scope, callable $consumer, int $limit = 100): int` |

## `src/Planning/BulkService.php`

[سورس](../src/Planning/BulkService.php)

| خط | امضای فعلی |
|---|---|
| [17](../src/Planning/BulkService.php#L17) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks)` |
| [21](../src/Planning/BulkService.php#L21) | `public function execute(ExecutionContext $context, array $items, string $mode, string $operation): array` |

## `src/Planning/MarkerService.php`

[سورس](../src/Planning/MarkerService.php)

| خط | امضای فعلی |
|---|---|
| [20](../src/Planning/MarkerService.php#L20) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly ProjectService $projects)` |
| [33](../src/Planning/MarkerService.php#L33) | `public function create(ExecutionContext $context, string $projectKey, string $kind, string $name, ?\DateTimeImmutable $due, string $operation): array` |
| [55](../src/Planning/MarkerService.php#L55) | `public function get(ExecutionContext $context, string $key, bool $write = false): array` |
| [72](../src/Planning/MarkerService.php#L72) | `public function link(ExecutionContext $context, string $key, int $version, string $taskKey, int $taskVersion, bool $enabled, string $operation): array` |
| [101](../src/Planning/MarkerService.php#L101) | `public function progress(ExecutionContext $context, string $key): array` |
| [111](../src/Planning/MarkerService.php#L111) | `public function change(ExecutionContext $context, string $key, int $version, string $name, ?\DateTimeImmutable $due, string $operation): array` |

## `src/Planning/PointValue.php`

[سورس](../src/Planning/PointValue.php)

| خط | امضای فعلی |
|---|---|
| [9](../src/Planning/PointValue.php#L9) | `public static function minor(?string $value): ?int` |
| [22](../src/Planning/PointValue.php#L22) | `public static function format(int $minor): string` |

## `src/Planning/PositionService.php`

[سورس](../src/Planning/PositionService.php)

| خط | امضای فعلی |
|---|---|
| [23](../src/Planning/PositionService.php#L23) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly ProjectService $projects, private readonly SprintService $sprints)` |
| [33](../src/Planning/PositionService.php#L33) | `public function create(ExecutionContext $context, string $kind, string $reference, string $operation): array` |
| [51](../src/Planning/PositionService.php#L51) | `public function get(ExecutionContext $context, string $key, bool $write = false): array` |
| [65](../src/Planning/PositionService.php#L65) | `public function place(ExecutionContext $context, string $key, int $version, string $taskKey, int $taskVersion, ?string $after, ?string $before, string $operation): array` |
| [122](../src/Planning/PositionService.php#L122) | `public function listing(ExecutionContext $context, string $key, int $limit = 100, ?array $cursor = null): array` |

## `src/Planning/ReportingService.php`

[سورس](../src/Planning/ReportingService.php)

| خط | امضای فعلی |
|---|---|
| [15](../src/Planning/ReportingService.php#L15) | `public function __construct(private readonly Connection $db, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly ProjectService $projects)` |
| [25](../src/Planning/ReportingService.php#L25) | `public function totals(ExecutionContext $context, ?string $projectKey = null): array` |
| [44](../src/Planning/ReportingService.php#L44) | `public function subtreeTime(ExecutionContext $context, string $taskKey): array` |
| [53](../src/Planning/ReportingService.php#L53) | `public function flow(ExecutionContext $context, string $taskKey, ?\DateTimeImmutable $asOf = null): array` |

## `src/Planning/SprintService.php`

[سورس](../src/Planning/SprintService.php)

| خط | امضای فعلی |
|---|---|
| [21](../src/Planning/SprintService.php#L21) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly ProjectService $projects)` |
| [31](../src/Planning/SprintService.php#L31) | `public function get(ExecutionContext $context, string $key, bool $write = false, bool $lock = false): array` |
| [56](../src/Planning/SprintService.php#L56) | `public function create(ExecutionContext $context, string $projectKey, string $name, ?\DateTimeImmutable $start, ?\DateTimeImmutable $end, string $operation): array` |
| [77](../src/Planning/SprintService.php#L77) | `public function enroll(ExecutionContext $context, string $key, int $version, array $references, string $operation): array` |
| [116](../src/Planning/SprintService.php#L116) | `public function remove(ExecutionContext $context, string $key, int $version, string $taskKey, int $taskVersion, string $operation): array` |
| [147](../src/Planning/SprintService.php#L147) | `public function start(ExecutionContext $context, string $key, int $version, string $operation): array` |
| [170](../src/Planning/SprintService.php#L170) | `public function snapshot(ExecutionContext $context, string $key, int $version, string $operation): array` |
| [186](../src/Planning/SprintService.php#L186) | `public function close(ExecutionContext $context, string $key, int $version, string $policy, ?string $targetKey, ?int $targetVersion, string $operation): array` |
| [233](../src/Planning/SprintService.php#L233) | `public function report(ExecutionContext $context, string $key): array` |
| [311](../src/Planning/SprintService.php#L311) | `public function backlog(ExecutionContext $context, string $projectKey, int $afterId = 0, int $limit = 100): array` |

## `src/Planning/TemplateService.php`

[سورس](../src/Planning/TemplateService.php)

| خط | امضای فعلی |
|---|---|
| [23](../src/Planning/TemplateService.php#L23) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly ProjectService $projects, private readonly WorkflowService $workflows, private readonly HierarchyService $hierarchy, private readonly TaskPlanningService $planning)` |
| [35](../src/Planning/TemplateService.php#L35) | `public function get(ExecutionContext $context, string $key, ?int $revision = null): array` |
| [53](../src/Planning/TemplateService.php#L53) | `public function publish(ExecutionContext $context, ?string $key, int $version, string $name, array $definition, string $operation): array` |
| [138](../src/Planning/TemplateService.php#L138) | `public function instantiate(ExecutionContext $context, string $key, int $revision, string $projectCode, string $name, string $operation): array` |

## `src/Planning/TimeTrackingService.php`

[سورس](../src/Planning/TimeTrackingService.php)

| خط | امضای فعلی |
|---|---|
| [17](../src/Planning/TimeTrackingService.php#L17) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks)` |
| [47](../src/Planning/TimeTrackingService.php#L47) | `public function start(ExecutionContext $context, string $taskKey, string $operation): array` |
| [66](../src/Planning/TimeTrackingService.php#L66) | `public function timer(ExecutionContext $context, string $key): array` |
| [85](../src/Planning/TimeTrackingService.php#L85) | `public function cancelOwn(ExecutionContext $context, string $key, int $version, string $operation): array` |
| [111](../src/Planning/TimeTrackingService.php#L111) | `public function stop(ExecutionContext $context, string $key, int $version, string $operation, string $overlapPolicy = 'reject', ?string $actor = null): array` |
| [145](../src/Planning/TimeTrackingService.php#L145) | `public function log(ExecutionContext $context, string $taskKey, \DateTimeImmutable $start, \DateTimeImmutable $end, ?string $note, string $operation, string $overlapPolicy = 'reject', ?string $actor = null): array` |
| [162](../src/Planning/TimeTrackingService.php#L162) | `public function amend(ExecutionContext $context, string $key, int $version, \DateTimeImmutable $start, \DateTimeImmutable $end, ?string $note, bool $deleted, string $operation, string $overlapPolicy = 'reject'): array` |
| [188](../src/Planning/TimeTrackingService.php#L188) | `public function entry(ExecutionContext $context, string $key): array` |
| [207](../src/Planning/TimeTrackingService.php#L207) | `public function stats(ExecutionContext $context, string $taskKey, bool $truthFallback = true): array` |
| [223](../src/Planning/TimeTrackingService.php#L223) | `public function rebuild(ExecutionContext $context, string $taskKey, string $operation): array` |

## `src/Responsibility/AssignmentEngine.php`

[سورس](../src/Responsibility/AssignmentEngine.php)

| خط | امضای فعلی |
|---|---|
| [15](../src/Responsibility/AssignmentEngine.php#L15) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly ResponsibilityResolver $resolver)` |
| [19](../src/Responsibility/AssignmentEngine.php#L19) | `public function validate(array $policy): array` |
| [49](../src/Responsibility/AssignmentEngine.php#L49) | `public function policy(ExecutionContext $context, array $instance): array` |
| [66](../src/Responsibility/AssignmentEngine.php#L66) | `public function resolve(ExecutionContext $context, array $instance, array $policy): ?string` |
| [96](../src/Responsibility/AssignmentEngine.php#L96) | `public function assign(ExecutionContext $context, array $instance, array $policy, ?int $policyId, ?string $primary, array $secondary, string $reason, ?int $visitId = null, bool $validateEligibility = true): void` |
| [135](../src/Responsibility/AssignmentEngine.php#L135) | `public function activated(ExecutionContext $context, int $instanceId, string $operationId): void` |
| [148](../src/Responsibility/AssignmentEngine.php#L148) | `public function entered(ExecutionContext $context, int $instanceId, int $visitId, object $stage, string $operationId): void` |
| [174](../src/Responsibility/AssignmentEngine.php#L174) | `public function close(ExecutionContext $context, int $instanceId): void` |
| [180](../src/Responsibility/AssignmentEngine.php#L180) | `public function leaving(ExecutionContext $context, int $instanceId): void` |
| [185](../src/Responsibility/AssignmentEngine.php#L185) | `public function activity(ExecutionContext $context, int $instanceId): void` |
| [189](../src/Responsibility/AssignmentEngine.php#L189) | `public function beforeTransition(ExecutionContext $context, array $instance, array $edge, array $payload): void` |

## `src/Responsibility/AssignmentService.php`

[سورس](../src/Responsibility/AssignmentService.php)

| خط | امضای فعلی |
|---|---|
| [17](../src/Responsibility/AssignmentService.php#L17) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly AssignmentEngine $engine, private readonly InstanceService $instances, private readonly PipelineService $pipelines)` |
| [28](../src/Responsibility/AssignmentService.php#L28) | `public function get(ExecutionContext $context, string $key): array` |
| [38](../src/Responsibility/AssignmentService.php#L38) | `public function assign(ExecutionContext $context, string $key, int $version, string $operation, ?string $primary, array $secondary = []): array` |
| [56](../src/Responsibility/AssignmentService.php#L56) | `public function configureInstance(ExecutionContext $context, string $key, int $version, string $operation, array $policy): array` |
| [77](../src/Responsibility/AssignmentService.php#L77) | `public function configureStage(ExecutionContext $context, string $pipelineKey, string $stageKey, int $version, string $operation, array $policy): array` |
| [102](../src/Responsibility/AssignmentService.php#L102) | `public function depart(ExecutionContext $context, string $key, int $version, string $operation, string $actor, string $policy): array` |

## `src/Responsibility/ContextResponsibilityResolver.php`

[سورس](../src/Responsibility/ContextResponsibilityResolver.php)

| خط | امضای فعلی |
|---|---|
| [11](../src/Responsibility/ContextResponsibilityResolver.php#L11) | `public function candidates(ExecutionContext $context, string $reference, string $purpose): array` |
| [16](../src/Responsibility/ContextResponsibilityResolver.php#L16) | `public function eligible(ExecutionContext $context, string $actor, string $purpose): bool` |

## `src/Scheduling/DailyQuietHours.php`

[سورس](../src/Scheduling/DailyQuietHours.php)

| خط | امضای فعلی |
|---|---|
| [11](../src/Scheduling/DailyQuietHours.php#L11) | `public function __construct(private string $timezone = 'UTC', private ?string $start = null, private ?string $end = null, private array $channels = ['in_app'])` |
| [27](../src/Scheduling/DailyQuietHours.php#L27) | `public function nextAllowed(ExecutionContext $context, string $channel, \DateTimeImmutable $at): ?\DateTimeImmutable` |

## `src/Scheduling/DateService.php`

[سورس](../src/Scheduling/DateService.php)

| خط | امضای فعلی |
|---|---|
| [17](../src/Scheduling/DateService.php#L17) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks)` |
| [22](../src/Scheduling/DateService.php#L22) | `public function change(ExecutionContext $context, string $key, int $version, ?string $start, ?string $end, ?string $due, string $timezone, bool $allDay, string $policy, string $operation): array` |
| [62](../src/Scheduling/DateService.php#L62) | `public function calendar(ExecutionContext $context, \DateTimeImmutable $from, \DateTimeImmutable $until, int $afterId = 0, int $limit = 100): array` |

## `src/Scheduling/LocalTime.php`

[سورس](../src/Scheduling/LocalTime.php)

| خط | امضای فعلی |
|---|---|
| [9](../src/Scheduling/LocalTime.php#L9) | `public static function zone(string $name): \DateTimeZone` |
| [18](../src/Scheduling/LocalTime.php#L18) | `public static function date(string $value): void` |
| [26](../src/Scheduling/LocalTime.php#L26) | `public static function resolve(string $wall, string $timezone, string $policy = 'reject'): \DateTimeImmutable` |
| [56](../src/Scheduling/LocalTime.php#L56) | `public static function stamp(?\DateTimeImmutable $time): ?string` |

## `src/Scheduling/PersianCalendar.php`

[سورس](../src/Scheduling/PersianCalendar.php)

| خط | امضای فعلی |
|---|---|
| [25](../src/Scheduling/PersianCalendar.php#L25) | `public static function parts(string $gregorian): array` |
| [34](../src/Scheduling/PersianCalendar.php#L34) | `public static function date(int $year, int $month, int $day): string` |
| [49](../src/Scheduling/PersianCalendar.php#L49) | `public static function days(int $year, int $month): int` |

## `src/Scheduling/RecurrenceRule.php`

[سورس](../src/Scheduling/RecurrenceRule.php)

| خط | امضای فعلی |
|---|---|
| [13](../src/Scheduling/RecurrenceRule.php#L13) | `public static function parse(array&#124;string $input): array` |
| [81](../src/Scheduling/RecurrenceRule.php#L81) | `public static function expand(string $system, string $anchor, string $timezone, array $rule, \DateTimeImmutable $from, \DateTimeImmutable $until, CalendarRegistry $calendars, int $limit = 100): array` |

## `src/Scheduling/RecurrenceService.php`

[سورس](../src/Scheduling/RecurrenceService.php)

| خط | امضای فعلی |
|---|---|
| [22](../src/Scheduling/RecurrenceService.php#L22) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly DateService $dates, private readonly ProjectService $projects, private readonly WorkflowService $workflows, private readonly CalendarRegistry $calendars)` |
| [33](../src/Scheduling/RecurrenceService.php#L33) | `public function get(ExecutionContext $context, string $key, bool $write = false): array` |
| [49](../src/Scheduling/RecurrenceService.php#L49) | `public function create(ExecutionContext $context, string $taskKey, int $taskVersion, string $system, string $anchor, string $timezone, array&#124;string $input, string $operation): array` |
| [85](../src/Scheduling/RecurrenceService.php#L85) | `public function preview(ExecutionContext $context, string $key, \DateTimeImmutable $from, \DateTimeImmutable $until, int $limit = 100): array` |
| [123](../src/Scheduling/RecurrenceService.php#L123) | `public function generate(ExecutionContext $context, string $key, int $version, \DateTimeImmutable $from, \DateTimeImmutable $until, string $operation, int $limit = 100): array` |
| [213](../src/Scheduling/RecurrenceService.php#L213) | `public function changeRule(ExecutionContext $context, string $key, int $version, array&#124;string $input, string $operation, bool $stop = false): array` |
| [246](../src/Scheduling/RecurrenceService.php#L246) | `public function exception(ExecutionContext $context, string $key, int $version, string $occurrenceKey, int $taskVersion, ?\DateTimeImmutable $override, bool $cancel, string $operation): array` |

## `src/Scheduling/ReminderInvalidator.php`

[سورس](../src/Scheduling/ReminderInvalidator.php)

| خط | امضای فعلی |
|---|---|
| [9](../src/Scheduling/ReminderInvalidator.php#L9) | `public static function cancelTask(Connection $db, int $taskId): void` |

## `src/Scheduling/ReminderSchedule.php`

[سورس](../src/Scheduling/ReminderSchedule.php)

| خط | امضای فعلی |
|---|---|
| [9](../src/Scheduling/ReminderSchedule.php#L9) | `public static function refresh(Connection $db, object $task, array $data): void` |

## `src/Scheduling/ReminderService.php`

[سورس](../src/Scheduling/ReminderService.php)

| خط | امضای فعلی |
|---|---|
| [23](../src/Scheduling/ReminderService.php#L23) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly NotificationPreferences $preferences, private readonly ?SubjectRegistry $subjects = null, private readonly ?ReminderRecipients $recipients = null)` |
| [33](../src/Scheduling/ReminderService.php#L33) | `public function parseTime(string $wall, string $timezone, string $calendar = 'gregorian', string $dstPolicy = 'reject'): \DateTimeImmutable` |
| [49](../src/Scheduling/ReminderService.php#L49) | `public function suggestedRecipient(ExecutionContext $context, ?string $type = null, ?string $id = null): string` |
| [82](../src/Scheduling/ReminderService.php#L82) | `public function createGeneral(ExecutionContext $context, string $body, \DateTimeImmutable $when, string $timezone, string $operation, ?string $subjectType = null, ?string $subjectId = null, ?string $recipient = null, bool $allowPast = false): array` |
| [113](../src/Scheduling/ReminderService.php#L113) | `public function updateGeneral(ExecutionContext $context, string $key, int $version, array $changes, string $operation): array` |
| [184](../src/Scheduling/ReminderService.php#L184) | `public function changeState(ExecutionContext $context, string $key, int $version, string $action, string $operation): array` |
| [205](../src/Scheduling/ReminderService.php#L205) | `public function query(ExecutionContext $context, int $limit = 30, int $afterId = 0): array` |
| [214](../src/Scheduling/ReminderService.php#L214) | `public function count(ExecutionContext $context): int` |
| [219](../src/Scheduling/ReminderService.php#L219) | `public function activity(ExecutionContext $context, string $key): array` |
| [258](../src/Scheduling/ReminderService.php#L258) | `public function get(ExecutionContext $context, string $key): array` |
| [322](../src/Scheduling/ReminderService.php#L322) | `public function create(ExecutionContext $context, string $taskKey, string $anchor, int $offsetSeconds, ?\DateTimeImmutable $absolute, string $channel, string $operation): array` |
| [356](../src/Scheduling/ReminderService.php#L356) | `public function snooze(ExecutionContext $context, string $key, int $version, \DateTimeImmutable $until, string $operation): array` |
| [379](../src/Scheduling/ReminderService.php#L379) | `public function dispatch(ExecutionContext $context, int $limit = 100): array` |

## `src/Scheduling/SelfReminderRecipients.php`

[سورس](../src/Scheduling/SelfReminderRecipients.php)

| خط | امضای فعلی |
|---|---|
| [11](../src/Scheduling/SelfReminderRecipients.php#L11) | `public function suggest(ExecutionContext $context, ?string $type, ?string $id): string` |
| [16](../src/Scheduling/SelfReminderRecipients.php#L16) | `public function authorize(ExecutionContext $context, string $recipient, ?string $type, ?string $id): void` |

## `src/Schema/IdentifierCollation.php`

[سورس](../src/Schema/IdentifierCollation.php)

| خط | امضای فعلی |
|---|---|
| [10](../src/Schema/IdentifierCollation.php#L10) | `public static function forConnection(Connection $connection): string` |

## `src/SLA/CalendarRegistry.php`

[سورس](../src/SLA/CalendarRegistry.php)

| خط | امضای فعلی |
|---|---|
| [11](../src/SLA/CalendarRegistry.php#L11) | `public function __construct(private array $calendars = [])` |
| [20](../src/SLA/CalendarRegistry.php#L20) | `public function get(string $key): WorkingCalendar` |

## `src/SLA/SlaEngine.php`

[سورس](../src/SLA/SlaEngine.php)

| خط | امضای فعلی |
|---|---|
| [13](../src/SLA/SlaEngine.php#L13) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly CalendarRegistry $calendars)` |
| [17](../src/SLA/SlaEngine.php#L17) | `public function now(): \DateTimeImmutable` |
| [22](../src/SLA/SlaEngine.php#L22) | `public function activated(ExecutionContext $context, int $instanceId, string $operationId): void` |
| [26](../src/SLA/SlaEngine.php#L26) | `public function date(string $value): \DateTimeImmutable` |
| [31](../src/SLA/SlaEngine.php#L31) | `public function format(\DateTimeImmutable $value): string` |
| [36](../src/SLA/SlaEngine.php#L36) | `public function policy(object $visit): array` |
| [46](../src/SLA/SlaEngine.php#L46) | `public function add(\DateTimeImmutable $start, int $seconds, array $policy): \DateTimeImmutable` |
| [51](../src/SLA/SlaEngine.php#L51) | `public function seconds(\DateTimeImmutable $start, \DateTimeImmutable $end, array $policy): int` |
| [60](../src/SLA/SlaEngine.php#L60) | `public function worked(object $visit, array $policy, ?\DateTimeImmutable $until = null, bool $sinceAnchor = false): int` |
| [76](../src/SLA/SlaEngine.php#L76) | `public function entered(ExecutionContext $context, int $instanceId, int $visitId, object $stage, string $operationId): void` |
| [94](../src/SLA/SlaEngine.php#L94) | `public function leaving(ExecutionContext $context, int $instanceId): void` |
| [117](../src/SLA/SlaEngine.php#L117) | `public function activity(ExecutionContext $context, int $instanceId): void` |
| [131](../src/SLA/SlaEngine.php#L131) | `public function beforeTransition(ExecutionContext $context, array $instance, array $edge, array $payload): void` |

## `src/SLA/SlaService.php`

[سورس](../src/SLA/SlaService.php)

| خط | امضای فعلی |
|---|---|
| [16](../src/SLA/SlaService.php#L16) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly SlaEngine $engine, private readonly InstanceService $instances)` |
| [27](../src/SLA/SlaService.php#L27) | `public function get(ExecutionContext $context, int $visitId): array` |
| [52](../src/SLA/SlaService.php#L52) | `public function pause(ExecutionContext $context, int $visitId, int $slaVersion, string $operation, string $reason): array` |
| [83](../src/SLA/SlaService.php#L83) | `public function resume(ExecutionContext $context, int $visitId, int $slaVersion, string $operation): array` |
| [119](../src/SLA/SlaService.php#L119) | `public function reset(ExecutionContext $context, int $visitId, int $slaVersion, string $operation): array` |
| [145](../src/SLA/SlaService.php#L145) | `public function markDeadCandidate(ExecutionContext $context, string $instanceKey, int $visitId): array` |
| [156](../src/SLA/SlaService.php#L156) | `public function run(ExecutionContext $context, int $limit = 100): array` |

## `src/SLA/StagePolicyService.php`

[سورس](../src/SLA/StagePolicyService.php)

| خط | امضای فعلی |
|---|---|
| [17](../src/SLA/StagePolicyService.php#L17) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly CalendarRegistry $calendars, private readonly PipelineService $pipelines)` |
| [22](../src/SLA/StagePolicyService.php#L22) | `public function publishSla(ExecutionContext $context, string $pipelineKey, string $stageKey, int $pipelineVersion, string $operation, array $policy): array` |

## `src/SLA/WeeklyWorkingCalendar.php`

[سورس](../src/SLA/WeeklyWorkingCalendar.php)

| خط | امضای فعلی |
|---|---|
| [13](../src/SLA/WeeklyWorkingCalendar.php#L13) | `public function __construct(string $timezone, private array $shifts, private array $holidays = [])` |
| [89](../src/SLA/WeeklyWorkingCalendar.php#L89) | `public function addSeconds(\DateTimeImmutable $start, int $seconds): \DateTimeImmutable` |
| [114](../src/SLA/WeeklyWorkingCalendar.php#L114) | `public function secondsBetween(\DateTimeImmutable $start, \DateTimeImmutable $end): int` |

## `src/StageLifecycleChain.php`

[سورس](../src/StageLifecycleChain.php)

| خط | امضای فعلی |
|---|---|
| [10](../src/StageLifecycleChain.php#L10) | `public function __construct(private array $hooks)` |
| [14](../src/StageLifecycleChain.php#L14) | `public function activated(ExecutionContext $context, int $instanceId, string $operationId): void` |
| [21](../src/StageLifecycleChain.php#L21) | `public function entered(ExecutionContext $context, int $instanceId, int $visitId, object $stage, string $operationId): void` |
| [28](../src/StageLifecycleChain.php#L28) | `public function leaving(ExecutionContext $context, int $instanceId): void` |
| [35](../src/StageLifecycleChain.php#L35) | `public function activity(ExecutionContext $context, int $instanceId): void` |
| [42](../src/StageLifecycleChain.php#L42) | `public function beforeTransition(ExecutionContext $context, array $instance, array $edge, array $payload): void` |

## `src/SubjectRegistry.php`

[سورس](../src/SubjectRegistry.php)

| خط | امضای فعلی |
|---|---|
| [11](../src/SubjectRegistry.php#L11) | `public function __construct(private array $handlers = [])` |
| [20](../src/SubjectRegistry.php#L20) | `public function handler(string $alias): SubjectHandler` |
| [25](../src/SubjectRegistry.php#L25) | `public function all(): array` |

## `src/SystemClock.php`

[سورس](../src/SystemClock.php)

| خط | امضای فعلی |
|---|---|
| [9](../src/SystemClock.php#L9) | `public function now(): \DateTimeImmutable` |

## `src/TaskGraph/CalendarLag.php`

[سورس](../src/TaskGraph/CalendarLag.php)

| خط | امضای فعلی |
|---|---|
| [10](../src/TaskGraph/CalendarLag.php#L10) | `public static function shift(\DateTimeImmutable $anchor, int $minutes, string $clock, ?string $reference, CalendarRegistry $registry): \DateTimeImmutable` |

## `src/TaskGraph/DependencyService.php`

[سورس](../src/TaskGraph/DependencyService.php)

| خط | امضای فعلی |
|---|---|
| [19](../src/TaskGraph/DependencyService.php#L19) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly CalendarRegistry $calendars)` |
| [23](../src/TaskGraph/DependencyService.php#L23) | `public function add(ExecutionContext $context, string $predecessor, int $predecessorVersion, string $successor, int $successorVersion, string $type, array $policy, string $operation): array` |
| [28](../src/TaskGraph/DependencyService.php#L28) | `public function addBatch(ExecutionContext $context, array $items, string $operation): array` |
| [109](../src/TaskGraph/DependencyService.php#L109) | `public function get(ExecutionContext $context, string $key, string $capability = 'view'): array` |
| [122](../src/TaskGraph/DependencyService.php#L122) | `public function links(ExecutionContext $context, string $taskKey, int $limit = 100, int $afterId = 0): array` |
| [135](../src/TaskGraph/DependencyService.php#L135) | `public function remove(ExecutionContext $context, string $key, int $version, string $operation): array` |

## `src/TaskGraph/HierarchyIndex.php`

[سورس](../src/TaskGraph/HierarchyIndex.php)

| خط | امضای فعلی |
|---|---|
| [12](../src/TaskGraph/HierarchyIndex.php#L12) | `public static function root(Connection $db, ExecutionContext $context, int $id): void` |
| [17](../src/TaskGraph/HierarchyIndex.php#L17) | `public static function move(Connection $db, ExecutionContext $context, int $id, ?int $parentId, int $maxDepth): void` |

## `src/TaskGraph/HierarchyService.php`

[سورس](../src/TaskGraph/HierarchyService.php)

| خط | امضای فعلی |
|---|---|
| [19](../src/TaskGraph/HierarchyService.php#L19) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly TaskService $tasks, private readonly ProjectService $projects, private readonly AuthorizationProvider $authorization)` |
| [23](../src/TaskGraph/HierarchyService.php#L23) | `public function reparent(ExecutionContext $context, string $key, int $version, ?string $parentKey, ?int $parentVersion, string $operation): array` |
| [47](../src/TaskGraph/HierarchyService.php#L47) | `public function configureDepth(ExecutionContext $context, string $projectKey, int $version, int $depth, string $operation): array` |
| [71](../src/TaskGraph/HierarchyService.php#L71) | `public function tree(ExecutionContext $context, string $key, int $limit = 100, int $afterId = 0): array` |
| [84](../src/TaskGraph/HierarchyService.php#L84) | `public function progress(ExecutionContext $context, string $key, string $mode = 'direct'): array` |
| [103](../src/TaskGraph/HierarchyService.php#L103) | `public function previewDelete(ExecutionContext $context, string $key, string $policy = 'deny_if_children'): array` |
| [121](../src/TaskGraph/HierarchyService.php#L121) | `public function executeDelete(ExecutionContext $context, array $preview, string $operation): array` |

## `src/TaskGraph/NativeTaskRules.php`

[سورس](../src/TaskGraph/NativeTaskRules.php)

| خط | امضای فعلی |
|---|---|
| [16](../src/TaskGraph/NativeTaskRules.php#L16) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly CalendarRegistry $calendars)` |
| [20](../src/TaskGraph/NativeTaskRules.php#L20) | `public function activated(ExecutionContext $context, int $instanceId, string $operationId): void` |
| [24](../src/TaskGraph/NativeTaskRules.php#L24) | `public function entered(ExecutionContext $context, int $instanceId, int $visitId, object $stage, string $operationId): void` |
| [28](../src/TaskGraph/NativeTaskRules.php#L28) | `public function leaving(ExecutionContext $context, int $instanceId): void` |
| [32](../src/TaskGraph/NativeTaskRules.php#L32) | `public function activity(ExecutionContext $context, int $instanceId): void` |
| [36](../src/TaskGraph/NativeTaskRules.php#L36) | `public function beforeTransition(ExecutionContext $context, array $instance, array $edge, array $payload): void` |

## `src/TaskGraph/PreviewValue.php`

[سورس](../src/TaskGraph/PreviewValue.php)

| خط | امضای فعلی |
|---|---|
| [7](../src/TaskGraph/PreviewValue.php#L7) | `public static function ordered(array $value): array` |

## `src/TaskGraph/ScopeGraphLock.php`

[سورس](../src/TaskGraph/ScopeGraphLock.php)

| خط | امضای فعلی |
|---|---|
| [10](../src/TaskGraph/ScopeGraphLock.php#L10) | `public static function acquire(Connection $db, ExecutionContext $context): int` |
| [20](../src/TaskGraph/ScopeGraphLock.php#L20) | `public static function changed(Connection $db, ExecutionContext $context): void` |

## `src/TaskGraph/TaskActualClock.php`

[سورس](../src/TaskGraph/TaskActualClock.php)

| خط | امضای فعلی |
|---|---|
| [9](../src/TaskGraph/TaskActualClock.php#L9) | `public static function current(Connection $db, string $scope, int $taskId): array` |

## `src/TaskGraph/TaskPlanningService.php`

[سورس](../src/TaskGraph/TaskPlanningService.php)

| خط | امضای فعلی |
|---|---|
| [20](../src/TaskGraph/TaskPlanningService.php#L20) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly ProjectService $projects, private readonly CalendarRegistry $calendars)` |
| [31](../src/TaskGraph/TaskPlanningService.php#L31) | `public function estimate(ExecutionContext $context, string $key, int $version, ?int $minutes, ?string $points, string $operation): array` |
| [55](../src/TaskGraph/TaskPlanningService.php#L55) | `public function previewSchedule(ExecutionContext $context, string $key, ?\DateTimeImmutable $start, ?\DateTimeImmutable $finish): array` |
| [151](../src/TaskGraph/TaskPlanningService.php#L151) | `public function executeSchedule(ExecutionContext $context, array $preview, string $operation): array` |
| [200](../src/TaskGraph/TaskPlanningService.php#L200) | `public function criticalPath(ExecutionContext $context, string $projectKey, ?\DateTimeImmutable $anchor = null): array` |

## `src/TaskManager.php`

[سورس](../src/TaskManager.php)

| خط | امضای فعلی |
|---|---|
| [49](../src/TaskManager.php#L49) | `public function __construct(private readonly TaskService $tasks, private readonly WorkflowService $workflows, private readonly PipelineService $pipelines, private readonly InstanceService $instances, private readonly ProjectService $projects, private readonly CaseService $cases, private readonly SlaService $sla, private readonly StagePolicyService $stagePolicies, private readonly AssignmentService $assignments, private readonly ApprovalService $approvals, private readonly HandoffService $handoffs, private readonly LocalRouteService $routes, private readonly ExternalSagaService $sagas, private readonly GroupService $groups, private readonly MilestoneService $milestones, private readonly HierarchyService $hierarchy, private readonly DependencyService $dependencies, private readonly TaskPlanningService $planning, private readonly CollaborationService $collaboration, private readonly ChecklistService $checklists, private readonly AttachmentService $attachments, private readonly TimeTrackingService $time, private readonly SprintService $sprints, private readonly PositionService $positions, private readonly MarkerService $markers, private readonly ReportingService $reports, private readonly TemplateService $templates, private readonly BulkService $bulk, private readonly DateService $dates, private readonly RecurrenceService $recurrences, private readonly ReminderService $reminders, private readonly CustomFieldService $fields, private readonly QueryService $queries, private readonly PresetService $presets, private readonly AutomationService $automation, private readonly EventPump $events, private readonly WebhookService $webhooks, private readonly ExportService $exports, private readonly ReportService $enterpriseReports, private readonly IntegrationFactory $integrations, private readonly ?EntryRoutingService $entryRouting = null)` |
| [81](../src/TaskManager.php#L81) | `public function entryRouting(): EntryRoutingService` |
| [86](../src/TaskManager.php#L86) | `public function integrations(): IntegrationFactory` |
| [91](../src/TaskManager.php#L91) | `public function tasks(): TaskService` |
| [96](../src/TaskManager.php#L96) | `public function workflows(): WorkflowService` |
| [101](../src/TaskManager.php#L101) | `public function pipelines(): PipelineService` |
| [106](../src/TaskManager.php#L106) | `public function instances(): InstanceService` |
| [111](../src/TaskManager.php#L111) | `public function projects(): ProjectService` |
| [116](../src/TaskManager.php#L116) | `public function cases(): CaseService` |
| [121](../src/TaskManager.php#L121) | `public function sla(): SlaService` |
| [126](../src/TaskManager.php#L126) | `public function stagePolicies(): StagePolicyService` |
| [131](../src/TaskManager.php#L131) | `public function assignments(): AssignmentService` |
| [136](../src/TaskManager.php#L136) | `public function approvals(): ApprovalService` |
| [141](../src/TaskManager.php#L141) | `public function handoffs(): HandoffService` |
| [146](../src/TaskManager.php#L146) | `public function routes(): LocalRouteService` |
| [151](../src/TaskManager.php#L151) | `public function sagas(): ExternalSagaService` |
| [156](../src/TaskManager.php#L156) | `public function groups(): GroupService` |
| [161](../src/TaskManager.php#L161) | `public function milestones(): MilestoneService` |
| [166](../src/TaskManager.php#L166) | `public function hierarchy(): HierarchyService` |
| [171](../src/TaskManager.php#L171) | `public function dependencies(): DependencyService` |
| [176](../src/TaskManager.php#L176) | `public function planning(): TaskPlanningService` |
| [181](../src/TaskManager.php#L181) | `public function collaboration(): CollaborationService` |
| [186](../src/TaskManager.php#L186) | `public function checklists(): ChecklistService` |
| [191](../src/TaskManager.php#L191) | `public function attachments(): AttachmentService` |
| [196](../src/TaskManager.php#L196) | `public function time(): TimeTrackingService` |
| [201](../src/TaskManager.php#L201) | `public function sprints(): SprintService` |
| [206](../src/TaskManager.php#L206) | `public function positions(): PositionService` |
| [211](../src/TaskManager.php#L211) | `public function markers(): MarkerService` |
| [216](../src/TaskManager.php#L216) | `public function reports(): ReportingService` |
| [221](../src/TaskManager.php#L221) | `public function templates(): TemplateService` |
| [226](../src/TaskManager.php#L226) | `public function bulk(): BulkService` |
| [231](../src/TaskManager.php#L231) | `public function dates(): DateService` |
| [236](../src/TaskManager.php#L236) | `public function recurrences(): RecurrenceService` |
| [241](../src/TaskManager.php#L241) | `public function reminders(): ReminderService` |
| [246](../src/TaskManager.php#L246) | `public function fields(): CustomFieldService` |
| [251](../src/TaskManager.php#L251) | `public function queries(): QueryService` |
| [256](../src/TaskManager.php#L256) | `public function presets(): PresetService` |
| [261](../src/TaskManager.php#L261) | `public function automation(): AutomationService` |
| [266](../src/TaskManager.php#L266) | `public function events(): EventPump` |
| [271](../src/TaskManager.php#L271) | `public function webhooks(): WebhookService` |
| [276](../src/TaskManager.php#L276) | `public function exports(): ExportService` |
| [281](../src/TaskManager.php#L281) | `public function enterpriseReports(): ReportService` |
| [286](../src/TaskManager.php#L286) | `public function __call(string $method, array $arguments): mixed` |

## `src/TaskManagerServiceProvider.php`

[سورس](../src/TaskManagerServiceProvider.php)

| خط | امضای فعلی |
|---|---|
| [89](../src/TaskManagerServiceProvider.php#L89) | `public function register(): void` |
| [170](../src/TaskManagerServiceProvider.php#L170) | `public function boot(): void` |

## `src/TaskResourceService.php`

[سورس](../src/TaskResourceService.php)

| خط | امضای فعلی |
|---|---|
| [15](../src/TaskResourceService.php#L15) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly TaskService $tasks, private readonly ?AuthorizationProvider $authorization = null)` |
| [48](../src/TaskResourceService.php#L48) | `public function create(ExecutionContext $context, string $taskKey, string $kind, array $data, string $operation): array` |
| [69](../src/TaskResourceService.php#L69) | `public function get(ExecutionContext $context, string $kind, string $key): array` |
| [88](../src/TaskResourceService.php#L88) | `public function change(ExecutionContext $context, string $kind, string $key, int $version, string $operation, ?array $data): array` |

## `src/TaskService.php`

[سورس](../src/TaskService.php)

| خط | امضای فعلی |
|---|---|
| [29](../src/TaskService.php#L29) | `public function __construct(private readonly Connection $db, private readonly Clock $clock = new SystemClock, private readonly AuthorizationProvider $authorization = new ContextAuthorization, private readonly ?StageLifecycle $lifecycle = null)` |
| [33](../src/TaskService.php#L33) | `public function createTodo(CreateTodo $command): TaskResult` |
| [38](../src/TaskService.php#L38) | `public function inContext(ExecutionContext $context): TaskSession` |
| [43](../src/TaskService.php#L43) | `public function change(MutateTask $command): TaskResult` |
| [49](../src/TaskService.php#L49) | `public function findTasks(TaskQuery $query): array` |
| [95](../src/TaskService.php#L95) | `public function authorizedQuery(ExecutionContext $context, string $capability = 'view'): Builder` |
| [104](../src/TaskService.php#L104) | `public function get(ExecutionContext $context, string $id): array` |
| [125](../src/TaskService.php#L125) | `public function resolveKey(ExecutionContext $context, string $key): array` |
| [148](../src/TaskService.php#L148) | `public function query(ExecutionContext $context, int $page = 1, int $limit = 30): array` |
| [161](../src/TaskService.php#L161) | `public function count(ExecutionContext $context): int` |
| [166](../src/TaskService.php#L166) | `public function facets(ExecutionContext $context): array` |
| [188](../src/TaskService.php#L188) | `public function lists(ExecutionContext $context): array` |
| [197](../src/TaskService.php#L197) | `public function createList(ExecutionContext $context, string $name, string $operation): array` |
| [213](../src/TaskService.php#L213) | `public function share(ExecutionContext $context, string $id, int $version, string $operation, string $actor, bool $edit = false, bool $revoke = false): array` |
| [242](../src/TaskService.php#L242) | `public function changeList(ExecutionContext $context, int $id, int $version, string $operation, array $values): array` |
| [277](../src/TaskService.php#L277) | `public function cloneTask(ExecutionContext $context, string $id, string $operation): array` |
| [289](../src/TaskService.php#L289) | `public function activity(ExecutionContext $context, string $id): array` |
| [335](../src/TaskService.php#L335) | `public function create(ExecutionContext $context, string $title, string $operation): array` |
| [376](../src/TaskService.php#L376) | `public function mutate(ExecutionContext $context, string $id, int $version, string $operation, string $action, array $values = []): array` |

## `src/TaskSession.php`

[سورس](../src/TaskSession.php)

| خط | امضای فعلی |
|---|---|
| [11](../src/TaskSession.php#L11) | `public function __construct(private TaskService $service, private ExecutionContext $context)` |
| [15](../src/TaskSession.php#L15) | `public function create(string $title, string $operation): TaskResult` |
| [20](../src/TaskSession.php#L20) | `public function complete(string $id, int $version, string $operation): TaskResult` |
| [25](../src/TaskSession.php#L25) | `public function get(string $id): TaskResult` |

## `src/UI/BoundCursor.php`

[سورس](../src/UI/BoundCursor.php)

| خط | امضای فعلی |
|---|---|
| [9](../src/UI/BoundCursor.php#L9) | `public function __construct(private string $secret)` |
| [16](../src/UI/BoundCursor.php#L16) | `public function encode(array $binding, array $position): string` |
| [23](../src/UI/BoundCursor.php#L23) | `public function decode(?string $token, array $binding): array` |

## `src/UI/Calendar/DueEvents.php`

[سورس](../src/UI/Calendar/DueEvents.php)

اعلان صریح متد public ندارد؛ نوع/قرارداد آن در سورس بررسی شود.

## `src/UI/Calendar/MilestoneEvents.php`

[سورس](../src/UI/Calendar/MilestoneEvents.php)

اعلان صریح متد public ندارد؛ نوع/قرارداد آن در سورس بررسی شود.

## `src/UI/Calendar/RecurringEvents.php`

[سورس](../src/UI/Calendar/RecurringEvents.php)

اعلان صریح متد public ندارد؛ نوع/قرارداد آن در سورس بررسی شود.

## `src/UI/Calendar/ScheduleEvents.php`

[سورس](../src/UI/Calendar/ScheduleEvents.php)

اعلان صریح متد public ندارد؛ نوع/قرارداد آن در سورس بررسی شود.

## `src/UI/Calendar/SlaEvents.php`

[سورس](../src/UI/Calendar/SlaEvents.php)

اعلان صریح متد public ندارد؛ نوع/قرارداد آن در سورس بررسی شود.

## `src/UI/Calendar/SourceEvents.php`

[سورس](../src/UI/Calendar/SourceEvents.php)

| خط | امضای فعلی |
|---|---|
| [17](../src/UI/Calendar/SourceEvents.php#L17) | `public function __construct(private readonly TaskCalendar $calendar)` |
| [21](../src/UI/Calendar/SourceEvents.php#L21) | `public function fetch(EventQuery $query, CalendarContext $context): EventPage` |
| [26](../src/UI/Calendar/SourceEvents.php#L26) | `public function find(EventReference $reference, CalendarContext $context): ?CalendarEventData` |

## `src/UI/Calendar/TaskCalendar.php`

[سورس](../src/UI/Calendar/TaskCalendar.php)

| خط | امضای فعلی |
|---|---|
| [38](../src/UI/Calendar/TaskCalendar.php#L38) | `public function __construct(private readonly Connection $db, private readonly TaskService $tasks, private readonly InstanceService $instances, private readonly ProjectService $projects, private readonly DateService $dates, private readonly RecurrenceService $recurrences, private readonly Surface $surface, private readonly ContextBridge $bridge, private readonly BoundCursor $cursors)` |
| [184](../src/UI/Calendar/TaskCalendar.php#L184) | `public function fetch(EventQuery $query, CalendarContext $context): EventPage` |
| [263](../src/UI/Calendar/TaskCalendar.php#L263) | `public function find(EventReference $reference, CalendarContext $context): ?CalendarEventData` |
| [320](../src/UI/Calendar/TaskCalendar.php#L320) | `public function execute(ActionCommand $command, CalendarContext $context): ActionResult` |

## `src/UI/CardIdentity.php`

[سورس](../src/UI/CardIdentity.php)

| خط | امضای فعلی |
|---|---|
| [9](../src/UI/CardIdentity.php#L9) | `public static function make(string $source, string $key): string` |
| [14](../src/UI/CardIdentity.php#L14) | `public static function parse(string $id, Surface $surface): array` |

## `src/UI/ContextBridge.php`

[سورس](../src/UI/ContextBridge.php)

| خط | امضای فعلی |
|---|---|
| [11](../src/UI/ContextBridge.php#L11) | `public function __construct(private \Closure $resolver)` |
| [15](../src/UI/ContextBridge.php#L15) | `public function resolve(object $surface, Surface $definition): ExecutionContext` |

## `src/UI/Crm/CutoverService.php`

[سورس](../src/UI/Crm/CutoverService.php)

| خط | امضای فعلی |
|---|---|
| [27](../src/UI/Crm/CutoverService.php#L27) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly HostAccess $access, private readonly TaskService $tasks, private readonly InstanceService $instances, private readonly WorkflowService $workflows, private readonly ProjectService $projects, private readonly PipelineService $pipelines, private readonly DateService $dates)` |
| [41](../src/UI/Crm/CutoverService.php#L41) | `public function get(ExecutionContext $c, string $source, bool $lock = false): array` |
| [55](../src/UI/Crm/CutoverService.php#L55) | `public function configure(ExecutionContext $c, string $source, array $mapping, string $operation): array` |
| [116](../src/UI/Crm/CutoverService.php#L116) | `public function inventory(ExecutionContext $c, string $source): array` |
| [156](../src/UI/Crm/CutoverService.php#L156) | `public function importBatch(ExecutionContext $c, string $source, int $version, int $afterId, string $operation, int $limit = 100): array` |
| [341](../src/UI/Crm/CutoverService.php#L341) | `public function reconcile(ExecutionContext $c, string $source): array` |
| [372](../src/UI/Crm/CutoverService.php#L372) | `public function switch(ExecutionContext $c, string $source, int $version, string $mode, string $operation): array` |
| [401](../src/UI/Crm/CutoverService.php#L401) | `public function shadow(ExecutionContext $c, string $source, string $legacyId): array` |

## `src/UI/Crm/DealSubject.php`

[سورس](../src/UI/Crm/DealSubject.php)

| خط | امضای فعلی |
|---|---|
| [14](../src/UI/Crm/DealSubject.php#L14) | `public function __construct(private Connection $db, private HostAccess $access)` |
| [18](../src/UI/Crm/DealSubject.php#L18) | `public function idColumn(): string` |
| [23](../src/UI/Crm/DealSubject.php#L23) | `public function scopeColumn(): string` |
| [28](../src/UI/Crm/DealSubject.php#L28) | `public function query(ExecutionContext $context, string $capability): Builder` |
| [45](../src/UI/Crm/DealSubject.php#L45) | `public function guard(ExecutionContext $context, string $subjectId, array $instance, string $action, array $payload): void` |

## `src/UI/Crm/FollowupService.php`

[سورس](../src/UI/Crm/FollowupService.php)

| خط | امضای فعلی |
|---|---|
| [17](../src/UI/Crm/FollowupService.php#L17) | `public function __construct(private Connection $db, private TaskService $tasks, private SubjectRegistry $subjects, private Clock $clock)` |
| [34](../src/UI/Crm/FollowupService.php#L34) | `public function create(ExecutionContext $c, string $type, string $subjectId, string $title, string $operation): array` |
| [51](../src/UI/Crm/FollowupService.php#L51) | `public function tasks(ExecutionContext $c, string $type, string $subjectId, int $afterId = 0, int $limit = 50): array` |

## `src/UI/Crm/HostAccess.php`

[سورس](../src/UI/Crm/HostAccess.php)

| خط | امضای فعلی |
|---|---|
| [11](../src/UI/Crm/HostAccess.php#L11) | `public function constrain(Builder $query, ExecutionContext $context, string $type, string $capability): void` |
| [13](../src/UI/Crm/HostAccess.php#L13) | `public function scopeFor(object $model): string` |

## `src/UI/Crm/LeadSubject.php`

[سورس](../src/UI/Crm/LeadSubject.php)

| خط | امضای فعلی |
|---|---|
| [13](../src/UI/Crm/LeadSubject.php#L13) | `public function __construct(private Connection $db, private HostAccess $access)` |
| [17](../src/UI/Crm/LeadSubject.php#L17) | `public function idColumn(): string` |
| [22](../src/UI/Crm/LeadSubject.php#L22) | `public function scopeColumn(): string` |
| [27](../src/UI/Crm/LeadSubject.php#L27) | `public function query(ExecutionContext $c, string $capability): Builder` |
| [35](../src/UI/Crm/LeadSubject.php#L35) | `public function guard(ExecutionContext $c, string $id, array $instance, string $action, array $payload): void` |

## `src/UI/Crm/LegacyWriteGuard.php`

[سورس](../src/UI/Crm/LegacyWriteGuard.php)

| خط | امضای فعلی |
|---|---|
| [11](../src/UI/Crm/LegacyWriteGuard.php#L11) | `public function __construct(private HostAccess $access)` |
| [15](../src/UI/Crm/LegacyWriteGuard.php#L15) | `public function saving(object $model): void` |
| [20](../src/UI/Crm/LegacyWriteGuard.php#L20) | `public function deleting(object $model): void` |
| [25](../src/UI/Crm/LegacyWriteGuard.php#L25) | `public function restoring(object $model): void` |

## `src/UI/Crm/WriteOwnership.php`

[سورس](../src/UI/Crm/WriteOwnership.php)

| خط | امضای فعلی |
|---|---|
| [11](../src/UI/Crm/WriteOwnership.php#L11) | `public static function constrainTasks(Connection $db, Builder $query): void` |
| [19](../src/UI/Crm/WriteOwnership.php#L19) | `public static function assertHost(Connection $db, string $scope, string $type, string $id): void` |

## `src/UI/IntegrationFactory.php`

[سورس](../src/UI/IntegrationFactory.php)

| خط | امضای فعلی |
|---|---|
| [26](../src/UI/IntegrationFactory.php#L26) | `public function __construct(private Container $app)` |
| [30](../src/UI/IntegrationFactory.php#L30) | `public function board(Surface $surface, ContextBridge $bridge, BoundCursor $cursors, ?\Closure $handoff = null): Kanban\TaskBoard` |
| [39](../src/UI/IntegrationFactory.php#L39) | `public function calendar(Surface $surface, ContextBridge $bridge, BoundCursor $cursors): Calendar\TaskCalendar` |
| [48](../src/UI/IntegrationFactory.php#L48) | `public function crm(): Crm\CutoverService` |
| [57](../src/UI/IntegrationFactory.php#L57) | `public function guardLegacyCrm(): void` |
| [66](../src/UI/IntegrationFactory.php#L66) | `public function followups(): Crm\FollowupService` |

## `src/UI/Kanban/TaskBoard.php`

[سورس](../src/UI/Kanban/TaskBoard.php)

| خط | امضای فعلی |
|---|---|
| [42](../src/UI/Kanban/TaskBoard.php#L42) | `public function __construct(private readonly Connection $db, private readonly TaskService $tasks, private readonly InstanceService $instances, private readonly WorkflowService $workflows, private readonly PipelineService $pipelines, private readonly SubjectRegistry $subjects, private readonly AuthorizationProvider $authorization, private readonly Surface $surface, private readonly ContextBridge $bridge, private readonly BoundCursor $cursors, private readonly ?\Closure $handoff = null)` |
| [55](../src/UI/Kanban/TaskBoard.php#L55) | `public function board(BoardContext $context): BoardData` |
| [62](../src/UI/Kanban/TaskBoard.php#L62) | `public function capabilities(BoardContext $context): array` |
| [134](../src/UI/Kanban/TaskBoard.php#L134) | `public function stages(BoardContext $context, BoardQuery $query): array` |
| [213](../src/UI/Kanban/TaskBoard.php#L213) | `public function stageCards(BoardContext $context, string $stageId, BoardQuery $query): PageResult` |
| [218](../src/UI/Kanban/TaskBoard.php#L218) | `public function listCards(BoardContext $context, BoardQuery $query): PageResult` |
| [223](../src/UI/Kanban/TaskBoard.php#L223) | `public function archives(BoardContext $context, BoardQuery $query): PageResult` |
| [228](../src/UI/Kanban/TaskBoard.php#L228) | `public function counts(BoardContext $context, BoardQuery $query): CountResult` |
| [244](../src/UI/Kanban/TaskBoard.php#L244) | `public function card(BoardContext $context, string $cardId): ?CardData` |
| [263](../src/UI/Kanban/TaskBoard.php#L263) | `public function execute(ActionCommand $command, BoardContext $context): ActionResult` |
| [366](../src/UI/Kanban/TaskBoard.php#L366) | `public function operationStatus(BoardContext $context, string $operationId): ?ActionResult` |
| [384](../src/UI/Kanban/TaskBoard.php#L384) | `public function sections(BoardContext $context, CardData $card): array` |
| [405](../src/UI/Kanban/TaskBoard.php#L405) | `public function loadSection(BoardContext $context, CardData $card, string $key, ?string $cursor, int $limit): CardDetailSectionData` |
| [461](../src/UI/Kanban/TaskBoard.php#L461) | `public function performAction(BoardContext $context, CardData $card, CardDetailAction $action): ActionResult` |

## `src/UI/Surface.php`

[سورس](../src/UI/Surface.php)

| خط | امضای فعلی |
|---|---|
| [10](../src/UI/Surface.php#L10) | `public function __construct(public string $key, public array $sources, public array $pipelines = [], public array $handoffRoutes = [])` |
| [22](../src/UI/Surface.php#L22) | `public function allows(string $source): void` |

## `src/Workflow/CaseService.php`

[سورس](../src/Workflow/CaseService.php)

| خط | امضای فعلی |
|---|---|
| [16](../src/Workflow/CaseService.php#L16) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly InstanceService $instances)` |
| [20](../src/Workflow/CaseService.php#L20) | `public function create(ExecutionContext $context, string $title, string $operation): array` |
| [41](../src/Workflow/CaseService.php#L41) | `public function timeline(ExecutionContext $context, string $caseKey, int $limit = 100): array` |

## `src/Workflow/Definition.php`

[سورس](../src/Workflow/Definition.php)

| خط | امضای فعلی |
|---|---|
| [10](../src/Workflow/Definition.php#L10) | `public static function stateMachine(array $states, string $initial, array $commands = []): array` |
| [33](../src/Workflow/Definition.php#L33) | `public static function validate(array $definition): void` |
| [110](../src/Workflow/Definition.php#L110) | `public static function key(mixed $value): void` |
| [117](../src/Workflow/Definition.php#L117) | `public static function decode(string $json): array` |

## `src/Workflow/EntryRoutingService.php`

[سورس](../src/Workflow/EntryRoutingService.php)

| خط | امضای فعلی |
|---|---|
| [17](../src/Workflow/EntryRoutingService.php#L17) | `public function __construct(private Connection $db, private Clock $clock, private AuthorizationProvider $authorization, private PipelineService $pipelines, private InstanceService $instances, private EntryRoutingProvider $provider)` |
| [35](../src/Workflow/EntryRoutingService.php#L35) | `public function getDefault(ExecutionContext $context, string $routingKey): array` |
| [43](../src/Workflow/EntryRoutingService.php#L43) | `public function setDefault(ExecutionContext $context, string $routingKey, ?string $pipelineKey, int $version, string $operation): array` |
| [66](../src/Workflow/EntryRoutingService.php#L66) | `public function resolve(ExecutionContext $context, string $routingKey, bool $parentFallback = true): array` |
| [91](../src/Workflow/EntryRoutingService.php#L91) | `public function enter(ExecutionContext $context, string $type, string $subjectId, int $fallbackRevision, string $routingKey, string $operation, bool $parentFallback = true, string $slot = 'main'): array` |
| [105](../src/Workflow/EntryRoutingService.php#L105) | `public function retirePipeline(ExecutionContext $context, string $key, int $version, string $operation, ?string $replacementKey = null): array` |
| [132](../src/Workflow/EntryRoutingService.php#L132) | `public function detachRetired(ExecutionContext $context, string $key, int $limit = 100): array` |

## `src/Workflow/InstanceService.php`

[سورس](../src/Workflow/InstanceService.php)

| خط | امضای فعلی |
|---|---|
| [20](../src/Workflow/InstanceService.php#L20) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly SubjectRegistry $subjects, private readonly WorkflowService $workflows, private readonly PipelineService $pipelines, private readonly array $guards = [], private readonly ?StageLifecycle $lifecycle = null)` |
| [76](../src/Workflow/InstanceService.php#L76) | `public function get(ExecutionContext $context, string $key): array` |
| [89](../src/Workflow/InstanceService.php#L89) | `public function authorizedQuery(ExecutionContext $context): Builder` |
| [94](../src/Workflow/InstanceService.php#L94) | `public function query(ExecutionContext $context, int $limit = 30, ?string $afterKey = null): array` |
| [107](../src/Workflow/InstanceService.php#L107) | `public function count(ExecutionContext $context): int` |
| [112](../src/Workflow/InstanceService.php#L112) | `public function facets(ExecutionContext $context): array` |
| [117](../src/Workflow/InstanceService.php#L117) | `public function visits(ExecutionContext $context, string $key): array` |
| [124](../src/Workflow/InstanceService.php#L124) | `public function start(ExecutionContext $context, string $type, string $subjectId, int $revisionId, string $operation, string $slot = 'main', ?string $pipelineKey = null, ?string $stageKey = null, ?string $caseKey = null): array` |
| [192](../src/Workflow/InstanceService.php#L192) | `public function transition(ExecutionContext $context, string $key, int $version, string $operation, string $transition, array $payload = [], ?string $stageKey = null, bool $confirmed = true): array` |
| [197](../src/Workflow/InstanceService.php#L197) | `public function executeBusinessCommand(ExecutionContext $context, string $key, int $version, string $operation, string $command, array $payload = [], ?string $stageKey = null): array` |
| [205](../src/Workflow/InstanceService.php#L205) | `public function availableTransitions(ExecutionContext $context, string $key): array` |
| [373](../src/Workflow/InstanceService.php#L373) | `public function previewMove(ExecutionContext $context, string $key, string $pipelineKey, array $map, ?string $stageKey = null): array` |
| [389](../src/Workflow/InstanceService.php#L389) | `public function moveToPipeline(ExecutionContext $context, array $preview, string $operation): array` |
| [436](../src/Workflow/InstanceService.php#L436) | `public function changeBoardVisibility(ExecutionContext $context, string $key): array` |
| [442](../src/Workflow/InstanceService.php#L442) | `public function leavePipeline(ExecutionContext $context, string $key, int $version, string $operation): array` |
| [461](../src/Workflow/InstanceService.php#L461) | `public function reconcile(ExecutionContext $context, string $key): array` |
| [491](../src/Workflow/InstanceService.php#L491) | `public function lockForCommand(ExecutionContext $context, string $key, int $version, string $subjectCapability = 'edit'): array` |

## `src/Workflow/PipelineService.php`

[سورس](../src/Workflow/PipelineService.php)

| خط | امضای فعلی |
|---|---|
| [16](../src/Workflow/PipelineService.php#L16) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly WorkflowService $workflows)` |
| [20](../src/Workflow/PipelineService.php#L20) | `public function create(ExecutionContext $context, string $name, int $revisionId, array $stages, string $operation, string $transitionMode = 'custom'): array` |
| [53](../src/Workflow/PipelineService.php#L53) | `public function get(ExecutionContext $context, string $key): array` |

## `src/Workflow/ProjectService.php`

[سورس](../src/Workflow/ProjectService.php)

| خط | امضای فعلی |
|---|---|
| [23](../src/Workflow/ProjectService.php#L23) | `public function authorizedQuery(ExecutionContext $context): Builder` |
| [35](../src/Workflow/ProjectService.php#L35) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly WorkflowService $workflows, private readonly TaskService $tasks, private readonly StageLifecycle $lifecycle)` |
| [39](../src/Workflow/ProjectService.php#L39) | `public function get(ExecutionContext $context, string $key, string $capability = 'view', bool $lock = false): array` |
| [63](../src/Workflow/ProjectService.php#L63) | `public function create(ExecutionContext $context, string $key, string $name, int $revisionId, string $operation): array` |
| [82](../src/Workflow/ProjectService.php#L82) | `public function change(ExecutionContext $context, string $key, int $version, string $operation, string $action, array $data): array` |
| [122](../src/Workflow/ProjectService.php#L122) | `public function previewTaskMove(ExecutionContext $context, string $taskKey, string $projectKey, string $type, array $stateMap): array` |
| [140](../src/Workflow/ProjectService.php#L140) | `public function moveTask(ExecutionContext $context, array $preview, string $operation, ?string $parentKey = null): array` |
| [198](../src/Workflow/ProjectService.php#L198) | `public function previewSubtreeMove(ExecutionContext $context, string $rootKey, string $projectKey, array $stateMaps): array` |
| [241](../src/Workflow/ProjectService.php#L241) | `public function moveSubtree(ExecutionContext $context, array $preview, string $operation): array` |
| [321](../src/Workflow/ProjectService.php#L321) | `public function previewWorkflowMigration(ExecutionContext $context, string $projectKey, int $revisionId, array $stateMap, ?string $type = null): array` |
| [351](../src/Workflow/ProjectService.php#L351) | `public function migrateWorkflow(ExecutionContext $context, array $preview, string $operation): array` |

## `src/Workflow/StateIndex.php`

[سورس](../src/Workflow/StateIndex.php)

| خط | امضای فعلی |
|---|---|
| [10](../src/Workflow/StateIndex.php#L10) | `public static function taskCategoryQuery(Connection $db, string $scope): Builder` |
| [16](../src/Workflow/StateIndex.php#L16) | `public static function currentTaskIds(Connection $db, string $scope, array $categories): Builder` |
| [23](../src/Workflow/StateIndex.php#L23) | `public static function category(array $definition, string $key): string` |
| [34](../src/Workflow/StateIndex.php#L34) | `public static function publish(Connection $db, int $revisionId, array $definition): void` |
| [45](../src/Workflow/StateIndex.php#L45) | `public static function transition(Connection $db, int $fromRevision, string $from, int $toRevision, string $to): array` |

## `src/Workflow/TaskSubjectHandler.php`

[سورس](../src/Workflow/TaskSubjectHandler.php)

| خط | امضای فعلی |
|---|---|
| [13](../src/Workflow/TaskSubjectHandler.php#L13) | `public function __construct(private readonly TaskService $tasks)` |
| [17](../src/Workflow/TaskSubjectHandler.php#L17) | `public function query(ExecutionContext $context, string $capability): Builder` |
| [22](../src/Workflow/TaskSubjectHandler.php#L22) | `public function idColumn(): string` |
| [27](../src/Workflow/TaskSubjectHandler.php#L27) | `public function scopeColumn(): string` |
| [32](../src/Workflow/TaskSubjectHandler.php#L32) | `public function guard(ExecutionContext $context, string $subjectId, array $instance, string $action, array $payload): void` |

## `src/Workflow/UnavailableEntryRoutingProvider.php`

[سورس](../src/Workflow/UnavailableEntryRoutingProvider.php)

| خط | امضای فعلی |
|---|---|
| [11](../src/Workflow/UnavailableEntryRoutingProvider.php#L11) | `public function authorize(ExecutionContext $context, string $routingKey): void` |
| [16](../src/Workflow/UnavailableEntryRoutingProvider.php#L16) | `public function parent(ExecutionContext $context, string $routingKey): ?string` |

## `src/Workflow/WorkflowService.php`

[سورس](../src/Workflow/WorkflowService.php)

| خط | امضای فعلی |
|---|---|
| [15](../src/Workflow/WorkflowService.php#L15) | `public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization)` |
| [19](../src/Workflow/WorkflowService.php#L19) | `public function publish(ExecutionContext $context, string $key, int $expectedRevision, string $operation, array $definition): array` |
| [45](../src/Workflow/WorkflowService.php#L45) | `public function revision(ExecutionContext $context, int $id): array` |

