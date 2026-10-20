<?php

namespace Karnoweb\TaskManager;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;

final class TaskService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock = new SystemClock(), private readonly AuthorizationProvider $authorization = new ContextAuthorization(), private readonly ?\Karnoweb\TaskManager\Contracts\StageLifecycle $lifecycle = null) {}

    public function createTodo(\Karnoweb\TaskManager\DTO\CreateTodo $command): \Karnoweb\TaskManager\DTO\TaskResult
    {
        return \Karnoweb\TaskManager\DTO\TaskResult::fromArray($this->create($command->context, $command->title, $command->operationId));
    }

    public function inContext(ExecutionContext $context): TaskSession { return new TaskSession($this, $context); }

    public function change(\Karnoweb\TaskManager\DTO\MutateTask $command): \Karnoweb\TaskManager\DTO\TaskResult
    {
        return \Karnoweb\TaskManager\DTO\TaskResult::fromArray($this->mutate($command->context, $command->publicKey, $command->expectedVersion, $command->operationId, $command->action, $command->values));
    }

    /** @return list<\Karnoweb\TaskManager\DTO\TaskResult> */
    public function findTasks(\Karnoweb\TaskManager\DTO\TaskQuery $query): array
    {
        return array_map(\Karnoweb\TaskManager\DTO\TaskResult::fromArray(...), $this->query($query->context, $query->page, $query->limit));
    }

    private function now(): string { return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }

    private function authorize(ExecutionContext $context, string $capability): void
    {
        $context->require($capability);
        $this->authorization->authorize($context, $capability);
    }

    private function normalize(string $value): string
    {
        return strtr(mb_strtolower($value), ['ي' => 'ی', 'ك' => 'ک', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    }

    private function visible(ExecutionContext $context, string $capability = 'view'): Builder
    {
        $this->authorize($context, 'task.'.$capability);
        $query = $this->db->table('tm_tasks')->where('scope_key', $context->scope)
            ->where(function (Builder $q) use ($context, $capability) {
                $q->where(function (Builder $personal) use ($context, $capability) {
                    $personal->whereNull('project_id')->where(function (Builder $owner) use ($context, $capability) {
                        $owner->where('owner_ref', $context->actor)->orWhereExists(function (Builder $grant) use ($context, $capability) {
                    $grant->selectRaw('1')->from('tm_task_grants')->whereColumn('task_id', 'tm_tasks.id')
                        ->where('actor_ref', $context->actor)->where('capability', $capability);
                        });
                    });
                })->orWhereExists(function (Builder $members) use ($context, $capability) {
                    $members->selectRaw('1')->from('tm_project_members')->whereColumn('project_id', 'tm_tasks.project_id')->where('actor_ref', $context->actor)
                        ->whereIn('role', $capability === 'view' ? ['manager', 'member', 'viewer'] : ['manager', 'member']);
                });
            });
        $this->authorization->constrain($query, $context, 'task', $capability);
        return $query;
    }

    public function authorizedQuery(ExecutionContext $context, string $capability = 'view'): Builder
    {
        if (!in_array($capability, ['view', 'edit'], true)) { throw new DomainError('capability_invalid'); }
        return $this->visible($context, $capability);
    }

    public function get(ExecutionContext $context, string $id): array
    {
        if (!Str::isUuid($id)) { throw new DomainError('not_found'); }
        $task = $this->visible($context)->where('public_key', $id)->first();
        if (!$task) { throw new DomainError('not_found'); }
        $instance = $this->db->table('tm_workflow_instances')->where('task_id', $task->id)->where('scope_key', $context->scope)->where('slot_key', 'main')->orderByDesc('id')->first();
        return [...(array) $task, 'state' => $instance->current_state_key, 'completed_at' => $instance->completed_at];
    }

    public function resolveKey(ExecutionContext $context, string $key): array
    {
        $task = $this->visible($context)->whereExists(function (Builder $query) use ($context, $key) {
            $query->selectRaw('1')->from('tm_task_key_aliases')->whereColumn('tm_task_key_aliases.task_id', 'tm_tasks.id')
                ->where('tm_task_key_aliases.scope_key', $context->scope)->where('display_key', $key);
        })->first();
        if (!$task) { throw new DomainError('not_found'); }
        return $this->get($context, $task->public_key);
    }

    private function nextDisplayKey(ExecutionContext $context): string
    {
        $this->db->table('tm_sequences')->insertOrIgnore(['scope_key' => $context->scope, 'sequence_key' => 'task', 'current' => 0]);
        $sequence = $this->db->table('tm_sequences')->where('scope_key', $context->scope)->where('sequence_key', 'task')->lockForUpdate()->first();
        $next = $sequence->current + 1;
        $this->db->table('tm_sequences')->where('scope_key', $context->scope)->where('sequence_key', 'task')->update(['current' => $next]);
        return 'TM-'.$next;
    }

    public function query(ExecutionContext $context, int $page = 1, int $limit = 30): array
    {
        if ($page < 1 || $limit < 1 || $limit > 100) { throw new DomainError('pagination_invalid'); }
        return $this->visible($context)->select('tm_tasks.*')
            ->selectSub($this->db->table('tm_workflow_instances')->select('current_state_key')->whereColumn('task_id', 'tm_tasks.id')->where('slot_key', 'main')->orderByDesc('id')->limit(1), 'state')
            ->selectSub($this->db->table('tm_workflow_instances')->select('completed_at')->whereColumn('task_id', 'tm_tasks.id')->where('slot_key', 'main')->orderByDesc('id')->limit(1), 'completed_at')
            ->whereNull('deleted_at')->whereNull('archived_at')->orderBy('public_key')
            ->forPage($page, $limit)->get()->map(fn ($row) => (array) $row)->all();
    }

    public function count(ExecutionContext $context): int
    {
        return $this->visible($context)->whereNull('deleted_at')->whereNull('archived_at')->count();
    }

    public function facets(ExecutionContext $context): array
    {
        return $this->visible($context)->whereNull('deleted_at')->whereNull('archived_at')
            ->select('priority')->selectRaw('COUNT(*) AS total')->groupBy('priority')->orderBy('priority')->get()
            ->map(fn ($row) => ['priority' => $row->priority, 'total' => (int) $row->total])->all();
    }

    private function ownedList(ExecutionContext $context, string $id, bool $lock = false): object
    {
        $query = $this->db->table('tm_lists')->where('scope_key', $context->scope)->where('owner_ref', $context->actor)->where('id', $id);
        $this->authorization->constrain($query, $context, 'list', 'view');
        if ($lock) { $query->lockForUpdate(); }
        $list = $query->first();
        if (!$list) { throw new DomainError('not_found'); }
        return $list;
    }

    public function lists(ExecutionContext $context): array
    {
        $this->authorize($context, 'list.view');
        $query = $this->db->table('tm_lists')->where('scope_key', $context->scope)->where('owner_ref', $context->actor);
        $this->authorization->constrain($query, $context, 'list', 'view');
        return $query->whereNull('archived_at')->orderBy('rank')->orderBy('id')->limit(100)->get()->map(fn ($row) => (array) $row)->all();
    }

    public function createList(ExecutionContext $context, string $name, string $operation): array
    {
        $this->authorize($context, 'list.create');
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 255 || $name === 'Inbox') { throw new DomainError('list_name_invalid'); }
        return $this->command($context, $operation, ['action' => 'create_list', 'name' => $name], function () use ($context, $name, $operation) {
            $id = $this->db->table('tm_lists')->insertGetId(['scope_key' => $context->scope, 'owner_ref' => $context->actor, 'name' => $name]);
            $this->record($context, null, 'list_created', $operation);
            return ['list_id' => $id, 'name' => $name];
        });
    }

    public function share(ExecutionContext $context, string $id, int $version, string $operation, string $actor, bool $edit = false, bool $revoke = false): array
    {
        $this->authorize($context, 'task.share');
        if (trim($actor) === '' || strlen($actor) > 120) { throw new DomainError('actor_invalid'); }
        return $this->command($context, $operation, compact('id', 'version', 'actor', 'edit', 'revoke') + ['action' => 'share'], function () use ($context, $id, $version, $operation, $actor, $edit, $revoke) {
            $task = $this->visible($context, 'edit')->where('owner_ref', $context->actor)->where('public_key', $id)->lockForUpdate()->first();
            if (!$task) { throw new DomainError('not_found'); }
            if ($task->version !== $version) { throw new DomainError('version_conflict'); }
            $this->db->table('tm_task_grants')->where('task_id', $task->id)->where('actor_ref', $actor)->delete();
            if (!$revoke) {
                foreach ($edit ? ['view', 'edit'] : ['view'] as $capability) {
                    $this->db->table('tm_task_grants')->insert(['task_id' => $task->id, 'actor_ref' => $actor, 'capability' => $capability]);
                }
            }
            $this->db->table('tm_tasks')->where('public_key', $id)->update(['version' => $version + 1]);
            $this->record($context, $id, $revoke ? 'share_revoked' : 'shared', $operation);
            return $this->get($context, $id);
        });
    }

    public function changeList(ExecutionContext $context, int $id, int $version, string $operation, array $values): array
    {
        $this->authorize($context, 'list.create');
        return $this->command($context, $operation, ['action' => 'change_list', 'id' => $id, 'version' => $version, 'values' => $values], function () use ($context, $id, $version, $operation, $values) {
            $this->ownedList($context, (string) $id);
            $list = $this->db->table('tm_lists')->where('id', $id)->where('scope_key', $context->scope)->lockForUpdate()->first();
            if ($list->version !== $version) { throw new DomainError('version_conflict'); }
            if ($list->is_inbox) { throw new DomainError('inbox_immutable'); }
            $changes = ['version' => $version + 1];
            foreach ($values as $key => $value) {
                if ($key === 'name' && is_string($value) && trim($value) !== '' && mb_strlen($value) <= 255 && $value !== 'Inbox') { $changes['name'] = trim($value); }
                elseif ($key === 'rank' && is_string($value) && preg_match('/^-?\d{1,11}(\.\d{1,8})?$/D', $value)) { $changes['rank'] = $value; }
                elseif ($key === 'archived' && is_bool($value)) {
                    if ($value && $this->db->table('tm_tasks')->where('list_id', $id)->whereNull('deleted_at')->whereNull('archived_at')->exists()) { throw new DomainError('list_not_empty'); }
                    $changes['archived_at'] = $value ? $this->now() : null;
                } else { throw new DomainError('list_value_invalid'); }
            }
            $this->db->table('tm_lists')->where('id', $id)->update($changes);
            $this->record($context, null, 'list_changed', $operation, metadata: ['list_id' => $id, 'fields' => array_keys($values)]);
            return ['list_id' => $id, ...(array) $this->ownedList($context, (string) $id)];
        });
    }

    public function cloneTask(ExecutionContext $context, string $id, string $operation): array
    {
        $this->authorize($context, 'task.create');
        return $this->command($context, $operation, ['action' => 'clone', 'source' => $id], function () use ($context, $id, $operation) {
            $source = $this->get($context, $id);
            // Create a fresh personal task: grants, project and lifecycle never carry over.
            return $this->createRecord($context, $source['title'], $operation, $source['description'], $source['priority']);
        });
    }

    public function activity(ExecutionContext $context, string $id): array
    {
        $this->authorize($context, 'activity.view');
        $task = $this->get($context, $id);
        return $this->db->table('tm_audit_events')->where('scope_key', $context->scope)->where('task_id', $task['id'])
            ->orderBy('occurred_at')->orderBy('event_id')->limit(100)->get()->map(fn ($row) => (array) $row)->all();
    }

    private function command(ExecutionContext $context, string $operation, array $payload, callable $execute): array
    {
        return (new CommandExecutor($this->db))->execute($context, $operation, $payload, $execute, function (array $result) use ($context) {
            if (isset($result['public_key'])) { $this->get($context, $result['public_key']); }
            elseif (isset($result['list_id'])) { $this->ownedList($context, $result['list_id']); }
        });
    }

    private function record(ExecutionContext $context, ?string $id, string $action, string $operation, array $before = [], array $changed = [], array $metadata = []): void
    {
        $task = $id === null ? null : $this->db->table('tm_tasks')->where('public_key', $id)->where('scope_key', $context->scope)->first();
        $safeFields = array_flip(['version', 'priority', 'list_id', 'archived_at', 'deleted_at']);
        $snapshot = ['task_id' => $id, 'changed_field_paths' => array_keys($changed), 'before_redacted' => array_intersect_key($before, $safeFields),
            'after_redacted' => array_intersect_key((array) $task, $safeFields), 'metadata' => $metadata];
        if (array_key_exists('title', $before)) { $snapshot['before_redacted']['title_hash'] = hash('sha256', $before['title']); }
        if ($task !== null) { $snapshot['after_redacted']['title_hash'] = hash('sha256', $task->title); }
        $event = ['event_id' => (string) Str::uuid(), 'scope_key' => $context->scope, 'actor_ref' => $context->actor,
            'task_id' => $task?->id, 'action' => $action, 'operation_id' => $operation, 'correlation_id' => $context->correlationId,
            'payload' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'occurred_at' => $this->now()];
        $this->db->table('tm_audit_events')->insert($event);
        $this->db->table('tm_outbox')->insert($event);
    }

    public function create(ExecutionContext $context, string $title, string $operation): array
    {
        $this->authorize($context, 'task.create');
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 255) { throw new DomainError('title_invalid'); }
        return $this->command($context, $operation, ['action' => 'create', 'title' => $title], fn () => $this->createRecord($context, $title, $operation));
    }

    private function createRecord(ExecutionContext $context, string $title, string $operation, ?string $description = null, string $priority = 'normal'): array
    {
            $this->db->table('tm_workflow_definitions')->insertOrIgnore(['scope_key' => $context->scope,
                'key' => 'personal', 'revision' => 1, 'definition' => json_encode(['states' => ['pending', 'done'], 'transitions' => ['pending' => ['done'], 'done' => ['pending']]], JSON_THROW_ON_ERROR)]);
            $list = $this->db->table('tm_lists')->where('scope_key', $context->scope)->where('owner_ref', $context->actor)->where('name', 'Inbox')->first();
            if (!$list) {
                $this->db->table('tm_lists')->insertOrIgnore(['scope_key' => $context->scope, 'owner_ref' => $context->actor, 'name' => 'Inbox', 'is_inbox' => true]);
                $listId = $this->db->table('tm_lists')->where('scope_key', $context->scope)->where('owner_ref', $context->actor)->where('name', 'Inbox')->value('id');
            } else { $listId = $list->id; }
            $id = (string) Str::uuid();
            $now = $this->now();
            $displayKey = $this->nextDisplayKey($context);
            $internalId = $this->db->table('tm_tasks')->insertGetId(['public_key' => $id, 'display_key' => $displayKey, 'scope_key' => $context->scope,
                'owner_ref' => $context->actor, 'list_id' => $listId, 'title' => $title, 'description' => $description, 'priority' => $priority, 'title_search_normalized' => $this->normalize($title), 'created_at' => $now, 'updated_at' => $now]);
            $revisionId = $this->db->table('tm_workflow_definitions')->where('scope_key', $context->scope)->where('key', 'personal')->where('revision', 1)->value('id');
            $instanceId = $this->db->table('tm_workflow_instances')->insertGetId(['instance_key' => (string) Str::uuid(), 'scope_key' => $context->scope, 'task_id' => $internalId, 'workflow_revision_id' => $revisionId,
                'subject_type' => 'task', 'subject_id' => $id, 'owner_ref' => $context->actor, 'primary_assignee' => $context->actor, 'started_at' => $now]);
            $this->db->table('tm_active_process_slots')->insert(['scope_key' => $context->scope, 'subject_type' => 'task', 'subject_id' => $id, 'slot_key' => 'main', 'instance_id' => $instanceId]);
            $this->db->table('tm_task_key_aliases')->insert(['scope_key' => $context->scope, 'task_id' => $internalId, 'display_key' => $displayKey]);
            $this->record($context, $id, 'created', $operation);
            return $this->get($context, $id);
    }

    public function mutate(ExecutionContext $context, string $id, int $version, string $operation, string $action, array $values = []): array
    {
        $allowed = ['update', 'complete', 'reopen', 'archive', 'restore', 'delete', 'undelete', 'move_list'];
        if (!in_array($action, $allowed, true)) { throw new DomainError('action_invalid'); }
        return $this->command($context, $operation, compact('id', 'version', 'action', 'values'), function () use ($context, $id, $version, $operation, $action, $values) {
            $task = $this->visible($context, 'edit')->where('public_key', $id)->lockForUpdate()->first();
            if (!$task) { throw new DomainError('not_found'); }
            if ($task->version !== $version) { throw new DomainError('version_conflict'); }
            $changes = ['version' => $version + 1, 'updated_at' => $this->now()];
            if ($action === 'move_list') {
                if ($task->owner_ref !== $context->actor) { throw new DomainError('access_denied'); }
                $list = $this->ownedList($context, $values['list_id'] ?? '', true);
                if ($list->archived_at !== null) { throw new DomainError('list_archived'); }
                $changes['list_id'] = $values['list_id'];
                // Moving lists preserves the task's existing rank.
            }
            if ($action === 'update') {
                foreach ($values as $key => $value) {
                    if (!in_array($key, ['title', 'description', 'priority'], true)) { throw new DomainError('field_invalid'); }
                    if ($key === 'title' && (!is_string($value) || trim($value) === '' || mb_strlen($value) > 255)) { throw new DomainError('title_invalid'); }
                    if ($key === 'priority' && !in_array($value, ['low', 'normal', 'high', 'urgent'], true)) { throw new DomainError('priority_invalid'); }
                    if ($key === 'description' && $value !== null && !is_string($value)) { throw new DomainError('description_invalid'); }
                    $changes[$key] = $value;
                    if ($key === 'title') { $changes['title_search_normalized'] = $this->normalize($value); }
                }
            }
            $now = $this->now();
            if (in_array($action, ['archive', 'delete'], true)) {
                $guarded = $this->db->table('tm_workflow_instances')->where('task_id', $task->id)->where('slot_key', 'main')->orderByDesc('id')->lockForUpdate()->first();
                $this->lifecycle?->beforeTransition($context, (array) $guarded, ['key' => $action, 'from' => $guarded->current_state_key, 'to' => $guarded->current_state_key, 'force_exit' => true], []);
            }
            if (in_array($action, ['archive', 'restore'], true)) { $changes['archived_at'] = $action === 'archive' ? $now : null; }
            if (in_array($action, ['delete', 'undelete'], true)) { $changes['deleted_at'] = $action === 'delete' ? $now : null; }
            if (in_array($action, ['complete', 'reopen'], true)) {
                if ($task->project_id !== null) { throw new DomainError('workflow_command_required'); }
                $instance = $this->db->table('tm_workflow_instances')->where('task_id', $task->id)->where('slot_key', 'main')->orderByDesc('id')->lockForUpdate()->first();
                if ($instance->workflow_key !== 'personal' || $instance->pipeline_id !== null) { throw new DomainError('workflow_command_required'); }
                $state = $action === 'complete' ? 'done' : 'pending';
                if ($instance->current_state_key === $state) { throw new DomainError('transition_invalid'); }
                $this->lifecycle?->beforeTransition($context, (array) $instance, ['key' => $action, 'from' => $instance->current_state_key, 'to' => $state], []);
                if ($state === 'done') { $this->lifecycle?->leaving($context, $instance->id); }
                $this->db->table('tm_instance_transitions')->insert(['scope_key' => $context->scope, 'instance_id' => $instance->id,
                    'from_state' => $instance->current_state_key, 'to_state' => $state, 'actor_ref' => $context->actor, 'operation_id' => $operation, 'occurred_at' => $now]);
                $this->db->table('tm_workflow_instances')->where('id', $instance->id)->update(['current_state_key' => $state, 'lifecycle' => $state === 'done' ? 'closed' : 'active',
                    'completed_at' => $state === 'done' ? $now : null, 'version' => $instance->version + 1]);
                if ($state === 'done') { $this->db->table('tm_active_process_slots')->where('instance_id', $instance->id)->delete(); }
                else {
                    $reserved = $this->db->table('tm_active_process_slots')->insertOrIgnore(['scope_key' => $context->scope, 'subject_type' => 'task', 'subject_id' => $id, 'slot_key' => 'main', 'instance_id' => $instance->id]);
                    if (!$reserved) { throw new DomainError('active_slot_conflict'); }
                    $this->lifecycle?->activated($context, $instance->id, $operation);
                }
            }
            $changed = $this->visible($context, 'edit')->where('public_key', $id)->where('version', $version)->update($changes);
            if ($changed !== 1) { throw new DomainError('version_conflict'); }
            $this->record($context, $id, $action, $operation, (array) $task, $changes);
            return $this->get($context, $id);
        });
    }
}
