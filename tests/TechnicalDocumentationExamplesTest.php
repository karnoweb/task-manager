<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\DTO\MutateTask;
use Karnoweb\TaskManager\DTO\TaskResult;
use Karnoweb\TaskManager\Facades\TaskManager;

/** Executes the PHP fences in the technical guides, without a copied implementation. */
final class TechnicalDocumentationExamplesTest extends PackageTestCase
{
    private const GUIDES = [
        'docs/README.md',
        'docs/01-getting-started/quick-start.md',
        'docs/03-basic-usage/tasks-projects.md',
        'docs/04-advanced/workflow.md',
        'docs/API-IMPROVEMENT-PROPOSALS.md',
    ];

    private function examples(): array
    {
        $examples = [];
        foreach (self::GUIDES as $guide) {
            $markdown = file_get_contents(dirname(__DIR__).'/'.$guide);
            preg_match_all('/<!-- example: ([a-z-]+) -->\s*```php\R(.*?)\R```/s', $markdown, $matches, PREG_SET_ORDER);
            self::assertSame(substr_count($markdown, '```php'), count($matches), 'Every PHP fence must have an executable example ID: '.$guide);
            foreach ($matches as $match) {
                self::assertArrayNotHasKey($match[1], $examples, 'Duplicate example ID');
                $examples[$match[1]] = $match[2];
            }
        }

        return $examples;
    }

    private function example(string $id): mixed
    {
        $examples = $this->examples();
        self::assertArrayHasKey($id, $examples);
        $path = tempnam(sys_get_temp_dir(), 'tm-docs-');
        try {
            file_put_contents($path, $examples[$id]);

            return require $path;
        } finally {
            unlink($path);
        }
    }

    private function user(string $id = '1'): GenericUser
    {
        return new GenericUser(['id' => $id]);
    }

    private function configure(): void
    {
        $this->example('configure-permission');
    }

    private function workflowContext(bool $canApprove = true): ExecutionContext
    {
        return new ExecutionContext('personal:1', '1', 'docs-validation', capabilities: [
            'task.create', 'task.view', 'task.edit', 'workflow.manage', 'workflow.view', 'workflow.transition',
            ...($canApprove ? ['document.approve'] : []),
        ]);
    }

    private function reviewForValidation(): array
    {
        $this->configure();
        $result = ($this->example('independent-workflow'))($this->user(), (string) Str::uuid());
        $context = $this->workflowContext();
        $started = TaskManager::instances()->start(
            $context, 'task', $result['current']->publicKey, $result['revision']['id'],
            (string) Str::uuid(), slot: 'validation',
        );
        $review = TaskManager::instances()->transition(
            $context, $started['instance_key'], $started['version'], (string) Str::uuid(), 'submit',
        );

        return [$context, $review, $result];
    }

    public function test_every_php_example_is_identified_and_runs_on_laravel_13(): void
    {
        self::assertStringStartsWith('13.', Application::VERSION);
        $ids = array_keys($this->examples());
        sort($ids);
        self::assertSame([
            'configure-permission', 'create-project-task', 'independent-workflow', 'manage-tasks', 'personal-todo',
        ], $ids);
        self::assertSame('sqlite', DB::connection()->getDriverName());
    }

    public function test_todo_fence_creates_reads_and_completes_without_project_or_pipeline(): void
    {
        $result = ($this->example('personal-todo'))($this->user(), (string) Str::uuid());
        self::assertInstanceOf(TaskResult::class, $result['created']);
        self::assertSame('pending', $result['created']->state);
        self::assertSame(1, $result['read']->version);
        self::assertSame($result['created']->publicKey, $result['completed']->publicKey);
        self::assertSame('done', $result['completed']->state);
        self::assertSame(2, $result['completed']->version);
        self::assertNotNull($result['completed']->completedAt);
        self::assertSame('personal:1', $result['completed']->scope);
        self::assertSame('1', $result['completed']->owner);
        self::assertSame(1, DB::table('tm_tasks')->count());
        self::assertNull(DB::table('tm_tasks')->value('project_id'));
        self::assertSame(0, DB::table('tm_projects')->count());
        self::assertSame(0, DB::table('tm_pipelines')->count());
        self::assertSame(1, DB::table('tm_lists')->where('is_inbox', true)->count());
        self::assertSame(1, DB::table('tm_workflow_definitions')->where('key', 'personal')->count());
        self::assertSame('closed', DB::table('tm_workflow_instances')->value('lifecycle'));
        self::assertSame(0, DB::table('tm_active_process_slots')->count());
        self::assertSame(1, DB::table('tm_instance_transitions')->count());
        self::assertSame(2, DB::table('tm_command_journal')->count());
        self::assertSame(2, DB::table('tm_audit_events')->count());
        self::assertSame(2, DB::table('tm_outbox')->count());
    }

