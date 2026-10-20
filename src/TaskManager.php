<?php

namespace Karnoweb\TaskManager;

use Karnoweb\TaskManager\Workflow\CaseService;
use Karnoweb\TaskManager\Workflow\InstanceService;
use Karnoweb\TaskManager\Workflow\PipelineService;
use Karnoweb\TaskManager\Workflow\ProjectService;
use Karnoweb\TaskManager\Workflow\WorkflowService;

final class TaskManager
{
    public function __construct(private readonly TaskService $tasks, private readonly WorkflowService $workflows, private readonly PipelineService $pipelines,
        private readonly InstanceService $instances, private readonly ProjectService $projects, private readonly CaseService $cases,
        private readonly \Karnoweb\TaskManager\SLA\SlaService $sla, private readonly \Karnoweb\TaskManager\SLA\StagePolicyService $stagePolicies) {}
    public function tasks(): TaskService { return $this->tasks; }
    public function workflows(): WorkflowService { return $this->workflows; }
    public function pipelines(): PipelineService { return $this->pipelines; }
    public function instances(): InstanceService { return $this->instances; }
    public function projects(): ProjectService { return $this->projects; }
    public function cases(): CaseService { return $this->cases; }
    public function sla(): \Karnoweb\TaskManager\SLA\SlaService { return $this->sla; }
    public function stagePolicies(): \Karnoweb\TaskManager\SLA\StagePolicyService { return $this->stagePolicies; }
    public function __call(string $method, array $arguments): mixed
    {
        if (!is_callable([$this->tasks, $method])) { throw new \BadMethodCallException('Unknown task method'); }
        return $this->tasks->{$method}(...$arguments);
    }
}
