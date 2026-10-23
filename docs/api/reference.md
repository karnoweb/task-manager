# Public service reference

Generated from PHP reflection by `php tools/build_api_reference.php`. No service is instantiated. Public locking helpers require an existing transaction; they are not controller endpoints.

Facade: `Karnoweb\TaskManager\Facades\TaskManager`; injectable manager: `Karnoweb\TaskManager\TaskManager`.

## approvals()

`Karnoweb\TaskManager\Approval\ApprovalService`

```php
public function get(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function active(Karnoweb\TaskManager\Context\ExecutionContext $context, string $instanceKey): ?array;
public function begin(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $operation, array $policy): array;
public function configureStage(Karnoweb\TaskManager\Context\ExecutionContext $context, string $pipelineKey, string $stageKey, int $version, string $operation, array $policy): array;
public function vote(Karnoweb\TaskManager\Context\ExecutionContext $context, string $stepKey, int $stepVersion, int $instanceVersion, string $operation, string $vote, ?string $reason = NULL): array;
public function execute(Karnoweb\TaskManager\Context\ExecutionContext $context, string $stepKey, int $stepVersion, int $instanceVersion, string $operation): array;
public function cancel(Karnoweb\TaskManager\Context\ExecutionContext $context, string $stepKey, int $stepVersion, int $instanceVersion, string $operation, string $reason): array;
```

## assignments()

`Karnoweb\TaskManager\Responsibility\AssignmentService`

```php
public function get(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function assign(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $operation, ?string $primary, array $secondary = []): array;
public function configureInstance(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $operation, array $policy): array;
public function configureStage(Karnoweb\TaskManager\Context\ExecutionContext $context, string $pipelineKey, string $stageKey, int $version, string $operation, array $policy): array;
public function depart(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $operation, string $actor, string $policy): array;
```

## attachments()

`Karnoweb\TaskManager\Collaboration\AttachmentService`

```php
public function attach(Karnoweb\TaskManager\Context\ExecutionContext $context, string $taskKey, string $reference, string $operation): array;
public function issueTicket(Karnoweb\TaskManager\Context\ExecutionContext $context, string $taskKey, string $attachmentKey, int $lifetimeSeconds = 120): array;
public function download(Karnoweb\TaskManager\Context\ExecutionContext $context, string $taskKey, string $attachmentKey, string $token): mixed;
```

## automation()

`Karnoweb\TaskManager\Integration\AutomationService`

```php
public function get(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function publish(Karnoweb\TaskManager\Context\ExecutionContext $context, ?string $key, int $version, string $trigger, array $definition, string $operation): array;
public function toggle(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, bool $active, string $operation): array;
public function run(Karnoweb\TaskManager\Context\ExecutionContext $context, string $eventId, int $limit = 100): array;
```

## bulk()

`Karnoweb\TaskManager\Planning\BulkService`

```php
public function execute(Karnoweb\TaskManager\Context\ExecutionContext $context, array $items, string $mode, string $operation): array;
```

## cases()

`Karnoweb\TaskManager\Workflow\CaseService`

```php
public function create(Karnoweb\TaskManager\Context\ExecutionContext $context, string $title, string $operation): array;
public function timeline(Karnoweb\TaskManager\Context\ExecutionContext $context, string $caseKey, int $limit = 100): array;
```

## checklists()

`Karnoweb\TaskManager\Collaboration\ChecklistService`

```php
public function create(Karnoweb\TaskManager\Context\ExecutionContext $context, string $taskKey, string $title, string $operation): array;
public function get(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function change(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $title, bool $deleted, string $operation): array;
public function addItem(Karnoweb\TaskManager\Context\ExecutionContext $context, string $listKey, int $version, string $title, ?string $assignee, ?DateTimeImmutable $due, string $operation): array;
public function item(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function changeItem(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, array $changes, string $operation): array;
public function convert(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $operation): array;
public function items(Karnoweb\TaskManager\Context\ExecutionContext $context, string $listKey, ?string $assignee = NULL, ?DateTimeImmutable $dueBefore = NULL, int $afterId = 0, int $limit = 100): array;
public function progress(Karnoweb\TaskManager\Context\ExecutionContext $context, string $taskKey): array;
```