    public function test_todo_replay_keeps_commands_and_checks_current_scope(): void
    {
        $run = $this->example('personal-todo');
        $operation = (string) Str::uuid();
        $first = $run($this->user(), $operation);
        $again = $run($this->user(), $operation);
        self::assertEquals($first['created'], $again['created']);
        self::assertEquals($first['completed'], $again['completed']);
        self::assertSame(2, $again['read']->version, 'An ordinary read returns current data, not the earlier snapshot');
        self::assertSame(1, DB::table('tm_tasks')->count());
        self::assertSame(1, DB::table('tm_instance_transitions')->count());
        $other = $run($this->user('2'), $operation);
        self::assertNotSame($first['created']->publicKey, $other['created']->publicKey);
        $foreign = new ExecutionContext('personal:2', '2', 'docs-other');
        $this->error('not_found', fn () => TaskManager::tasks()->get($foreign, $first['created']->publicKey));
        $this->error('not_found', fn () => TaskManager::tasks()->get(
            new ExecutionContext('personal:1', '2', 'docs-other-owner'), $first['created']->publicKey,
        ));
        $this->error('not_found', fn () => TaskManager::tasks()->get(
            new ExecutionContext('personal:2', '1', 'docs-other-scope'), $first['created']->publicKey,
        ));
    }

    public function test_stale_task_version_and_operation_conflict_have_actionable_recovery(): void
    {
        $operation = (string) Str::uuid();
        $result = ($this->example('personal-todo'))($this->user(), $operation);
        $context = new ExecutionContext('personal:1', '1', 'docs-recovery');
        $id = $result['created']->publicKey;
        $this->error('version_conflict', fn () => TaskManager::tasks()->change(new MutateTask(
            $context, $id, 1, (string) Str::uuid(), 'reopen',
        )));
        $this->error('idempotency_key_conflict', fn () => TaskManager::tasks()->create(
            $context, 'محتوای متفاوت', $operation.':create',
        ));
        $current = TaskManager::tasks()->inContext($context)->get($id);
        $reopened = TaskManager::tasks()->change(new MutateTask(
            $context, $id, $current->version, (string) Str::uuid(), 'reopen',
        ));
        self::assertSame('pending', $reopened->state);
        self::assertSame(3, $reopened->version);
    }

    public function test_task_management_fence_preserves_versions_fields_and_listing(): void
    {
        $run = $this->example('manage-tasks');
        $operation = (string) Str::uuid();
        $result = $run($this->user(), $operation);
        foreach (['updated' => 2, 'done' => 3, 'reopened' => 4, 'archived' => 5, 'restored' => 6] as $key => $version) {
            self::assertInstanceOf(TaskResult::class, $result[$key]);
            self::assertSame($version, $result[$key]->version);
        }
        self::assertSame('done', $result['done']->state);
        self::assertSame('pending', $result['restored']->state);
        self::assertCount(1, $result['rows']);
        self::assertSame(6, $result['rows'][0]->version);
        self::assertSame('high', DB::table('tm_tasks')->value('priority'));
        self::assertSame('تماس با مشتری', DB::table('tm_tasks')->value('description'));
        self::assertNull(DB::table('tm_tasks')->value('archived_at'));
        self::assertSame(1, DB::table('tm_active_process_slots')->count());
        $again = $run($this->user(), $operation);
        self::assertEquals($result['restored'], $again['restored']);
        self::assertSame(1, DB::table('tm_tasks')->count());
    }

    public function test_project_fence_moves_the_same_task_and_main_instance_and_replays_saved_preview(): void
    {
        $this->configure();
        $run = $this->example('create-project-task');
        $operation = (string) Str::uuid();
        $result = $run($this->user(), $operation);
        self::assertSame(1, $result['revision']['revision']);
        self::assertSame('pending', $result['personal']['state']);
        self::assertNull($result['personal']['project_id']);
        self::assertSame($result['personal']['public_key'], $result['task']['public_key']);
        self::assertSame($result['project']['id'], $result['task']['project_id']);
        self::assertSame('open', $result['task']['state']);
        self::assertSame(2, $result['task']['version']);
        self::assertSame(1, DB::table('tm_tasks')->count());
        self::assertSame(1, DB::table('tm_workflow_instances')->count());
        self::assertSame($result['revision']['id'], DB::table('tm_workflow_instances')->value('workflow_revision_id'));
        self::assertSame('manager', DB::table('tm_project_members')->value('role'));
        self::assertSame(2, DB::table('tm_task_key_aliases')->count());
        self::assertSame(0, DB::table('tm_pipelines')->count());
        $again = $run($this->user(), $operation, $result['preview']);
        self::assertSame($result['task'], $again['task']);
        self::assertSame(1, DB::table('tm_projects')->count());
        self::assertSame(1, DB::table('tm_tasks')->count());
        $this->error('workflow_command_required', fn () => TaskManager::tasks()->inContext(
            new ExecutionContext('personal:1', '1', 'docs-project'),
        )->complete($result['task']['public_key'], $result['task']['version'], (string) Str::uuid()));
    }

