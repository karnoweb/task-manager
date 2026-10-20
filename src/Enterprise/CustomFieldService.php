<?php

namespace Karnoweb\TaskManager\Enterprise;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\Contracts\ResponsibilityResolver;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Scheduling\LocalTime;
use Karnoweb\TaskManager\TaskGraph\ScopeGraphLock;
use Karnoweb\TaskManager\TaskService;
use Karnoweb\TaskManager\Workflow\Definition;

final class CustomFieldService
{
    private const TYPES = ['text','long_text','number','bool','date','datetime','single_select','multi_select','actor_ref','url'];
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly ResponsibilityResolver $resolver) {}
    private function authorize(ExecutionContext $context, string $capability): void { $context->require($capability); $this->authorization->authorize($context, $capability); }
    public function create(ExecutionContext $context, string $name, string $type, array $options, ?string $readCapability, string $operation): array
    {
        $this->authorize($context, 'custom_field.manage'); if (trim($name) === '' || mb_strlen($name) > 255 || !in_array($type, self::TYPES, true) || !array_is_list($options) || count($options) > 100 || count(array_unique($options, SORT_REGULAR)) !== count($options) || ($readCapability !== null && (!preg_match('/^[a-z][a-z0-9_.]{0,119}$/D', $readCapability))) || (!in_array($type, ['single_select','multi_select'], true) && $options)) { throw new DomainError('custom_field_invalid'); }
        foreach ($options as $option) { Definition::key($option); }
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'custom_field_create', 'name' => $name, 'type' => $type, 'options' => $options, 'read_capability' => $readCapability], function () use ($context, $name, $type, $options, $readCapability, $operation) {
            $key = (string) Str::uuid(); $this->db->table('tm_custom_fields')->insert(['field_key' => $key, 'scope_key' => $context->scope, 'name' => $name, 'type' => $type, 'options' => json_encode($options, JSON_THROW_ON_ERROR), 'read_capability' => $readCapability]);
            (new EventRecorder($this->db, $this->clock))->append($context, 'custom_field', $key, 'custom_field_created', $operation, ['type' => $type]); return $this->get($context, $key);
        }, fn ($result) => $this->get($context, $result['field_key']));
    }
    public function get(ExecutionContext $context, string $key): array
    {
        $this->authorize($context, 'custom_field.view'); if (!Str::isUuid($key)) { throw new DomainError('not_found'); } $query = $this->db->table('tm_custom_fields')->where('scope_key', $context->scope)->where('field_key', $key);
        $this->authorization->constrain($query, $context, 'custom_field', 'view'); $row = $query->first(); if (!$row) { throw new DomainError('not_found'); } if ($row->read_capability !== null) { $this->authorize($context, $row->read_capability); }
        return [...(array) $row, 'options' => json_decode($row->options, true, flags: JSON_THROW_ON_ERROR)];
    }
    public function archive(ExecutionContext $context, string $key, int $version, bool $archived, string $operation): array
    {
        $this->authorize($context, 'custom_field.manage');
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'custom_field_archive', 'key' => $key, 'version' => $version, 'archived' => $archived], function () use ($context, $key, $version, $archived, $operation) {
            ScopeGraphLock::acquire($this->db, $context); $field = $this->get($context, $key); if ($field['version'] !== $version) { throw new DomainError('version_conflict'); }
            $this->db->table('tm_custom_fields')->where('id', $field['id'])->update(['archived' => $archived, 'version' => $version + 1]); (new EventRecorder($this->db, $this->clock))->append($context, 'custom_field', $key, 'custom_field_archived', $operation, ['archived' => $archived]); return $this->get($context, $key);
        }, fn ($result) => $this->get($context, $result['field_key']));
    }
    public function forms(ExecutionContext $context, int $afterId = 0, int $limit = 100): array
    {
        $this->authorize($context, 'custom_field.view'); if ($afterId < 0 || $limit < 1 || $limit > 100) { throw new DomainError('pagination_invalid'); }
        $readable = []; foreach ($context->capabilities as $capability) { try { $this->authorization->authorize($context, $capability); $readable[] = $capability; } catch (DomainError) {} }
        $query = $this->db->table('tm_custom_fields')->where('scope_key', $context->scope)->where('archived', false)->where(fn ($q) => $q->whereNull('read_capability')->orWhereIn('read_capability', $readable)); $this->authorization->constrain($query, $context, 'custom_field', 'view'); return $query->where('id', '>', $afterId)->orderBy('id')->limit($limit)->get()->map(fn ($row) => [...(array) $row, 'options' => json_decode($row->options, true, flags: JSON_THROW_ON_ERROR)])->all();
    }
    public function set(ExecutionContext $context, string $taskKey, int $taskVersion, string $fieldKey, int $fieldVersion, mixed $value, string $operation): array
    {
        $this->authorize($context, 'custom_field.manage'); $field = $this->get($context, $fieldKey); $typed = $this->typed($context, $field, $value);
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'custom_field_set', 'task' => $taskKey, 'version' => $taskVersion, 'field' => $fieldKey, 'field_version' => $fieldVersion, 'value' => $typed], function () use ($context, $taskKey, $taskVersion, $fieldKey, $fieldVersion, $typed, $operation) {
            ScopeGraphLock::acquire($this->db, $context); $field = $this->get($context, $fieldKey); if ($field['version'] !== $fieldVersion) { throw new DomainError('version_conflict'); } if ($field['archived']) { throw new DomainError('custom_field_archived'); }
            $task = $this->tasks->authorizedQuery($context, 'edit')->where('public_key', $taskKey)->whereNull('deleted_at')->whereNull('archived_at')->lockForUpdate()->first(); if (!$task) { throw new DomainError('not_found'); } if ($task->version !== $taskVersion) { throw new DomainError('version_conflict'); }
            if ($field['type'] === 'actor_ref' && $typed['key_value'] !== null && !$this->tasks->authorizedQuery(new ExecutionContext($context->scope, $typed['key_value'], $context->correlationId, capabilities: ['task.view']))->where('id', $task->id)->exists()) { throw new DomainError('custom_actor_ineligible'); }
            $selection = $typed['selections']; unset($typed['selections']); $prior = $this->db->table('tm_custom_values')->where('field_id', $field['id'])->where('task_id', $task->id)->first();
            $this->db->table('tm_custom_values')->updateOrInsert(['field_id' => $field['id'], 'task_id' => $task->id], [...$typed, 'version' => ($prior->version ?? 0) + 1]);
            $this->db->table('tm_custom_selections')->where('field_id', $field['id'])->where('task_id', $task->id)->delete(); foreach ($selection as $option) { $this->db->table('tm_custom_selections')->insert(['field_id' => $field['id'], 'task_id' => $task->id, 'option_key' => $option]); }
            $this->db->table('tm_tasks')->where('id', $task->id)->increment('version'); (new EventRecorder($this->db, $this->clock))->append($context, 'task', $taskKey, 'custom_value_changed', $operation, ['field_key' => $fieldKey], $task->id); return $this->value($context, $taskKey, $fieldKey);
        }, function ($result) use ($context, $taskKey, $fieldKey) { $this->value($context, $taskKey, $fieldKey); });
    }
    private function typed(ExecutionContext $context, array $field, mixed $value): array
    {
        $result = ['text_value' => null,'number_scaled' => null,'bool_value' => null,'date_value' => null,'datetime_value' => null,'key_value' => null,'selections' => []]; if ($value === null) { return $result; }
        switch ($field['type']) {
            case 'number': $result['number_scaled'] = FieldValue::number($value); break;
            case 'date': if (!is_string($value)) { throw new DomainError('custom_value_invalid'); } LocalTime::date($value); $result['date_value'] = $value; break;
            case 'datetime': if (!$value instanceof \DateTimeImmutable) { throw new DomainError('custom_value_invalid'); } $result['datetime_value'] = LocalTime::stamp($value); break;
            case 'bool': if (!is_bool($value)) { throw new DomainError('custom_value_invalid'); } $result['bool_value'] = $value; break;
            case 'single_select': if (!is_string($value) || !in_array($value, $field['options'], true)) { throw new DomainError('custom_option_invalid'); } $result['key_value'] = $value; break;
            case 'multi_select': if (!is_array($value) || !array_is_list($value) || count($value) > 100 || array_filter($value, fn ($item) => !is_string($item)) || array_diff($value, $field['options'])) { throw new DomainError('custom_option_invalid'); } $result['selections'] = array_values(array_unique($value)); break;
            case 'actor_ref': if (!is_string($value) || strlen($value) > 120 || !$this->resolver->eligible($context, $value, 'custom_field')) { throw new DomainError('custom_actor_ineligible'); } $result['key_value'] = $value; break;
            case 'url': if (!is_string($value) || strlen($value) > 255 || !filter_var($value, FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($value, PHP_URL_SCHEME) ?? ''), ['https','http'], true)) { throw new DomainError('custom_url_invalid'); } $result['key_value'] = $value; break;
            default: if (!is_string($value) || mb_strlen($value) > ($field['type'] === 'long_text' ? 16000 : 1000)) { throw new DomainError('custom_value_invalid'); } $result['text_value'] = $value;
        }
        return $result;
    }
    public function value(ExecutionContext $context, string $taskKey, string $fieldKey): array
    {
        $task = $this->tasks->get($context, $taskKey); if ($task['deleted_at'] !== null) { throw new DomainError('not_found'); } $field = $this->get($context, $fieldKey); $row = $this->db->table('tm_custom_values')->where('field_id', $field['id'])->where('task_id', $task['id'])->first();
        $value = $row === null ? null : match ($field['type']) { 'number' => $row->number_scaled === null ? null : FieldValue::format($row->number_scaled), 'bool' => $row->bool_value === null ? null : (bool) $row->bool_value, 'date' => $row->date_value, 'datetime' => $row->datetime_value, 'text','long_text' => $row->text_value, 'multi_select' => $this->db->table('tm_custom_selections')->where('field_id', $field['id'])->where('task_id', $task['id'])->orderBy('option_key')->pluck('option_key')->all(), default => $row->key_value };
        return ['field_key' => $fieldKey, 'task_key' => $taskKey, 'type' => $field['type'], 'value' => $value, 'version' => $row->version ?? 0, 'task_version' => $task['version'], 'archived' => (bool) $field['archived']];
    }
    public function filter(Builder $query, ExecutionContext $context, string $fieldKey, string $operator, mixed $value): Builder
    {
        $field = $this->get($context, $fieldKey); if (!in_array($operator, ['=','!=','>','>=','<','<=','contains'], true)) { throw new DomainError('custom_filter_invalid'); } $typed = $this->typed($context, $field, $value);
        if ($field['type'] === 'multi_select') { if ($operator !== 'contains' || count($typed['selections']) !== 1) { throw new DomainError('custom_filter_invalid'); } return $query->whereIn('id', $this->db->table('tm_custom_selections')->where('field_id', $field['id'])->where('option_key', $typed['selections'][0])->select('task_id')); }
        $column = $this->column($field['type']); if ($operator === 'contains') { throw new DomainError('custom_filter_invalid'); }
        return $query->whereIn('id', $this->db->table('tm_custom_values')->where('field_id', $field['id'])->where($column, $operator, $typed[$column])->select('task_id'));
    }
    public function sortQuery(ExecutionContext $context, string $fieldKey): array
    {
        $field = $this->get($context, $fieldKey); $column = $this->column($field['type']); return [$field, $column, $this->db->table('tm_custom_values')->where('field_id', $field['id'])->whereColumn('task_id', 'tm_tasks.id')->select($column)];
    }
    private function column(string $type): string { return match ($type) { 'number' => 'number_scaled', 'date' => 'date_value', 'datetime' => 'datetime_value', 'bool' => 'bool_value', 'single_select','actor_ref','url' => 'key_value', default => throw new DomainError('custom_field_not_indexable') }; }
}