## collaboration()

`Karnoweb\TaskManager\Collaboration\CollaborationService`

```php
public function comment(Karnoweb\TaskManager\Context\ExecutionContext $context, string $taskKey, string $body, array $mentions, ?string $parentKey, string $operation): array;
public function editComment(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, ?string $body, string $operation): array;
public function history(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $afterVersion = 0, int $limit = 100): array;
public function watch(Karnoweb\TaskManager\Context\ExecutionContext $context, string $taskKey, int $version, string $actor, bool $enabled, string $operation): array;
public function notificationRecipients(Karnoweb\TaskManager\Context\ExecutionContext $context, string $taskKey, ?string $commentKey = NULL): array;
public function restore(Karnoweb\TaskManager\Context\ExecutionContext $context, string $kind, string $key, int $version, string $operation): array;
```

## dates()

`Karnoweb\TaskManager\Scheduling\DateService`

```php
public function change(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, ?string $start, ?string $end, ?string $due, string $timezone, bool $allDay, string $policy, string $operation): array;
public function calendar(Karnoweb\TaskManager\Context\ExecutionContext $context, DateTimeImmutable $from, DateTimeImmutable $until, int $afterId = 0, int $limit = 100): array;
```

## dependencies()

`Karnoweb\TaskManager\TaskGraph\DependencyService`

```php
public function add(Karnoweb\TaskManager\Context\ExecutionContext $context, string $predecessor, int $predecessorVersion, string $successor, int $successorVersion, string $type, array $policy, string $operation): array;
public function addBatch(Karnoweb\TaskManager\Context\ExecutionContext $context, array $items, string $operation): array;
public function get(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, string $capability = 'view'): array;
public function links(Karnoweb\TaskManager\Context\ExecutionContext $context, string $taskKey, int $limit = 100, int $afterId = 0): array;
public function remove(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $operation): array;
```

## enterpriseReports()

`Karnoweb\TaskManager\Enterprise\ReportService`

```php
public function read(Karnoweb\TaskManager\Context\ExecutionContext $context, array $input = [], bool $truthFallback = true): array;
public function rebuild(Karnoweb\TaskManager\Context\ExecutionContext $context, array $input, string $operation): array;
```

## entryRouting()

`Karnoweb\TaskManager\Workflow\EntryRoutingService`

```php
public function getDefault(Karnoweb\TaskManager\Context\ExecutionContext $context, string $routingKey): array;
public function setDefault(Karnoweb\TaskManager\Context\ExecutionContext $context, string $routingKey, ?string $pipelineKey, int $version, string $operation): array;
public function resolve(Karnoweb\TaskManager\Context\ExecutionContext $context, string $routingKey, bool $parentFallback = true): array;
public function enter(Karnoweb\TaskManager\Context\ExecutionContext $context, string $type, string $subjectId, int $fallbackRevision, string $routingKey, string $operation, bool $parentFallback = true, string $slot = 'main'): array;
public function retirePipeline(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $operation, ?string $replacementKey = NULL): array;
public function detachRetired(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $limit = 100): array;
```

## events()

`Karnoweb\TaskManager\Integration\EventPump`

```php
public function subscribe(Karnoweb\TaskManager\Context\ExecutionContext $context, string $eventType, string $consumer, string $operation): array;
public function toggle(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, bool $active, string $operation): array;
public function enqueue(Karnoweb\TaskManager\Context\ExecutionContext $context, int $limit = 100, ?int $replayAfter = NULL): array;
public function deliveries(Karnoweb\TaskManager\Context\ExecutionContext $context, int $afterId = 0, int $limit = 100): array;
public function run(Karnoweb\TaskManager\Context\ExecutionContext $context, int $limit = 100): array;
```

## exports()

`Karnoweb\TaskManager\Enterprise\ExportService`

```php
public function enqueue(Karnoweb\TaskManager\Context\ExecutionContext $context, array $input, string $operation, int $lifetimeSeconds = 3600): array;
public function get(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function stream(Karnoweb\TaskManager\Context\ExecutionContext $context, array $input): Generator;
public function build(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version): array;
public function download(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): mixed;
```