    public function test_stale_project_preview_is_rejected_and_can_be_rebuilt(): void
    {
        $this->configure();
        $result = ($this->example('create-project-task'))($this->user(), (string) Str::uuid());
        $context = new ExecutionContext('personal:1', '1', 'docs-preview', capabilities: [
            'task.view', 'task.edit', 'workflow.view', 'project.view', 'project.edit', 'project.manage',
        ]);
        $this->error('preview_stale', fn () => TaskManager::projects()->moveTask(
            $context, $result['preview'], (string) Str::uuid(),
        ));
        $preview = TaskManager::projects()->previewTaskMove(
            $context, $result['task']['public_key'], $result['project']['project_key'], 'task', ['open' => 'open'],
        );
        $fresh = TaskManager::projects()->moveTask($context, $preview, (string) Str::uuid());
        self::assertSame('open', $fresh['state']);
        self::assertSame(3, $fresh['version']);
    }

    public function test_configuration_gate_denies_other_users_before_writing(): void
    {
        $this->configure();
        self::assertTrue(Gate::forUser($this->user())->allows('task-manager.docs.configure'));
        self::assertFalse(Gate::forUser($this->user('2'))->allows('task-manager.docs.configure'));
        foreach (['create-project-task', 'independent-workflow'] as $id) {
            try {
                ($this->example($id))($this->user('2'), (string) Str::uuid());
                self::fail('The host gate must deny this user');
            } catch (AuthorizationException) {
                self::assertSame(0, DB::table('tm_tasks')->count());
                self::assertSame(0, DB::table('tm_workflow_definitions')->count());
            }
        }
    }

    public function test_example_inputs_reject_invalid_operation_ids_before_writing(): void
    {
        $this->configure();
        foreach (['personal-todo', 'manage-tasks', 'create-project-task', 'independent-workflow'] as $id) {
            try {
                ($this->example($id))($this->user(), 'not-a-uuid');
                self::fail('The example must reject an invalid root operation ID');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('operation must be a UUID', $error->getMessage());
                self::assertSame(0, DB::table('tm_command_journal')->count());
            }
        }
    }

    public function test_project_main_instance_can_be_completed_after_a_task_only_edit(): void
    {
        $this->configure();
        $result = ($this->example('create-project-task'))($this->user(), (string) Str::uuid());
        $context = $this->workflowContext();
        $task = TaskManager::tasks()->change(new MutateTask(
            $context, $result['task']['public_key'], $result['task']['version'],
            (string) Str::uuid(), 'update', ['priority' => 'high'],
        ));
        $instance = TaskManager::instances()->authorizedQuery($context)
            ->where('subject_type', 'task')->where('subject_id', $task->publicKey)
            ->where('slot_key', 'main')->first();
        self::assertNotSame($task->version, $instance->version);
        $finished = TaskManager::instances()->transition(
            $context, $instance->instance_key, $instance->version, (string) Str::uuid(), 'finish',
        );
        self::assertSame('done', $finished['current_state_key']);
        self::assertSame('closed', $finished['lifecycle']);
        self::assertSame('done', TaskManager::tasks()->get($context, $task->publicKey)['state']);
        self::assertSame(4, TaskManager::tasks()->get($context, $task->publicKey)['version']);
    }

