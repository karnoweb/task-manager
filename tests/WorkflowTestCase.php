<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\SubjectRegistry;
use Karnoweb\TaskManager\Tests\Support\HostSubjectHandler;
use Karnoweb\TaskManager\Workflow\CaseService;
use Karnoweb\TaskManager\Workflow\InstanceService;
use Karnoweb\TaskManager\Workflow\PipelineService;
use Karnoweb\TaskManager\Workflow\ProjectService;
use Karnoweb\TaskManager\Workflow\TaskSubjectHandler;
use Karnoweb\TaskManager\Workflow\WorkflowService;

abstract class WorkflowTestCase extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('host_subjects', function (Blueprint $t) {
            $t->string('id', 120)->primary();
            $t->string('scope_key', 120);
            $t->string('type', 100);
            $t->string('title');
            $t->boolean('veto')->default(false);
            $t->string('legacy_status')->nullable();
            $t->unsignedInteger('guard_touches')->default(0);
            $t->unsignedInteger('amount')->default(100);
            $t->text('private_text')->nullable();
        });
        Schema::create('host_permissions', function (Blueprint $t) {
            $t->string('subject_id', 120);
            $t->string('actor', 120);
            $t->boolean('can_edit')->default(true);
            $t->primary(['subject_id', 'actor']);
        });
        $this->app->bind(SubjectRegistry::class, fn () => new SubjectRegistry([
            'task' => new TaskSubjectHandler($this->service()), 'crm.lead' => new HostSubjectHandler(DB::connection(), 'crm.lead'),
            'support.ticket' => new HostSubjectHandler(DB::connection(), 'support.ticket'), 'crm.opportunity' => new HostSubjectHandler(DB::connection(), 'crm.opportunity'),
        ]));
    }

    protected function manager(string $actor = 'a', string $scope = 'one'): ExecutionContext
    {
        return new ExecutionContext($scope, $actor, 'workflow-test', capabilities: [...$this->context()->capabilities,
            'workflow.manage', 'workflow.view', 'workflow.transition', 'pipeline.manage', 'project.manage', 'project.edit', 'project.view', 'case.manage']);
    }

    protected function workflows(): WorkflowService
    {
        return $this->app->make(WorkflowService::class);
    }

    protected function pipelines(): PipelineService
    {
        return $this->app->make(PipelineService::class);
    }

    protected function instances(): InstanceService
    {
        return $this->app->make(InstanceService::class);
    }

    protected function projects(): ProjectService
    {
        return $this->app->make(ProjectService::class);
    }

    protected function cases(): CaseService
    {
        return $this->app->make(CaseService::class);
    }

    protected function definition(): array
    {
        return ['initial' => 'new', 'states' => ['new' => ['terminal' => 'none'], 'review' => ['terminal' => 'none'], 'won' => ['terminal' => 'success']],
            'transitions' => ['review' => ['from' => 'new', 'to' => 'review'], 'approve' => ['from' => 'review', 'to' => 'won', 'reason_required' => true, 'reason_codes' => ['approved']],
                'return' => ['from' => 'review', 'to' => 'new'], 'reopen' => ['from' => 'won', 'to' => 'new'], 'note' => ['from' => 'new', 'to' => 'new', 'required_fields' => ['note']]]];
    }

    protected function sales(string $operation = 'workflow'): array
    {
        return $this->workflows()->publish($this->manager(), 'sales', 0, $operation, $this->definition());
    }

    protected function pipeline(int $revisionId, ?int $capacity = null, string $name = 'Sales'): array
    {
        return $this->pipelines()->create($this->manager(), $name, $revisionId, ['inbox' => ['state' => 'new'], 'review' => ['state' => 'review', 'capacity' => $capacity], 'won' => ['state' => 'won']], 'pipeline-'.$name);
    }

    protected function host(string $id = 'lead-1', string $type = 'crm.lead', string $scope = 'one', array $actors = ['a']): void
    {
        DB::table('host_subjects')->insert(['id' => $id, 'scope_key' => $scope, 'type' => $type, 'title' => 'Host-owned', 'private_text' => 'PRIVATE-HOST-TEXT']);
        foreach ($actors as $actor) {
            DB::table('host_permissions')->insert(['subject_id' => $id, 'actor' => $actor, 'can_edit' => true]);
        }
    }

    protected function projectTask(): array
    {
        $revision = $this->sales();
        $project = $this->projects()->create($this->manager(), 'APP', 'Application', $revision['id'], 'project');
        $task = $this->service()->create($this->manager(), 'Task', 'task');
        $preview = $this->projects()->previewTaskMove($this->manager(), $task['public_key'], $project['project_key'], 'task', ['pending' => 'new']);
        $converted = $this->projects()->moveTask($this->manager(), $preview, 'convert');

        return [$revision, $project, $converted];
    }
}
