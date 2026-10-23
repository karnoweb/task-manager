<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\TaskManager;
use Karnoweb\TaskManager\Workflow\Definition;

/** Executable development example. Never use these fixed capabilities in an HTTP controller. */
final class TaskManagerExample extends Command
{
    protected $signature = 'task-manager:example {scenario=todo} {--actor=a} {--scope=one} {--operation=}';

    protected $description = 'Run an isolated-development Task Manager example';

    public function handle(TaskManager $manager): int
    {
        $scenario = $this->argument('scenario');
        $operation = $this->option('operation') ?: (string) Str::uuid();
        $context = new ExecutionContext($this->option('scope'), $this->option('actor'), 'developer-example', capabilities: [
            'task.create', 'task.view', 'task.edit', 'list.view', 'activity.view', 'workflow.manage', 'workflow.view', 'workflow.transition',
            'pipeline.manage', 'project.manage', 'project.edit', 'project.view', 'reminder.manage',
        ]);
        if ($scenario === 'todo') {
            $task = $manager->tasks()->create($context, 'Call supplier', $operation.':create');
            $task = $manager->tasks()->mutate($context, $task['public_key'], $task['version'], $operation.':complete', 'complete');
            $task = $manager->tasks()->mutate($context, $task['public_key'], $task['version'], $operation.':reopen', 'reopen');
            $this->line(json_encode(['task_key' => $task['public_key'], 'state' => $task['state'], 'project_id' => $task['project_id']], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        if ($scenario === 'workflow') {
            $definition = Definition::stateMachine(['open' => ['terminal' => 'none'], 'review' => ['terminal' => 'none'], 'closed' => ['terminal' => 'success']], 'open');
            $revision = $manager->workflows()->publish($context, 'demo'.substr(hash('sha256', $operation), 0, 12), 0, $operation.':workflow', $definition);
            $project = $manager->projects()->create($context, 'DEMO'.strtoupper(substr(hash('sha256', $operation), 0, 8)), 'Example project', $revision['id'], $operation.':project');
            $task = $manager->tasks()->create($context, 'Project task', $operation.':task');
            $preview = $manager->projects()->previewTaskMove($context, $task['public_key'], $project['project_key'], 'task', ['pending' => 'open']);
            $manager->projects()->moveTask($context, $preview, $operation.':project-move');
            $pipeline = $manager->pipelines()->create($context, 'Demo '.substr(hash('sha256', $operation), 0, 8), $revision['id'], ['open' => ['state' => 'open'], 'review' => ['state' => 'review'], 'closed' => ['state' => 'closed']], $operation.':pipeline', 'sequential');
            $instance = $manager->instances()->authorizedQuery($context)->where('subject_type', 'task')->where('subject_id', $task['public_key'])->where('slot_key', 'main')->first();
            $preview = $manager->instances()->previewMove($context, $instance->instance_key, $pipeline['pipeline_key'], ['open' => 'open']);
            $placed = $manager->instances()->moveToPipeline($context, $preview, $operation.':place');
            $result = $manager->instances()->transition($context, $placed['instance_key'], $placed['version'], $operation.':review', 'move.open.review');
            $this->line(json_encode(['project_key' => $project['project_key'], 'instance_key' => $result['instance_key'], 'state' => $result['current_state_key']], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        if ($scenario === 'reminder') {
            $when = $manager->reminders()->parseTime('2090-01-01 10:00:00', 'Asia/Tehran');
            $reminder = $manager->reminders()->createGeneral($context, 'Personal reminder', $when, 'Asia/Tehran', $operation.':reminder');
            $this->line(json_encode(['reminder_key' => $reminder['reminder_key'], 'task_id' => $reminder['task_id'], 'scheduled_at' => $reminder['scheduled_at']], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        $this->error('Use todo, workflow or reminder.');

        return self::INVALID;
    }
}
