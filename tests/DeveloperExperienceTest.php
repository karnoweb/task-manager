<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\ContextAuthorization;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\Contracts\StageLifecycle;
use Karnoweb\TaskManager\DTO\TaskQuery;
use Karnoweb\TaskManager\Enterprise\QueryService;
use Karnoweb\TaskManager\Facades\TaskManager;
use Karnoweb\TaskManager\Scheduling\ReminderService;
use Karnoweb\TaskManager\StageLifecycleChain;
use Karnoweb\TaskManager\SubjectRegistry;
use Karnoweb\TaskManager\TaskGraph\TaskActualClock;
use Karnoweb\TaskManager\Workflow\InstanceService;
use Karnoweb\TaskManager\Workflow\TransitionGuards;

final class DeveloperExperienceTest extends WorkflowTestCase
{
    private function project(): array
    {
        $revision = $this->sales();

        return $this->projects()->create($this->manager(), 'DX', 'Developer experience', $revision['id'], 'project');
    }

    public function test_direct_creation_is_atomic_idempotent_and_has_no_personal_task_or_revision(): void
    {
        $project = $this->project();
        $c = $this->manager();
        $task = TaskManager::projects()->createTask($c, $project['project_key'], 'bug', 'Fix login', 'direct');
        self::assertSame('new', $task['state']);
        self::assertSame(1, $task['version']);
        self::assertSame('DX-1', $task['display_key']);
        self::assertSame('bug', $task['type_key']);
        self::assertSame($project['id'], $task['project_id']);
        self::assertSame(1, DB::table('tm_tasks')->count());
        self::assertSame(1, DB::table('tm_workflow_instances')->count());
        self::assertSame(1, DB::table('tm_workflow_instances')->value('version'));
        self::assertSame(0, DB::table('tm_workflow_definitions')->where('key', 'personal')->count());
        self::assertSame(0, DB::table('tm_task_key_aliases')->where('display_key', 'like', 'TM-%')->count());
        self::assertSame(1, DB::table('tm_outbox')->where('action', 'created')->count());
        self::assertSame($task, TaskManager::projects()->createTask($c, $project['project_key'], 'bug', 'Fix login', 'direct'));
        self::assertSame(1, DB::table('tm_tasks')->count());
        $this->error('idempotency_key_conflict', fn () => TaskManager::projects()->createTask($c, $project['project_key'], 'bug', 'Different', 'direct'));
    }

    public function test_project_type_workflow_and_initial_in_progress_are_honored(): void
    {
        $project = $this->project();
        $revision = $this->workflows()->publish($this->manager(), 'bugs', 0, 'bugs', [
            'initial' => 'working', 'states' => ['working' => ['category' => 'in_progress', 'terminal' => 'none']], 'transitions' => [],
        ]);
        $this->projects()->change($this->manager(), $project['project_key'], 1, 'type', 'type_workflow', ['type' => 'bug', 'revision' => $revision['id']]);
        $task = $this->projects()->createTask($this->manager(), $project['project_key'], 'bug', 'In progress', 'direct');
        self::assertSame('working', $task['state']);
        self::assertSame($revision['id'], DB::table('tm_workflow_instances')->value('workflow_revision_id'));
        self::assertNotNull(TaskActualClock::current(DB::connection(), 'one', $task['id'])['started_at']);
        self::assertSame('', DB::table('tm_instance_transitions')->value('from_state'));
    }

    public function test_direct_creation_rejects_cross_scope_membership_and_revoked_replay(): void
    {
        $project = $this->project();
        foreach (['member', 'viewer'] as $role) {
            DB::table('tm_project_members')->insert(['project_id' => $project['id'], 'actor_ref' => $role, 'role' => $role]);
            $this->error('not_found', fn () => $this->projects()->createTask($this->manager($role), $project['project_key'], 'task', 'Denied', 'role-'.$role));
        }
        $this->error('not_found', fn () => $this->projects()->createTask($this->manager('a', 'other'), $project['project_key'], 'task', 'Denied', 'scope'));
        $this->error('capability_denied', fn () => $this->projects()->createTask($this->context(), $project['project_key'], 'task', 'Denied', 'caps'));
        $this->projects()->createTask($this->manager(), $project['project_key'], 'task', 'Allowed', 'direct');
        DB::table('tm_project_members')->where('project_id', $project['id'])->where('actor_ref', 'a')->update(['role' => 'member']);
        $this->error('not_found', fn () => $this->projects()->createTask($this->manager(), $project['project_key'], 'task', 'Allowed', 'direct'));
        self::assertSame(1, DB::table('tm_tasks')->count());
    }

