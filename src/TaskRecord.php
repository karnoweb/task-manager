<?php

namespace Karnoweb\TaskManager;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\Contracts\StageLifecycle;
use Karnoweb\TaskManager\Enterprise\TextNormalizer;
use Karnoweb\TaskManager\Schema\Tables;
use Karnoweb\TaskManager\TaskGraph\HierarchyIndex;
use Karnoweb\TaskManager\TaskGraph\ScopeGraphLock;
use Karnoweb\TaskManager\Workflow\StateIndex;

/** @internal Shared persistence only; callers authorize and journal the command first. */
final readonly class TaskRecord
{
    public function __construct(private Connection $db, private Clock $clock)
    {
        Tables::assertConnection($db);
    }

    public function insert(ExecutionContext $context, array $revision, string $title, ?string $description = null, string $priority = 'normal', ?array $project = null, string $type = 'task', ?StageLifecycle $lifecycle = null, string $operation = ''): string
    {
        $context->validateTenant();
        ScopeGraphLock::acquire($this->db, $context);
        $this->db->table(Tables::name('lists'))->insertOrIgnore(['scope_key' => $context->scope, 'owner_ref' => $context->actor, 'name' => 'Inbox', 'is_inbox' => true]);
        $listId = $this->db->table(Tables::name('lists'))->where('scope_key', $context->scope)->where('owner_ref', $context->actor)->where('name', 'Inbox')->value('id');
        $sequenceKey = $project === null ? 'task' : 'project:'.$project['id'];
        $this->db->table(Tables::name('sequences'))->insertOrIgnore(['scope_key' => $context->scope, 'sequence_key' => $sequenceKey, 'current' => 0]);
        $sequence = $this->db->table(Tables::name('sequences'))->where('scope_key', $context->scope)->where('sequence_key', $sequenceKey)->lockForUpdate()->first();
        $number = $sequence->current + 1;
        $this->db->table(Tables::name('sequences'))->where('scope_key', $context->scope)->where('sequence_key', $sequenceKey)->update(['current' => $number]);
        $display = ($project['key'] ?? 'TM').'-'.$number;
        $key = (string) Str::uuid();
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $taskId = $this->db->table(Tables::name('tasks'))->insertGetId([
            'public_key' => $key, 'display_key' => $display, 'scope_key' => $context->scope,
            'owner_ref' => $context->actor, 'list_id' => $listId, 'project_id' => $project['id'] ?? null,
            'type_key' => $type, 'title' => $title, 'description' => $description, 'priority' => $priority,
            'title_search_normalized' => TextNormalizer::normalize($title), 'search_revision' => TextNormalizer::VERSION,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        HierarchyIndex::root($this->db, $context, $taskId);
        ScopeGraphLock::changed($this->db, $context);
        $definition = $revision['definition'];
        StateIndex::publish($this->db, $revision['id'], $definition);
        $state = $definition['initial'];
        $instanceId = $this->db->table(Tables::name('workflow_instances'))->insertGetId([
            'instance_key' => (string) Str::uuid(), 'scope_key' => $context->scope, 'task_id' => $taskId,
            'workflow_revision_id' => $revision['id'], 'workflow_key' => $revision['key'], 'current_state_key' => $state,
            'subject_type' => 'task', 'subject_id' => $key, 'slot_key' => 'main',
            'owner_ref' => $context->actor, 'primary_assignee' => $context->actor, 'started_at' => $now,
        ]);
        $this->db->table(Tables::name('active_process_slots'))->insert(['scope_key' => $context->scope, 'subject_type' => 'task', 'subject_id' => $key, 'slot_key' => 'main', 'instance_id' => $instanceId]);
        $this->db->table(Tables::name('task_key_aliases'))->insert(['scope_key' => $context->scope, 'task_id' => $taskId, 'display_key' => $display]);
        if ($project !== null) {
            $instance = (array) $this->db->table(Tables::name('workflow_instances'))->where('id', $instanceId)->first();
            $lifecycle?->beforeTransition($context, $instance, ['key' => 'create_project_task', 'from' => $state, 'to' => $state, 'starting_instance' => true], []);
            if (StateIndex::category($definition, $state) === 'in_progress') {
                $this->db->table(Tables::name('instance_transitions'))->insert([
                    'scope_key' => $context->scope, 'instance_id' => $instanceId, 'from_state' => '', 'to_state' => $state,
                    'from_revision_id' => null, 'to_revision_id' => $revision['id'], 'from_category' => null, 'to_category' => 'in_progress',
                    'actor_ref' => $context->actor, 'operation_id' => $operation, 'occurred_at' => $now,
                ]);
            }
            $lifecycle?->activated($context, $instanceId, $operation);
        }

        return $key;
    }
}