## fields()

`Karnoweb\TaskManager\Enterprise\CustomFieldService`

```php
public function create(Karnoweb\TaskManager\Context\ExecutionContext $context, string $name, string $type, array $options, ?string $readCapability, string $operation): array;
public function get(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function archive(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, bool $archived, string $operation): array;
public function forms(Karnoweb\TaskManager\Context\ExecutionContext $context, int $afterId = 0, int $limit = 100): array;
public function set(Karnoweb\TaskManager\Context\ExecutionContext $context, string $taskKey, int $taskVersion, string $fieldKey, int $fieldVersion, mixed $value, string $operation): array;
public function value(Karnoweb\TaskManager\Context\ExecutionContext $context, string $taskKey, string $fieldKey): array;
public function filter(Illuminate\Database\Query\Builder $query, Karnoweb\TaskManager\Context\ExecutionContext $context, string $fieldKey, string $operator, mixed $value): Illuminate\Database\Query\Builder;
public function sortQuery(Karnoweb\TaskManager\Context\ExecutionContext $context, string $fieldKey): array;
```

## groups()

`Karnoweb\TaskManager\Group\GroupService`

```php
public function get(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function register(Karnoweb\TaskManager\Context\ExecutionContext $context, string $sourceType, string $sourceId, array $policy, string $operation): array;
public function publishPolicy(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, array $policy, string $operation): array;
public function enroll(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, array $instances, string $operation): array;
public function previewClosure(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function previewDelta(Karnoweb\TaskManager\Context\ExecutionContext $context, array $previous): array;
public function executeClosure(Karnoweb\TaskManager\Context\ExecutionContext $context, array $preview, string $runKey): array;
public function runInfo(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function process(Karnoweb\TaskManager\Context\ExecutionContext $context, string $runKey, int $limit = 25): array;
public function report(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $limit = 100, int $afterId = 0): array;
```

## handoffs()

`Karnoweb\TaskManager\Handoff\HandoffService`

```php
public function get(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function delegate(Karnoweb\TaskManager\Context\ExecutionContext $context, string $originKey, int $version, string $pipelineKey, int $revisionId, string $slot, array $policy, string $operation): array;
public function parallel(Karnoweb\TaskManager\Context\ExecutionContext $context, string $originKey, int $version, array $destinations, array $policy, array $join, string $operation): array;
public function settle(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $operation): array;
public function recall(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $operation, bool $timeout = false): array;
public function getGroup(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function manualJoin(Karnoweb\TaskManager\Context\ExecutionContext $context, string $groupKey, int $version, string $operation): array;
public function retryReturn(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, int $originVersion, string $reason, string $operation): array;
public function closeConflict(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $operation): array;
public function run(Karnoweb\TaskManager\Context\ExecutionContext $context, int $limit = 100): array;
```

## hierarchy()

`Karnoweb\TaskManager\TaskGraph\HierarchyService`

```php
public function reparent(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, ?string $parentKey, ?int $parentVersion, string $operation): array;
public function configureDepth(Karnoweb\TaskManager\Context\ExecutionContext $context, string $projectKey, int $version, int $depth, string $operation): array;
public function tree(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $limit = 100, int $afterId = 0): array;
public function progress(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, string $mode = 'direct'): array;
public function previewDelete(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, string $policy = 'deny_if_children'): array;
public function executeDelete(Karnoweb\TaskManager\Context\ExecutionContext $context, array $preview, string $operation): array;
```

## instances()

`Karnoweb\TaskManager\Workflow\InstanceService`