    public function test_workflow_fence_runs_independently_and_distinguishes_task_and_instance_versions(): void
    {
        $this->configure();
        $run = $this->example('independent-workflow');
        $operation = (string) Str::uuid();
        $result = $run($this->user(), $operation);
        self::assertSame(2, $result['edited']->version);
        self::assertSame(1, $result['started']['version']);
        self::assertSame('draft', $result['started']['current_state_key']);
        self::assertSame([['transition' => 'submit', 'state' => 'review', 'requires_validation' => true]], $result['available']);
        self::assertSame(2, $result['submitted']['version']);
        self::assertSame('review', $result['submitted']['current_state_key']);
        self::assertSame(3, $result['approved']['version']);
        self::assertSame('approved', $result['approved']['current_state_key']);
        self::assertSame('closed', $result['approved']['lifecycle']);
        self::assertSame(5, $result['current']->version);
        self::assertSame('pending', $result['current']->state);
        self::assertSame(2, DB::table('tm_workflow_instances')->count());
        self::assertSame(2, DB::table('tm_instance_transitions')->count());
        self::assertSame(1, DB::table('tm_active_process_slots')->where('slot_key', 'main')->count());
        self::assertSame(0, DB::table('tm_active_process_slots')->where('slot_key', 'review')->count());
        self::assertSame(0, DB::table('tm_projects')->count());
        self::assertSame(0, DB::table('tm_pipelines')->count());
        $again = $run($this->user(), $operation);
        self::assertSame($result['approved'], $again['approved']);
        self::assertSame(2, DB::table('tm_workflow_instances')->count());
        self::assertSame(2, DB::table('tm_instance_transitions')->count());
    }

    public function test_business_command_requires_its_route_capability_payload_and_reason(): void
    {
        [$context, $review] = $this->reviewForValidation();
        $key = $review['instance_key'];
        $version = $review['version'];
        $this->error('business_command_required', fn () => TaskManager::instances()->transition(
            $context, $key, $version, (string) Str::uuid(), 'approve',
        ));
        $this->error('capability_denied', fn () => TaskManager::instances()->executeBusinessCommand(
            $this->workflowContext(false), $key, $version, (string) Str::uuid(), 'approve_result',
            ['result' => 'تأیید شد', 'reason_code' => 'approved'],
        ));
        foreach ([
            'required_field_missing' => ['reason_code' => 'approved'],
            'reason_required' => ['result' => 'تأیید شد'],
            'reason_invalid' => ['result' => 'تأیید شد', 'reason_code' => 'other'],
        ] as $error => $payload) {
            $this->error($error, fn () => TaskManager::instances()->executeBusinessCommand(
                $context, $key, $version, (string) Str::uuid(), 'approve_result', $payload,
            ));
            self::assertSame($version, TaskManager::instances()->get($context, $key)['version']);
        }
        $done = TaskManager::instances()->executeBusinessCommand(
            $context, $key, $version, (string) Str::uuid(), 'approve_result',
            ['result' => 'تأیید شد', 'reason_code' => 'approved'],
        );
        self::assertSame('approved', $done['current_state_key']);
    }

    public function test_workflow_rejects_task_version_and_duplicate_main_slot(): void
    {
        [$context, $review, $result] = $this->reviewForValidation();
        $task = TaskManager::tasks()->get($context, $result['current']->publicKey);
        self::assertNotSame($task['version'], $review['version']);
        $this->error('version_conflict', fn () => TaskManager::instances()->executeBusinessCommand(
            $context, $review['instance_key'], $task['version'], (string) Str::uuid(), 'approve_result',
            ['result' => 'تأیید شد', 'reason_code' => 'approved'],
        ));
        $this->error('active_slot_conflict', fn () => TaskManager::instances()->start(
            $context, 'task', $task['public_key'], $result['revision']['id'], (string) Str::uuid(),
        ));
    }

    public function test_new_markdown_links_and_heading_fragments_resolve(): void
    {
        $root = dirname(__DIR__);
        $checked = 0;
        foreach (self::GUIDES as $guide) {
            $source = $root.'/'.$guide;
            $markdown = file_get_contents($source);
            preg_match_all('/\[[^\]]+\]\(([^)]+)\)/u', $markdown, $links);
            foreach ($links[1] as $target) {
                self::assertDoesNotMatchRegularExpression('/^https?:/i', $target, 'New guides currently use only local source links');
                [$path, $fragment] = array_pad(explode('#', $target, 2), 2, null);
                $resolved = realpath(dirname($source).'/'.$path);
                self::assertNotFalse($resolved, $guide.' -> '.$target);
                self::assertFileExists($resolved);
                if ($fragment !== null && str_ends_with($resolved, '.md')) {
                    preg_match_all('/^#+\s+(.+)$/mu', file_get_contents($resolved), $headings);
                    $slugs = array_map(static function (string $heading): string {
                        $heading = mb_strtolower(strip_tags($heading));
                        $heading = preg_replace('/[^\p{L}\p{N}_\-\s]/u', '', $heading);

                        return preg_replace('/\s/u', '-', trim($heading));
                    }, $headings[1]);
                    self::assertContains($fragment, $slugs, $guide.' -> '.$target);
                }
                $checked++;
            }
        }
        self::assertGreaterThan(20, $checked);
    }
}
