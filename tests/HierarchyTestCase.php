<?php

namespace Karnoweb\TaskManager\Tests;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\TaskGraph\HierarchyService;

abstract class HierarchyTestCase extends WorkflowTestCase
{
    protected function manager(string $actor = 'a', string $scope = 'one'): ExecutionContext
    {
        $base = parent::manager($actor, $scope);

        return new ExecutionContext($scope, $actor, 'hierarchy-test', capabilities: [...$base->capabilities, 'task.reparent', 'task.delete']);
    }

    protected function hierarchy(): HierarchyService
    {
        return $this->app->make(HierarchyService::class);
    }

    protected function todo(string $title): array
    {
        return $this->service()->create($this->manager(), $title, 'create-'.$title);
    }

    protected function attach(array $child, array $parent, string $operation): array
    {
        $c = $this->manager();
        $child = $this->service()->get($c, $child['public_key']);
        $parent = $this->service()->get($c, $parent['public_key']);

        return $this->hierarchy()->reparent($c, $child['public_key'], $child['version'], $parent['public_key'], $parent['version'], $operation);
    }

    protected function inProject(array $project, string $title, string $type = 'task'): array
    {
        $task = $this->todo($title);
        $preview = $this->projects()->previewTaskMove($this->manager(), $task['public_key'], $project['project_key'], $type, ['pending' => 'new']);

        return $this->projects()->moveTask($this->manager(), $preview, 'project-'.$title);
    }
}