```php
public function get(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function authorizedQuery(Karnoweb\TaskManager\Context\ExecutionContext $context): Illuminate\Database\Query\Builder;
public function query(Karnoweb\TaskManager\Context\ExecutionContext $context, int $limit = 30, ?string $afterKey = NULL): array;
public function count(Karnoweb\TaskManager\Context\ExecutionContext $context): int;
public function facets(Karnoweb\TaskManager\Context\ExecutionContext $context): array;
public function visits(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function start(Karnoweb\TaskManager\Context\ExecutionContext $context, string $type, string $subjectId, int $revisionId, string $operation, string $slot = 'main', ?string $pipelineKey = NULL, ?string $stageKey = NULL, ?string $caseKey = NULL): array;
public function transition(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $operation, string $transition, array $payload = [], ?string $stageKey = NULL, bool $confirmed = true): array;
public function executeBusinessCommand(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $operation, string $command, array $payload = [], ?string $stageKey = NULL): array;
public function availableTransitions(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function previewMove(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, string $pipelineKey, array $map, ?string $stageKey = NULL): array;
public function moveToPipeline(Karnoweb\TaskManager\Context\ExecutionContext $context, array $preview, string $operation): array;
public function changeBoardVisibility(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function leavePipeline(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $operation): array;
public function reconcile(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function lockForCommand(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $subjectCapability = 'edit'): array;
```

## integrations()

`Karnoweb\TaskManager\UI\IntegrationFactory`

```php
public function board(Karnoweb\TaskManager\UI\Surface $surface, Karnoweb\TaskManager\UI\ContextBridge $bridge, Karnoweb\TaskManager\UI\BoundCursor $cursors, ?Closure $handoff = NULL): Karnoweb\TaskManager\UI\Kanban\TaskBoard;
public function calendar(Karnoweb\TaskManager\UI\Surface $surface, Karnoweb\TaskManager\UI\ContextBridge $bridge, Karnoweb\TaskManager\UI\BoundCursor $cursors): Karnoweb\TaskManager\UI\Calendar\TaskCalendar;
public function crm(): Karnoweb\TaskManager\UI\Crm\CutoverService;
public function guardLegacyCrm(): void;
public function followups(): Karnoweb\TaskManager\UI\Crm\FollowupService;
```

## markers()

`Karnoweb\TaskManager\Planning\MarkerService`

```php
public function create(Karnoweb\TaskManager\Context\ExecutionContext $context, string $projectKey, string $kind, string $name, ?DateTimeImmutable $due, string $operation): array;
public function get(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, bool $write = false): array;
public function link(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $taskKey, int $taskVersion, bool $enabled, string $operation): array;
public function progress(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function change(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $name, ?DateTimeImmutable $due, string $operation): array;
```

## milestones()

`Karnoweb\TaskManager\Group\MilestoneService`

```php
public function get(Karnoweb\TaskManager\Context\ExecutionContext $context, string $intentKey): array;
public function verify(Karnoweb\TaskManager\Context\ExecutionContext $context, string $intentKey, int $instanceVersion, string $operation): array;
public function run(Karnoweb\TaskManager\Context\ExecutionContext $context, int $limit = 100): array;
```

## pipelines()

`Karnoweb\TaskManager\Workflow\PipelineService`

```php
public function create(Karnoweb\TaskManager\Context\ExecutionContext $context, string $name, int $revisionId, array $stages, string $operation, string $transitionMode = 'custom'): array;
public function get(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
```

## planning()

`Karnoweb\TaskManager\TaskGraph\TaskPlanningService`

```php
public function estimate(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, ?int $minutes, ?string $points, string $operation): array;
public function previewSchedule(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, ?DateTimeImmutable $start, ?DateTimeImmutable $finish): array;
public function executeSchedule(Karnoweb\TaskManager\Context\ExecutionContext $context, array $preview, string $operation): array;
public function criticalPath(Karnoweb\TaskManager\Context\ExecutionContext $context, string $projectKey, ?DateTimeImmutable $anchor = NULL): array;
```

## positions()

`Karnoweb\TaskManager\Planning\PositionService`

```php
public function create(Karnoweb\TaskManager\Context\ExecutionContext $context, string $kind, string $reference, string $operation): array;
public function get(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, bool $write = false): array;
public function place(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $taskKey, int $taskVersion, ?string $after, ?string $before, string $operation): array;
public function listing(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $limit = 100, ?array $cursor = NULL): array;
```

## presets()