    public function test_invalid_type_title_archived_project_and_terminal_initial_state_create_nothing(): void
    {
        $project = $this->project();
        $this->error('key_invalid', fn () => $this->projects()->createTask($this->manager(), $project['project_key'], 'Invalid type', 'Title', 'type'));
        $this->error('title_invalid', fn () => $this->projects()->createTask($this->manager(), $project['project_key'], 'task', ' ', 'title'));
        $this->projects()->change($this->manager(), $project['project_key'], 1, 'archive', 'archive', []);
        $this->error('project_archived', fn () => $this->projects()->createTask($this->manager(), $project['project_key'], 'task', 'Title', 'archived'));
        $revision = $this->workflows()->publish($this->manager(), 'terminal', 0, 'terminal', [
            'initial' => 'done', 'states' => ['done' => ['terminal' => 'success']], 'transitions' => [],
        ]);
        $other = $this->projects()->create($this->manager(), 'DONE', 'Terminal', $revision['id'], 'other');
        $this->error('initial_state_terminal', fn () => $this->projects()->createTask($this->manager(), $other['project_key'], 'task', 'Title', 'initial'));
        self::assertSame(0, DB::table('tm_tasks')->count());
        self::assertSame(0, DB::table('tm_workflow_instances')->count());
    }

    public function test_host_edit_constraint_rolls_back_every_creation_write(): void
    {
        $project = $this->project();
        $before = [];
        $tables = ['tm_tasks', 'tm_lists', 'tm_sequences', 'tm_workflow_instances', 'tm_active_process_slots', 'tm_task_key_aliases', 'tm_task_closure', 'tm_command_journal', 'tm_outbox'];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->count();
        }
        $this->app->instance(AuthorizationProvider::class, new class implements AuthorizationProvider
        {
            public function authorize(ExecutionContext $context, string $capability): void
            {
                $context->require($capability);
            }

            public function constrain(Builder $query, ExecutionContext $context, string $resource, string $capability): void
            {
                if ($resource === 'task' && $capability === 'edit') {
                    $query->whereRaw('1 = 0');
                }
            }
        });
        $this->error('not_found', fn () => $this->projects()->createTask($this->manager(), $project['project_key'], 'task', 'Denied', 'direct'));
        foreach ($before as $table => $count) {
            self::assertSame($count, DB::table($table)->count(), $table);
        }
    }

    public function test_host_project_edit_constraint_is_checked_on_creation_and_replay(): void
    {
        $project = $this->project();
        $this->projects()->createTask($this->manager(), $project['project_key'], 'task', 'Allowed', 'direct');
        $this->app->instance(AuthorizationProvider::class, new class implements AuthorizationProvider
        {
            public function authorize(ExecutionContext $context, string $capability): void
            {
                $context->require($capability);
            }

            public function constrain(Builder $query, ExecutionContext $context, string $resource, string $capability): void
            {
                if ($resource === 'project' && $capability === 'edit') {
                    $query->whereRaw('1 = 0');
                }
            }
        });
        $this->error('not_found', fn () => $this->projects()->createTask($this->manager(), $project['project_key'], 'task', 'Allowed', 'direct'));
        $this->error('not_found', fn () => $this->projects()->createTask($this->manager(), $project['project_key'], 'task', 'Denied', 'new'));
        self::assertSame(1, DB::table('tm_tasks')->count());
    }

    public function test_project_lifecycle_exception_rolls_back_task_instance_sequence_journal_and_event(): void
    {
        $project = $this->project();
        $lifecycle = new class implements StageLifecycle
        {
            public function activated(ExecutionContext $context, int $instanceId, string $operationId): void
            {
            }

            public function entered(ExecutionContext $context, int $instanceId, int $visitId, object $stage, string $operationId): void
            {
            }

            public function leaving(ExecutionContext $context, int $instanceId): void
            {
            }

            public function activity(ExecutionContext $context, int $instanceId): void
            {
            }

            public function beforeTransition(ExecutionContext $context, array $instance, array $edge, array $payload): void
            {
                throw new \RuntimeException('lifecycle_veto');
            }
        };
        $this->app->instance(StageLifecycle::class, new StageLifecycleChain([$this->app->make(StageLifecycle::class), $lifecycle]));
        try {
            $this->projects()->createTask($this->manager(), $project['project_key'], 'task', 'Rollback', 'rolled-back');
            self::fail('Lifecycle must participate in the creation transaction');
        } catch (\RuntimeException $error) {
            self::assertSame('lifecycle_veto', $error->getMessage());
        }
        foreach (['tm_tasks', 'tm_workflow_instances', 'tm_sequences', 'tm_active_process_slots', 'tm_task_closure', 'tm_task_key_aliases'] as $table) {
            self::assertSame(0, DB::table($table)->count(), $table);
        }
        self::assertSame(0, DB::table('tm_command_journal')->where('operation_id', 'rolled-back')->count());
        self::assertSame(0, DB::table('tm_outbox')->where('operation_id', 'rolled-back')->count());
    }

    public function test_task_sessions_delegate_everyday_operations_and_keep_context_local(): void
    {
        $a = TaskManager::tasks()->inContext($this->context());
        $b = TaskManager::tasks()->inContext($this->context('b'));
        $task = $a->create('Task', 'create');
        $b->create('Other', 'other');
        $task = $a->update($task->publicKey, $task->version, 'edit', ['priority' => 'high']);
        $task = $a->complete($task->publicKey, $task->version, 'complete');
        $task = $a->reopen($task->publicKey, $task->version, 'reopen');
        $task = $a->archive($task->publicKey, $task->version, 'archive');
        self::assertCount(0, $a->query());
        $task = $a->restore($task->publicKey, $task->version, 'restore');
        self::assertSame(6, $task->version);
        self::assertCount(1, $a->query());
        self::assertSame('Other', $b->query()[0]->title);
        $project = $this->project();
        $projectTask = $this->projects()->createTask($this->manager(), $project['project_key'], 'task', 'Project', 'project-task');
        $this->error('workflow_command_required', fn () => $a->complete($projectTask['public_key'], $projectTask['version'], 'bad-complete'));
        $this->error('workflow_command_required', fn () => $a->reopen($projectTask['public_key'], $projectTask['version'], 'bad-reopen'));
    }

    public function test_pagination_configuration_defaults_explicit_limits_and_safe_ceiling(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->service()->create($this->context(), 'Task '.$i, 'task-'.$i);
        }
        config(['task-manager.page_size' => 2, 'task-manager.max_page_size' => 3]);
        self::assertCount(2, $this->service()->query($this->context()));
        self::assertCount(2, $this->service()->inContext($this->context())->query());
        self::assertCount(1, $this->service()->query($this->context(), limit: 1));
        self::assertCount(3, $this->service()->query($this->context(), limit: 3));
        $this->error('pagination_invalid', fn () => $this->service()->query($this->context(), limit: 0));
        $this->error('pagination_invalid', fn () => $this->service()->query($this->context(), limit: 4));
        $this->error('pagination_invalid', fn () => $this->service()->findTasks(new TaskQuery($this->context())));
        config(['task-manager.max_page_size' => 101]);
        $this->error('pagination_configuration_invalid', fn () => $this->service()->query($this->context()));
        config(['task-manager.max_page_size' => null]);
        $this->error('pagination_configuration_invalid', fn () => $this->service()->query($this->context()));
        config(['task-manager.max_page_size' => 100, 'task-manager.page_size' => null]);
        $this->error('pagination_configuration_invalid', fn () => $this->service()->query($this->context()));
        config(['task-manager.max_page_size' => 100, 'task-manager.page_size' => 0]);
        $this->error('pagination_configuration_invalid', fn () => $this->service()->query($this->context()));
        self::assertCount(1, $this->service()->query($this->context(), limit: 1));
    }

    public function test_reminder_read_and_query_normalize_database_timestamp_precision_without_losing_microseconds(): void
    {
        $base = $this->manager();
        $context = new ExecutionContext('one', 'a', 'reminder-format', capabilities: [...$base->capabilities, 'reminder.manage']);
        $reminders = $this->app->make(ReminderService::class);
        $reminder = $reminders->createGeneral($context, 'Precision', new \DateTimeImmutable('2090-01-02T10:00:00Z'), 'UTC', 'precision');
        DB::table('tm_reminders')->where('id', $reminder['id'])->update(['scheduled_at' => '2090-01-02 10:00:00', 'absolute_at' => '2090-01-02 10:00:00.123456']);
        $read = $reminders->get($context, $reminder['reminder_key']);
        self::assertSame('2090-01-02 10:00:00.000000', $read['scheduled_at']);
        self::assertSame('2090-01-02 10:00:00.123456', $read['absolute_at']);
        self::assertNull($read['deleted_at']);
        self::assertSame($read, $reminders->query($context)[0]);
    }

    public function test_container_guards_keep_subject_and_lifecycle_checks_and_roll_back_exceptions(): void
    {
        $definition = $this->definition();
        $definition['transitions']['review']['guards'] = ['host.ready'];
        $revision = $this->workflows()->publish($this->manager(), 'guarded', 0, 'publish', $definition);
        $this->host();
        $this->app->instance(TransitionGuards::class, new TransitionGuards([
            'host.ready' => static function (ExecutionContext $context, array $instance, array $payload): bool {
                if (($payload['throw'] ?? false) === true) {
                    throw new \RuntimeException('guard failed');
                }

                return $context->scope === 'one' && ($payload['ready'] ?? false) === true;
            },
        ]));
        $service = $this->app->make(InstanceService::class);
        $instance = $service->start($this->manager(), 'crm.lead', 'lead-1', $revision['id'], 'start');
        $this->error('guard_veto', fn () => $service->transition($this->manager(), $instance['instance_key'], 1, 'veto', 'review'));
        $before = DB::table('tm_command_journal')->count();
        try {
            $service->transition($this->manager(), $instance['instance_key'], 1, 'throws', 'review', ['throw' => true]);
            self::fail('A throwing guard must abort the command');
        } catch (\RuntimeException $error) {
            self::assertSame('guard failed', $error->getMessage());
        }
        self::assertSame($before, DB::table('tm_command_journal')->count());
        self::assertSame(1, $service->get($this->manager(), $instance['instance_key'])['version']);
        DB::table('host_subjects')->where('id', 'lead-1')->update(['veto' => true]);
        $this->error('host_guard_veto', fn () => $service->transition($this->manager(), $instance['instance_key'], 1, 'host-veto', 'review', ['ready' => true]));
        DB::table('host_subjects')->where('id', 'lead-1')->update(['veto' => false]);
        $done = $service->transition($this->manager(), $instance['instance_key'], 1, 'review', 'review', ['ready' => true]);
        self::assertSame('review', $done['current_state_key']);
    }

    public function test_all_four_query_paths_apply_config_and_preserve_named_argument_compatibility(): void
    {
        $base = $this->manager();
        $c = new ExecutionContext('one', 'a', 'pagination', capabilities: [...$base->capabilities, 'reminder.manage']);
        $reminders = $this->app->make(ReminderService::class);
        for ($i = 0; $i < 4; $i++) {
            $this->service()->create($c, 'Page '.$i, 'page-'.$i);
            $reminders->createGeneral($c, 'Reminder '.$i, new \DateTimeImmutable('2090-01-01T10:00:00Z'), 'UTC', 'reminder-'.$i);
        }
        config(['task-manager.page_size' => 2, 'task-manager.max_page_size' => 100]);
        self::assertCount(2, $this->instances()->query($c));
        self::assertCount(2, $reminders->query($c));
        $queries = $this->app->make(QueryService::class);
        self::assertCount(2, $queries->tasks($c)['items']);
        self::assertCount(4, $this->instances()->query($c, afterKey: null));
        self::assertCount(4, $reminders->query($c, afterId: 0));
        self::assertCount(4, $queries->tasks($c, cursor: null)['items']);
        config(['task-manager.max_page_size' => 3]);
        self::assertCount(3, $this->instances()->query($c, 3));
        self::assertCount(3, $reminders->query($c, 3));
        self::assertCount(3, $queries->tasks($c, limit: 3)['items']);
        $this->error('pagination_invalid', fn () => $this->instances()->query($c, 4));
        $this->error('pagination_invalid', fn () => $reminders->query($c, 4));
        $this->error('pagination_invalid', fn () => $queries->tasks($c, limit: 4));
    }

    public function test_missing_guard_and_legacy_constructor_precedence_are_preserved(): void
    {
        $definition = $this->definition();
        $definition['transitions']['review']['guards'] = ['host.ready'];
        $revision = $this->workflows()->publish($this->manager(), 'guarded', 0, 'publish', $definition);
        $this->host();
        $instance = $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $revision['id'], 'start');
        $this->error('guard_unregistered', fn () => $this->instances()->transition($this->manager(), $instance['instance_key'], 1, 'missing', 'review'));
        $invalidLegacy = new InstanceService(DB::connection(), $this->app->make(Clock::class), new ContextAuthorization,
            $this->app->make(SubjectRegistry::class), $this->workflows(), $this->pipelines(),
            ['host.ready' => null], transitionGuards: new TransitionGuards(['host.ready' => static fn () => true]));
        $this->error('guard_unregistered', fn () => $invalidLegacy->transition($this->manager(), $instance['instance_key'], 1, 'invalid-legacy', 'review'));
        $legacy = new InstanceService(DB::connection(), $this->app->make(Clock::class), new ContextAuthorization,
            $this->app->make(SubjectRegistry::class), $this->workflows(), $this->pipelines(),
            ['host.ready' => static fn () => true], $this->app->make(StageLifecycle::class), new TransitionGuards(['host.ready' => static fn () => false]));
        self::assertSame('review', $legacy->transition($this->manager(), $instance['instance_key'], 1, 'legacy', 'review')['current_state_key']);
    }

    public function test_facade_annotations_match_every_accessor_and_legacy_forwarding_still_runs(): void
    {
        $doc = (new \ReflectionClass(TaskManager::class))->getDocComment();
        foreach ((new \ReflectionClass(\Karnoweb\TaskManager\TaskManager::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getNumberOfParameters() === 0) {
                self::assertStringContainsString(' '.$method->name.'()', $doc);
            }
        }
        $task = TaskManager::create($this->context(), 'Legacy', 'legacy');
        self::assertSame('Legacy', TaskManager::get($this->context(), $task['public_key'])['title']);
        $this->expectException(\BadMethodCallException::class);
        TaskManager::unknownTaskMethod();
    }

    public function test_existing_public_method_parameter_names_are_preserved(): void
    {
        $baseline = json_decode(file_get_contents(dirname(__DIR__).'/dev/TaskManager-Public-Surface-Audit-FA.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($baseline as $file) {
            if (! in_array($file['path'], ['src/TaskService.php', 'src/TaskSession.php', 'src/Workflow/ProjectService.php', 'src/Workflow/InstanceService.php'], true)) {
                continue;
            }
            $class = 'Karnoweb\\TaskManager\\'.str_replace('/', '\\', substr($file['path'], 4, -4));
            foreach ($file['methods'] as $method) {
                $reflection = new \ReflectionMethod($class, $method['name']);
                self::assertTrue($reflection->isPublic());
                preg_match_all('/\$(\w+)/', $method['signature'], $matches);
                $parameters = $reflection->getParameters();
                self::assertGreaterThanOrEqual(count($matches[1]), count($parameters));
                foreach ($matches[1] as $index => $name) {
                    self::assertSame($name, $parameters[$index]->getName(), $class.'::'.$method['name']);
                }
                foreach (array_slice($parameters, count($matches[1])) as $added) {
                    self::assertTrue($added->isOptional(), 'New constructor parameters must remain optional');
                }
            }
        }
    }
}
