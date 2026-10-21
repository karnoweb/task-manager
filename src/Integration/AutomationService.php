<?php

namespace Karnoweb\TaskManager\Integration;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Scheduling\LocalTime;
use Karnoweb\TaskManager\TaskGraph\ScopeGraphLock;
use Karnoweb\TaskManager\TaskService;
use Karnoweb\TaskManager\Workflow\StateIndex;

final class AutomationService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly EventAccess $access, private readonly AutomationActions $actions)
    {
    }

    private function authorize(ExecutionContext $context): void
    {
        $context->require('automation.manage');
        $this->authorization->authorize($context, 'automation.manage');
    }

    public function get(ExecutionContext $context, string $key): array
    {
        $this->authorize($context);
        if (! Str::isUuid($key)) {
            throw new DomainError('not_found');
        }$row = $this->db->table('tm_automation_rules')->where('scope_key', $context->scope)->where('owner_ref', $context->actor)->where('rule_key', $key)->first();
        if (! $row) {
            throw new DomainError('not_found');
        }$revision = $this->db->table('tm_automation_rule_revisions')->where('rule_id', $row->id)->where('revision', $row->version)->first();

        return [...(array) $row, 'definition' => json_decode($revision->definition, true, flags: JSON_THROW_ON_ERROR)];
    }

    public function publish(ExecutionContext $context, ?string $key, int $version, string $trigger, array $definition, string $operation): array
    {
        $this->authorize($context);
        if (! preg_match('/^[a-z][a-z0-9_]{0,99}$/D', $trigger) || array_diff(array_keys($definition), ['conditions', 'actions', 'max_depth', 'max_actions', 'hourly_limit'])) {
            throw new DomainError('automation_rule_invalid');
        }
        $d = ['conditions' => $definition['conditions'] ?? [], 'actions' => $definition['actions'] ?? [], 'max_depth' => $definition['max_depth'] ?? 5, 'max_actions' => $definition['max_actions'] ?? 20, 'hourly_limit' => $definition['hourly_limit'] ?? 100, 'capabilities' => $context->capabilities];
        if (! is_array($d['conditions']) || ! array_is_list($d['conditions']) || count($d['conditions']) > 20 || ! is_array($d['actions']) || ! array_is_list($d['actions']) || ! $d['actions'] || count($d['actions']) > 20 || ! is_int($d['max_depth']) || $d['max_depth'] < 1 || $d['max_depth'] > 10 || ! is_int($d['max_actions']) || $d['max_actions'] < 1 || $d['max_actions'] > 20 || ! is_int($d['hourly_limit']) || $d['hourly_limit'] < 1 || $d['hourly_limit'] > 1000 || ($key === null && $version !== 0)) {
            throw new DomainError('automation_rule_invalid');
        }
        if (strlen(json_encode($d, JSON_THROW_ON_ERROR)) > 131072) {
            throw new DomainError('automation_rule_invalid');
        }
        foreach ($d['actions'] as $action) {
            if (! is_array($action)) {
                throw new DomainError('automation_action_invalid');
            }$this->actions->validate($action);
        }
        foreach ($d['conditions'] as $condition) {
            if (! is_array($condition) || array_diff(array_keys($condition), ['field', 'operator', 'value']) || ! in_array($condition['field'] ?? null, ['priority', 'type_key', 'status_category', 'event_type'], true) || ! in_array($condition['operator'] ?? null, ['equals', 'in'], true) || ! array_key_exists('value', $condition) || ($condition['operator'] === 'in' && (! is_array($condition['value']) || count($condition['value']) > 100))) {
                throw new DomainError('automation_condition_invalid');
            }
        }

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'automation_publish', 'key' => $key, 'version' => $version, 'trigger' => $trigger, 'definition' => $d], function () use ($context, $key, $version, $trigger, $d, $operation) {
            ScopeGraphLock::acquire($this->db, $context);
            $public = $key ?? (string) Str::uuid();
            if ($key === null) {
                if ($this->db->table('tm_automation_rules')->where('scope_key', $context->scope)->where('owner_ref', $context->actor)->count() >= 100) {
                    throw new DomainError('automation_rule_limit');
                }$id = $this->db->table('tm_automation_rules')->insertGetId(['rule_key' => $public, 'scope_key' => $context->scope, 'owner_ref' => $context->actor, 'trigger' => $trigger]);
            } else {
                $prior = $this->get($context, $key);
                if ($prior['version'] !== $version) {
                    throw new DomainError('version_conflict');
                }$id = $prior['id'];
                $this->db->table('tm_automation_rules')->where('id', $id)->update(['trigger' => $trigger, 'version' => $version + 1]);
            }$this->db->table('tm_automation_rule_revisions')->insert(['rule_id' => $id, 'revision' => $version + 1, 'definition' => json_encode($d, JSON_THROW_ON_ERROR)]);
            (new EventRecorder($this->db, $this->clock))->append($context, 'automation_rule', $public, 'automation_rule_published', $operation, ['revision' => $version + 1]);

            return $this->get($context, $public);
        }, fn ($result) => $this->get($context, $result['rule_key']));
    }

    public function toggle(ExecutionContext $context, string $key, int $version, bool $active, string $operation): array
    {
        $this->authorize($context);

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'automation_toggle', 'key' => $key, 'version' => $version, 'active' => $active], function () use ($context, $key, $version, $active, $operation) {
            ScopeGraphLock::acquire($this->db, $context);
            $row = $this->get($context, $key);
            if ($row['version'] !== $version) {
                throw new DomainError('version_conflict');
            }$this->db->table('tm_automation_rules')->where('id', $row['id'])->update(['active' => $active, 'version' => $version + 1]);
            $this->db->table('tm_automation_rule_revisions')->insert(['rule_id' => $row['id'], 'revision' => $version + 1, 'definition' => json_encode($row['definition'], JSON_THROW_ON_ERROR)]);
            (new EventRecorder($this->db, $this->clock))->append($context, 'automation_rule', $key, 'automation_rule_toggled', $operation, ['active' => $active, 'version' => $version + 1]);

            return $this->get($context, $key);
        }, fn ($result) => $this->get($context, $result['rule_key']));
    }

    public function run(ExecutionContext $context, string $eventId, int $limit = 100): array
    {
        $this->authorize($context);
        if (! $context->serviceActor || $this->db->transactionLevel() !== 0 || $limit < 1 || $limit > 100 || ! Str::isUuid($eventId)) {
            throw new DomainError('automation_worker_context_invalid');
        }
        $event = $this->db->table('tm_outbox')->where('scope_key', $context->scope)->where('event_id', $eventId)->first();
        if (! $event) {
            throw new DomainError('not_found');
        }$source = $this->access->source($context, (array) $event);
        $rules = $this->db->table('tm_automation_rules')->where('scope_key', $context->scope)->where('owner_ref', $context->actor)->where('trigger', $event->action)->where('active', true)->orderBy('id')->limit($limit)->get();
        $results = [];
        foreach ($rules as $head) {
            $rule = $this->get($context, $head->rule_key);
            $d = $rule['definition'];
            $lineage = $this->db->table('tm_event_lineage')->where('event_id', $eventId)->first();
            $root = $lineage?->root_event_id ?? $eventId;
            $depth = ($lineage?->depth ?? 0) + 1;
            $child = new ExecutionContext($context->scope, $context->actor, $context->correlationId, true, array_values(array_intersect($context->capabilities, $d['capabilities'])), $root, $depth);
            $matches = true;
            $task = null;
            if ($source['task_key'] !== null) {
                $task = $this->tasks->get($child, $source['task_key']);
            }
            foreach ($d['conditions'] as $condition) {
                $actual = match ($condition['field']) {
                    'event_type' => $event->action,'status_category' => $task === null ? null : $this->tasks->authorizedQuery($child)->where('id', $task['id'])->select(['category' => StateIndex::taskCategoryQuery($this->db, $context->scope)])->first()?->category,default => $task[$condition['field']] ?? null
                };
                if (! ($condition['operator'] === 'equals' ? $actual === $condition['value'] : in_array($actual, $condition['value'], true))) {
                    $matches = false;
                    break;
                }
            }
            if (! $matches) {
                continue;
            }
            foreach ($d['actions'] as $index => $action) {
                $results[] = $this->db->transaction(function () use ($context, $child, $rule, $d, $index, $action, $event, $root, $depth) {
                    ScopeGraphLock::acquire($this->db, $context);
                    $actionKey = 'action_'.$index;
                    $prior = $this->db->table('tm_automation_runs')->where('rule_id', $rule['id'])->where('event_id', $event->event_id)->where('action_key', $actionKey)->first();
                    if ($prior) {
                        return ['run_id' => $prior->id, 'state' => $prior->state, 'reason_code' => $prior->reason_code];
                    }
                    $id = $this->db->table('tm_automation_runs')->insertGetId(['rule_id' => $rule['id'], 'event_id' => $event->event_id, 'rule_revision' => $rule['version'], 'action_key' => $actionKey, 'state' => 'pending', 'occurred_at' => LocalTime::stamp($this->clock->now())]);
                    $reason = null;
                    $state = 'completed';
                    $this->db->table('tm_automation_budgets')->insertOrIgnore(['scope_key' => $context->scope, 'root_event_id' => $root]);
                    $budget = $this->db->table('tm_automation_budgets')->where('scope_key', $context->scope)->where('root_event_id', $root)->lockForUpdate()->first();
                    $hour = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H');
                    $this->db->table('tm_automation_throttles')->insertOrIgnore(['rule_id' => $rule['id'], 'hour_key' => $hour]);
                    $throttle = $this->db->table('tm_automation_throttles')->where('rule_id', $rule['id'])->where('hour_key', $hour)->lockForUpdate()->first();
                    if (! $this->db->table('tm_automation_rules')->where('id', $rule['id'])->where('version', $rule['version'])->where('active', true)->exists()) {
                        $reason = 'automation_rule_changed';
                    } elseif ($depth > $d['max_depth']) {
                        $reason = 'automation_depth_limit';
                    } elseif ($index >= $d['max_actions'] || $budget->used >= 20) {
                        $reason = 'automation_action_limit';
                    } elseif ($throttle->used >= $d['hourly_limit']) {
                        $reason = 'rate_limited';
                    }
                    if ($reason === null) {
                        $this->db->table('tm_automation_budgets')->where('scope_key', $context->scope)->where('root_event_id', $root)->increment('used');
                        $this->db->table('tm_automation_throttles')->where('rule_id', $rule['id'])->where('hour_key', $hour)->increment('used');
                        try {
                            $this->db->transaction(function () use ($child, $event, $action, $id) {
                                $fresh = $this->access->source($child, (array) $event);
                                $this->actions->execute($child, (array) $event, $fresh, $action, 'automation:'.hash('sha256', (string) $id));
                            });
                        } catch (DomainError $error) {
                            $reason = preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $error->getMessage()) ? $error->getMessage() : 'automation_action_denied';
                        }
                    }
                    if ($reason !== null) {
                        $state = 'rejected';
                    }$this->db->table('tm_automation_runs')->where('id',$id)->update(['state' => $state, 'reason_code' => $reason]);
                    (new EventRecorder($this->db,$this->clock))->append($child,'automation_rule',$rule['rule_key'],'automation_action_processed','automation-result:'.$id,['state' => $state, 'reason_code' => $reason, 'action_key' => $actionKey]);

                    return ['run_id' => $id, 'state' => $state, 'reason_code' => $reason];
                }, 3);
            }
        }

        return ['event_id' => $eventId, 'actions' => $results];
    }
}