`Karnoweb\TaskManager\Enterprise\PresetService`

```php
public function create(Karnoweb\TaskManager\Context\ExecutionContext $context, string $name, array $definition, string $operation): array;
public function get(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function instantiate(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, string $operation): array;
```

## projects()

`Karnoweb\TaskManager\Workflow\ProjectService`

```php
public function authorizedQuery(Karnoweb\TaskManager\Context\ExecutionContext $context): Illuminate\Database\Query\Builder;
public function get(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, string $capability = 'view', bool $lock = false): array;
public function create(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, string $name, int $revisionId, string $operation): array;
public function change(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $operation, string $action, array $data): array;
public function previewTaskMove(Karnoweb\TaskManager\Context\ExecutionContext $context, string $taskKey, string $projectKey, string $type, array $stateMap): array;
public function moveTask(Karnoweb\TaskManager\Context\ExecutionContext $context, array $preview, string $operation, ?string $parentKey = NULL): array;
public function previewSubtreeMove(Karnoweb\TaskManager\Context\ExecutionContext $context, string $rootKey, string $projectKey, array $stateMaps): array;
public function moveSubtree(Karnoweb\TaskManager\Context\ExecutionContext $context, array $preview, string $operation): array;
public function previewWorkflowMigration(Karnoweb\TaskManager\Context\ExecutionContext $context, string $projectKey, int $revisionId, array $stateMap, ?string $type = NULL): array;
public function migrateWorkflow(Karnoweb\TaskManager\Context\ExecutionContext $context, array $preview, string $operation): array;
```

## queries()

`Karnoweb\TaskManager\Enterprise\QueryService`

```php
public function base(Karnoweb\TaskManager\Context\ExecutionContext $context, array $filters): Illuminate\Database\Query\Builder;
public function tasks(Karnoweb\TaskManager\Context\ExecutionContext $context, array $filters = [], string $sort = 'id', string $direction = 'asc', int $limit = 30, ?string $cursor = NULL): array;
public function reindex(Karnoweb\TaskManager\Context\ExecutionContext $context, int $afterId = 0, int $limit = 1000): array;
```

## recurrences()

`Karnoweb\TaskManager\Scheduling\RecurrenceService`

```php
public function get(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, bool $write = false): array;
public function create(Karnoweb\TaskManager\Context\ExecutionContext $context, string $taskKey, int $taskVersion, string $system, string $anchor, string $timezone, array|string $input, string $operation): array;
public function preview(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, DateTimeImmutable $from, DateTimeImmutable $until, int $limit = 100): array;
public function generate(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, DateTimeImmutable $from, DateTimeImmutable $until, string $operation, int $limit = 100): array;
public function changeRule(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, array|string $input, string $operation, bool $stop = false): array;
public function exception(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $occurrenceKey, int $taskVersion, ?DateTimeImmutable $override, bool $cancel, string $operation): array;
```

## reminders()

`Karnoweb\TaskManager\Scheduling\ReminderService`

```php
public function parseTime(string $wall, string $timezone, string $calendar = 'gregorian', string $dstPolicy = 'reject'): DateTimeImmutable;
public function suggestedRecipient(Karnoweb\TaskManager\Context\ExecutionContext $context, ?string $type = NULL, ?string $id = NULL): string;
public function createGeneral(Karnoweb\TaskManager\Context\ExecutionContext $context, string $body, DateTimeImmutable $when, string $timezone, string $operation, ?string $subjectType = NULL, ?string $subjectId = NULL, ?string $recipient = NULL, bool $allowPast = false): array;
public function updateGeneral(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, array $changes, string $operation): array;
public function changeState(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $action, string $operation): array;
public function query(Karnoweb\TaskManager\Context\ExecutionContext $context, int $limit = 30, int $afterId = 0): array;
public function count(Karnoweb\TaskManager\Context\ExecutionContext $context): int;
public function activity(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function get(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function create(Karnoweb\TaskManager\Context\ExecutionContext $context, string $taskKey, string $anchor, int $offsetSeconds, ?DateTimeImmutable $absolute, string $channel, string $operation): array;
public function snooze(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, DateTimeImmutable $until, string $operation): array;
public function dispatch(Karnoweb\TaskManager\Context\ExecutionContext $context, int $limit = 100): array;
```

