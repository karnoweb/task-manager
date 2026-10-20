<?php

namespace Karnoweb\TaskManager\Planning;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\TaskGraph\HierarchyService;
use Karnoweb\TaskManager\TaskGraph\ScopeGraphLock;
use Karnoweb\TaskManager\TaskGraph\TaskPlanningService;
use Karnoweb\TaskManager\TaskService;
use Karnoweb\TaskManager\Workflow\Definition;
use Karnoweb\TaskManager\Workflow\ProjectService;
use Karnoweb\TaskManager\Workflow\WorkflowService;

final class TemplateService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization,
        private readonly TaskService $tasks, private readonly ProjectService $projects, private readonly WorkflowService $workflows,
        private readonly HierarchyService $hierarchy, private readonly TaskPlanningService $planning) {}
    private function authorize(ExecutionContext $context): void { $context->require('template.manage'); $this->authorization->authorize($context, 'template.manage'); }
    public function get(ExecutionContext $context, string $key, ?int $revision = null): array
    {
        $this->authorize($context); if (!Str::isUuid($key)) { throw new DomainError('not_found'); }
        $head = $this->db->table('tm_project_templates')->where('scope_key', $context->scope)->where('owner_ref', $context->actor)->where('template_key', $key)->first();
        if (!$head) { throw new DomainError('not_found'); }
        $row = $this->db->table('tm_project_template_revisions')->where('template_id', $head->id)->where('revision', $revision ?? $head->version)->first(); if (!$row) { throw new DomainError('not_found'); }
        return [...(array) $head, 'revision_id' => $row->id, 'revision' => $row->revision, 'definition' => json_decode($row->definition, true, 512, JSON_THROW_ON_ERROR), 'definition_hash' => $row->definition_hash];
    }
    public function publish(ExecutionContext $context, ?string $key, int $version, string $name, array $definition, string $operation): array
    {
        $this->authorize($context); if (trim($name) === '' || mb_strlen($name) > 255 || ($key === null && $version !== 0)) { throw new DomainError('template_invalid'); }
        $definition = $this->validate($context, $definition);
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'template_publish', 'key' => $key, 'version' => $version, 'name' => $name, 'definition' => $definition], function () use ($context, $key, $version, $name, $definition, $operation) {
            ScopeGraphLock::acquire($this->db, $context); $public = $key ?? (string) Str::uuid();
            if ($key === null) { $id = $this->db->table('tm_project_templates')->insertGetId(['template_key' => $public, 'scope_key' => $context->scope, 'owner_ref' => $context->actor, 'name' => $name]); }
            else { $head = $this->get($context, $key); if ($head['version'] !== $version) { throw new DomainError('version_conflict'); } $id = $head['id']; $this->db->table('tm_project_templates')->where('id', $id)->update(['version' => $version + 1, 'name' => $name]); }
            $encoded = json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $this->db->table('tm_project_template_revisions')->insert(['template_id' => $id, 'revision' => $version + 1, 'definition' => $encoded, 'definition_hash' => hash('sha256', $encoded), 'created_at' => $this->clock->now()->format('Y-m-d H:i:s.u')]);
            (new EventRecorder($this->db, $this->clock))->append($context, 'project_template', $public, 'template_published', $operation, ['revision' => $version + 1]); return $this->get($context, $public);
        }, fn ($result) => $this->get($context, $result['template_key'], $result['revision']));
    }
    private function validate(ExecutionContext $context, array $definition): array
    {
        if (array_diff(array_keys($definition), ['workflow_revision_id', 'type_workflows', 'tasks', 'max_depth']) || !is_int($definition['workflow_revision_id'] ?? null)) { throw new DomainError('template_invalid'); }
        $this->workflows->revision($context, $definition['workflow_revision_id']); $types = $definition['type_workflows'] ?? []; $items = $definition['tasks'] ?? []; $depth = $definition['max_depth'] ?? 3;
        if (!is_array($types) || count($types) > 30 || !is_array($items) || !array_is_list($items) || count($items) > 100 || !is_int($depth) || $depth < 1 || $depth > 8) { throw new DomainError('template_invalid'); }
        foreach ($types as $type => $revision) { Definition::key($type); if (!is_int($revision)) { throw new DomainError('template_invalid'); } $this->workflows->revision($context, $revision); }
        $aliases = []; foreach ($items as $item) {
            if (!is_array($item) || array_diff(array_keys($item), ['alias', 'title', 'type', 'parent', 'estimate_minutes', 'story_points']) || !is_string($item['title'] ?? null) || trim($item['title']) === '' || mb_strlen($item['title']) > 255) { throw new DomainError('template_invalid'); }
            Definition::key($item['alias'] ?? null); Definition::key($item['type'] ?? 'task'); if (isset($aliases[$item['alias']])) { throw new DomainError('template_alias_duplicate'); }
            if (isset($item['estimate_minutes']) && (!is_int($item['estimate_minutes']) || $item['estimate_minutes'] < 0 || $item['estimate_minutes'] > 5256000)) { throw new DomainError('template_invalid'); }
            if (isset($item['story_points'])) { if (!is_string($item['story_points'])) { throw new DomainError('template_invalid'); } PointValue::minor($item['story_points']); }
            $aliases[$item['alias']] = $item;
        }
        foreach ($aliases as $alias => $item) {
            $seen = [$alias => true]; $parent = $item['parent'] ?? null; $distance = 0;
            while ($parent !== null) { if (!is_string($parent) || !isset($aliases[$parent])) { throw new DomainError('template_parent_missing'); } if (isset($seen[$parent])) { throw new DomainError('task_hierarchy_cycle'); } $seen[$parent] = true; if (++$distance > $depth) { throw new DomainError('task_depth_exceeded'); } $parent = $aliases[$parent]['parent'] ?? null; }
        }
        return ['workflow_revision_id' => $definition['workflow_revision_id'], 'type_workflows' => $types, 'tasks' => $items, 'max_depth' => $depth];
    }
    public function instantiate(ExecutionContext $context, string $key, int $revision, string $projectCode, string $name, string $operation): array
    {
        $template = $this->get($context, $key, $revision);
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'template_instantiate', 'key' => $key, 'revision' => $revision, 'project_code' => $projectCode, 'name' => $name], function () use ($context, $template, $projectCode, $name, $operation) {
            ScopeGraphLock::acquire($this->db, $context); $definition = $this->validate($context, $template['definition']);
            $child = fn (string $step) => 'template:'.hash('sha256', $operation.':'.$step);
            $project = $this->projects->create($context, $projectCode, $name, $definition['workflow_revision_id'], $child('project'));
            foreach ($definition['type_workflows'] as $type => $id) { $project = $this->projects->change($context, $project['project_key'], $project['version'], $child('type:'.$type), 'type_workflow', ['type' => $type, 'revision' => $id]); }
            $project = $this->hierarchy->configureDepth($context, $project['project_key'], $project['version'], $definition['max_depth'], $child('depth')); $created = [];
            foreach ($definition['tasks'] as $item) {
                $task = $this->tasks->create($context, $item['title'], $child('create:'.$item['alias'])); $type = $item['type'] ?? 'task';
                $target = $this->workflows->revision($context, $definition['type_workflows'][$type] ?? $definition['workflow_revision_id'])['definition']['initial'];
                $preview = $this->projects->previewTaskMove($context, $task['public_key'], $project['project_key'], $type, [$task['state'] => $target]);
                $task = $this->projects->moveTask($context, $preview, $child('move:'.$item['alias']));
                if (isset($item['estimate_minutes']) || isset($item['story_points'])) { $task = $this->planning->estimate($context, $task['public_key'], $task['version'], $item['estimate_minutes'] ?? null, $item['story_points'] ?? null, $child('estimate:'.$item['alias'])); }
                $created[$item['alias']] = $task;
            }
            // Attach in ancestor-first order so the configured depth is checked against final paths.
            $pending = $definition['tasks']; $attached = [];
            while ($pending !== []) { foreach ($pending as $index => $item) {
                $parent = $item['parent'] ?? null; if ($parent !== null && !isset($attached[$parent])) { continue; }
                if ($parent !== null) { $task = $this->tasks->get($context, $created[$item['alias']]['public_key']); $ancestor = $this->tasks->get($context, $created[$parent]['public_key']); $this->hierarchy->reparent($context, $task['public_key'], $task['version'], $ancestor['public_key'], $ancestor['version'], $child('parent:'.$item['alias'])); }
                $attached[$item['alias']] = true; unset($pending[$index]);
            } }
            $this->db->table('tm_project_template_instances')->insert(['project_id' => $project['id'], 'template_revision_id' => $template['revision_id']]);
            (new EventRecorder($this->db, $this->clock))->append($context, 'project', $project['project_key'], 'project_template_instantiated', $operation, ['template_key' => $template['template_key'], 'revision' => $template['revision']]);
            return ['project' => $this->projects->get($context, $project['project_key']), 'tasks' => array_map(fn ($task) => $task['public_key'], $created), 'template_revision' => $template['revision']];
        }, function ($result) use ($context, $key, $revision) { $this->get($context, $key, $revision); $this->projects->get($context, $result['project']['project_key']); foreach ($result['tasks'] as $task) { $this->tasks->get($context, $task); } });
    }
}