## reports()

`Karnoweb\TaskManager\Planning\ReportingService`

```php
public function totals(Karnoweb\TaskManager\Context\ExecutionContext $context, ?string $projectKey = NULL): array;
public function subtreeTime(Karnoweb\TaskManager\Context\ExecutionContext $context, string $taskKey): array;
public function flow(Karnoweb\TaskManager\Context\ExecutionContext $context, string $taskKey, ?DateTimeImmutable $asOf = NULL): array;
```

## routes()

`Karnoweb\TaskManager\Handoff\LocalRouteService`

```php
public function move(Karnoweb\TaskManager\Context\ExecutionContext $context, string $route, array $preview, string $reason, string $operation): array;
public function spawn(Karnoweb\TaskManager\Context\ExecutionContext $context, string $route, string $originKey, int $version, string $pipelineKey, int $revisionId, string $slot, string $sourceTransition, string $reason, string $operation): array;
```

## sagas()

`Karnoweb\TaskManager\Handoff\ExternalSagaService`

```php
public function get(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function begin(Karnoweb\TaskManager\Context\ExecutionContext $context, string $originKey, int $version, string $route, string $transition, string $reason, string $operation): array;
public function execute(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function repair(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version): array;
public function run(Karnoweb\TaskManager\Context\ExecutionContext $context, int $limit = 50): array;
```

## sla()

`Karnoweb\TaskManager\SLA\SlaService`

```php
public function get(Karnoweb\TaskManager\Context\ExecutionContext $context, int $visitId): array;
public function pause(Karnoweb\TaskManager\Context\ExecutionContext $context, int $visitId, int $slaVersion, string $operation, string $reason): array;
public function resume(Karnoweb\TaskManager\Context\ExecutionContext $context, int $visitId, int $slaVersion, string $operation): array;
public function reset(Karnoweb\TaskManager\Context\ExecutionContext $context, int $visitId, int $slaVersion, string $operation): array;
public function markDeadCandidate(Karnoweb\TaskManager\Context\ExecutionContext $context, string $instanceKey, int $visitId): array;
public function run(Karnoweb\TaskManager\Context\ExecutionContext $context, int $limit = 100): array;
```

## sprints()

`Karnoweb\TaskManager\Planning\SprintService`

```php
public function get(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, bool $write = false, bool $lock = false): array;
public function create(Karnoweb\TaskManager\Context\ExecutionContext $context, string $projectKey, string $name, ?DateTimeImmutable $start, ?DateTimeImmutable $end, string $operation): array;
public function enroll(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, array $references, string $operation): array;
public function remove(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $taskKey, int $taskVersion, string $operation): array;
public function start(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $operation): array;
public function snapshot(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $operation): array;
public function close(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $policy, ?string $targetKey, ?int $targetVersion, string $operation): array;
public function report(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function backlog(Karnoweb\TaskManager\Context\ExecutionContext $context, string $projectKey, int $afterId = 0, int $limit = 100): array;
```

## stagePolicies()

`Karnoweb\TaskManager\SLA\StagePolicyService`

```php
public function publishSla(Karnoweb\TaskManager\Context\ExecutionContext $context, string $pipelineKey, string $stageKey, int $pipelineVersion, string $operation, array $policy): array;
```

## tasks()

`Karnoweb\TaskManager\TaskService`

```php
public function createTodo(Karnoweb\TaskManager\DTO\CreateTodo $command): Karnoweb\TaskManager\DTO\TaskResult;
public function inContext(Karnoweb\TaskManager\Context\ExecutionContext $context): Karnoweb\TaskManager\TaskSession;
public function change(Karnoweb\TaskManager\DTO\MutateTask $command): Karnoweb\TaskManager\DTO\TaskResult;
public function findTasks(Karnoweb\TaskManager\DTO\TaskQuery $query): array;
public function authorizedQuery(Karnoweb\TaskManager\Context\ExecutionContext $context, string $capability = 'view'): Illuminate\Database\Query\Builder;
public function get(Karnoweb\TaskManager\Context\ExecutionContext $context, string $id): array;
public function resolveKey(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function query(Karnoweb\TaskManager\Context\ExecutionContext $context, int $page = 1, int $limit = 30): array;
public function count(Karnoweb\TaskManager\Context\ExecutionContext $context): int;
public function facets(Karnoweb\TaskManager\Context\ExecutionContext $context): array;
public function lists(Karnoweb\TaskManager\Context\ExecutionContext $context): array;
public function createList(Karnoweb\TaskManager\Context\ExecutionContext $context, string $name, string $operation): array;
public function share(Karnoweb\TaskManager\Context\ExecutionContext $context, string $id, int $version, string $operation, string $actor, bool $edit = false, bool $revoke = false): array;
public function changeList(Karnoweb\TaskManager\Context\ExecutionContext $context, int $id, int $version, string $operation, array $values): array;
public function cloneTask(Karnoweb\TaskManager\Context\ExecutionContext $context, string $id, string $operation): array;
public function activity(Karnoweb\TaskManager\Context\ExecutionContext $context, string $id): array;
public function create(Karnoweb\TaskManager\Context\ExecutionContext $context, string $title, string $operation): array;
public function mutate(Karnoweb\TaskManager\Context\ExecutionContext $context, string $id, int $version, string $operation, string $action, array $values = []): array;
```

## templates()

`Karnoweb\TaskManager\Planning\TemplateService`

```php
public function get(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, ?int $revision = NULL): array;
public function publish(Karnoweb\TaskManager\Context\ExecutionContext $context, ?string $key, int $version, string $name, array $definition, string $operation): array;
public function instantiate(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $revision, string $projectCode, string $name, string $operation): array;
```

## time()

`Karnoweb\TaskManager\Planning\TimeTrackingService`

```php
public function start(Karnoweb\TaskManager\Context\ExecutionContext $context, string $taskKey, string $operation): array;
public function timer(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function cancelOwn(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $operation): array;
public function stop(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $operation, string $overlapPolicy = 'reject', ?string $actor = NULL): array;
public function log(Karnoweb\TaskManager\Context\ExecutionContext $context, string $taskKey, DateTimeImmutable $start, DateTimeImmutable $end, ?string $note, string $operation, string $overlapPolicy = 'reject', ?string $actor = NULL): array;
public function amend(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, DateTimeImmutable $start, DateTimeImmutable $end, ?string $note, bool $deleted, string $operation, string $overlapPolicy = 'reject'): array;
public function entry(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function stats(Karnoweb\TaskManager\Context\ExecutionContext $context, string $taskKey, bool $truthFallback = true): array;
public function rebuild(Karnoweb\TaskManager\Context\ExecutionContext $context, string $taskKey, string $operation): array;
```

## webhooks()

`Karnoweb\TaskManager\Integration\WebhookService`

```php
public function get(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key): array;
public function create(Karnoweb\TaskManager\Context\ExecutionContext $context, string $url, string $secretRef, array $types, string $operation): array;
public function change(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $version, string $url, string $secretRef, array $types, bool $active, string $operation): array;
public function queue(Karnoweb\TaskManager\Context\ExecutionContext $context, string $eventId): int;
public function deliveries(Karnoweb\TaskManager\Context\ExecutionContext $context, int $afterId = 0, int $limit = 100): array;
public function run(Karnoweb\TaskManager\Context\ExecutionContext $context, int $limit = 100): array;
public function retry(Karnoweb\TaskManager\Context\ExecutionContext $context, int $deliveryId, int $version, string $operation): array;
public function verifyIncoming(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, string $timestamp, string $eventId, string $body, string $signature): bool;
```

## workflows()

`Karnoweb\TaskManager\Workflow\WorkflowService`

```php
public function publish(Karnoweb\TaskManager\Context\ExecutionContext $context, string $key, int $expectedRevision, string $operation, array $definition): array;
public function revision(Karnoweb\TaskManager\Context\ExecutionContext $context, int $id): array;
```

