<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Karnoweb\TaskManager\Context\ContextFactory;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\BranchResolver;
use Karnoweb\TaskManager\Contracts\ScopeResolver;
use Karnoweb\TaskManager\Contracts\UserResolver;
use Karnoweb\TaskManager\Enterprise\ExportService;
use Karnoweb\TaskManager\Enterprise\QueryService;
use Karnoweb\TaskManager\Scheduling\ReminderService;
use Karnoweb\TaskManager\Schema\Tables;
use Karnoweb\TaskManager\TaskGraph\DependencyService;
use Karnoweb\TaskManager\TaskGraph\HierarchyService;

/** Runs the same real service flows with default and custom table prefixes. */
class MultiTenancyScenarioTest extends WorkflowTestCase
{
    protected const PREFIX = 'tm_';

    private array $membership = ['shared' => ['north', 'south'], 'worker' => ['north']];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('task-manager.table_prefix', static::PREFIX);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['task-manager.branch.enabled' => true]);
        $this->app->instance(UserResolver::class, fn () => new GenericUser(['id' => 'shared']));
        $this->app->instance(BranchResolver::class, function ($actor, $requested) {
            return in_array($requested, $this->membership[$actor->getAuthIdentifier()] ?? [], true) ? $requested : null;
        });
    }

    private function branch(string $branch): ExecutionContext
    {
        $resolved = $this->app->make(ContextFactory::class)->make(branch: $branch);

        return new ExecutionContext($resolved->scope, $resolved->actor, $resolved->correlationId, capabilities: [
            ...$this->manager()->capabilities, 'dependency.manage', 'task.reparent', 'query.view', 'export.create', 'reminder.manage', 'reminder.schedule_past',
        ]);
    }

    public function test_shared_actor_has_independent_todos_projects_workflows_and_pipelines(): void
    {
        $north = $this->branch('north');
        $south = $this->branch('south');
        foreach ([$north, $south] as $context) {
            $todo = $this->service()->create($context, $context->scope, 'same-operation');
            $revision = $this->workflows()->publish($context, 'sales', 0, 'workflow', $this->definition());
            $project = $this->projects()->create($context, 'APP', 'Application', $revision['id'], 'project');
            $pipeline = $this->pipelines()->create($context, 'Sales', $revision['id'], ['new' => ['state' => 'new']], 'pipeline');
            $task = $this->projects()->createTask($context, $project['project_key'], 'task', 'Project task', 'project-task');
            self::assertSame('new', $task['state']);
            $instance = DB::table(Tables::name('workflow_instances'))->where('scope_key', $context->scope)->where('task_id', $task['id'])->first();
            $transitioned = $this->instances()->transition($context, $instance->instance_key, 1, 'review', 'review');
            self::assertSame('review', $transitioned['current_state_key']);
            self::assertCount(2, $this->service()->query($context));
            self::assertSame($context->scope, $this->pipelines()->get($context, $pipeline['pipeline_key'])['scope_key']);
            self::assertSame($context->scope, $this->service()->get($context, $todo['public_key'])['scope_key']);
        }
        self::assertSame(4, DB::table(Tables::name('tasks'))->count());
        self::assertSame(2, DB::table(Tables::name('projects'))->count());
        self::assertTrue(Schema::hasTable('host_subjects'));
        self::assertFalse(Schema::hasTable(static::PREFIX.'host_subjects'));
        self::assertTrue(Schema::hasTable(static::PREFIX.'tasks'));
        if (static::PREFIX !== 'tm_') {
            self::assertFalse(Schema::hasTable('tm_tasks'));
        }
        self::assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_foreign_identifiers_cannot_read_edit_complete_delete_or_create_relations(): void
    {
        $north = $this->branch('north');
        $south = $this->branch('south');
        $task = $this->service()->create($north, 'Private', 'create');
        $foreign = $this->service()->create($south, 'Other', 'create');
        $revision = $this->workflows()->publish($south, 'sales', 0, 'workflow', $this->definition());
        $project = $this->projects()->create($south, 'SOUTH', 'South', $revision['id'], 'project');
        $before = DB::table(Tables::name('command_journal'))->count();
        $this->error('not_found', fn () => $this->service()->get($south, $task['public_key']));
        foreach (['update', 'complete', 'delete'] as $action) {
            $this->error('not_found', fn () => $this->service()->mutate($south, $task['public_key'], 1, $action, $action, ['title' => 'Intrusion']));
        }
        $this->error('not_found', fn () => $this->projects()->previewTaskMove($north, $task['public_key'], $project['project_key'], 'task', ['pending' => 'new']));
        $this->error('not_found', fn () => $this->instances()->start($north, 'task', $task['public_key'], $revision['id'], 'foreign-workflow'));
        $this->error('not_found', fn () => $this->app->make(HierarchyService::class)->reparent($north, $task['public_key'], 1, $foreign['public_key'], 1, 'parent'));
        $this->error('not_found', fn () => $this->app->make(DependencyService::class)->add($north, $task['public_key'], 1, $foreign['public_key'], 1, 'relates_to', [], 'dependency'));
        self::assertSame($before, DB::table(Tables::name('command_journal'))->count());
        self::assertSame('Private', $this->service()->get($north, $task['public_key'])['title']);
        $this->error('context_branch_denied', fn () => $this->service()->share($south, $foreign['public_key'], 1, 'share', 'worker'));
        $this->error('context_branch_denied', fn () => $this->projects()->change($south, $project['project_key'], 1, 'member', 'member', ['actor' => 'worker', 'role' => 'member']));
        self::assertSame(0, DB::table(Tables::name('task_grants'))->count());
    }

    public function test_search_count_activity_and_export_do_not_cross_branch(): void
    {
        $north = $this->branch('north');
        $south = $this->branch('south');
        $task = $this->service()->create($north, 'North secret', 'create');
        $this->service()->create($south, 'South visible', 'create');
        self::assertSame(1, $this->service()->count($south));
        $query = $this->app->make(QueryService::class)->tasks($south, ['q' => 'secret']);
        self::assertSame(0, $query['total']);
        $this->error('not_found', fn () => $this->service()->activity($south, $task['public_key']));
        $export = implode('', iterator_to_array($this->app->make(ExportService::class)->stream($south, [])));
        self::assertStringContainsString('South visible', $export);
        self::assertStringNotContainsString('North secret', $export);
    }

    public function test_explicit_context_worker_replay_and_revoked_membership_fail_closed(): void
    {
        $north = $this->branch('north');
        $session = $this->service()->inContext($north);
        $task = $session->create('North', 'operation');
        $this->error('context_branch_scope_conflict', fn () => $this->service()->count($this->manager()));
        $this->app->instance(ScopeResolver::class, fn () => 'branch:south');
        $this->error('context_branch_scope_conflict', fn () => $this->app->make(ContextFactory::class)->make(branch: 'north'));
        $worker = new ExecutionContext('branch:north', 'worker', 'job', true, ['task.view']);
        self::assertSame(0, $this->service()->count($worker));
        $this->error('context_branch_denied', fn () => $this->service()->count(new ExecutionContext('branch:south', 'worker', 'job', true, ['task.view'])));
        $other = $this->service()->create($this->branch('south'), 'South', 'operation');
        self::assertNotSame($task->publicKey, $other['public_key']);
        unset($this->membership['shared']);
        $this->error('context_branch_denied', fn () => $session->create('North', 'operation'));
        self::assertSame(2, DB::table(Tables::name('tasks'))->count());
    }

    public function test_prefix_validation_and_cacheable_configuration(): void
    {
        self::assertSame(static::PREFIX.'tasks', Tables::name('tasks'));
        self::assertSame(static::PREFIX.'tasks.id', Tables::sql('tm_tasks.id'));
        $file = tempnam(sys_get_temp_dir(), 'tm-settings-');
        try {
            file_put_contents($file, '<?php return '.var_export(config('task-manager'), true).';');
            self::assertSame(config('task-manager'), require $file);
        } finally {
            unlink($file);
        }
        foreach (['bad-prefix', 'x;DROP', '', null, str_repeat('x', 13)] as $prefix) {
            config(['task-manager.table_prefix' => $prefix]);
            $this->error('table_prefix_invalid', fn () => Tables::name('tasks'));
        }
        config(['task-manager.table_prefix' => static::PREFIX, 'database.connections.testing.prefix' => 'app_']);
        $this->error('connection_table_prefix_unsupported', fn () => Tables::name('tasks'));
    }

    public function test_worker_dispatch_and_retry_keep_the_original_branch(): void
    {
        $north = $this->branch('north');
        $south = $this->branch('south');
        $reminders = $this->app->make(ReminderService::class);
        $when = new \DateTimeImmutable('-1 minute');
        $first = $reminders->createGeneral($north, 'North reminder', $when, 'UTC', 'reminder', allowPast: true);
        $second = $reminders->createGeneral($south, 'South reminder', $when, 'UTC', 'reminder', allowPast: true);
        $worker = new ExecutionContext($north->scope, $north->actor, 'dispatch', true, $north->capabilities);
        self::assertSame(1, $reminders->dispatch($worker)['queued']);
        self::assertSame(0, $reminders->dispatch($worker)['queued']);
        self::assertSame('sent', $reminders->get($north, $first['reminder_key'])['state']);
        self::assertSame('pending', $reminders->get($south, $second['reminder_key'])['state']);
        self::assertSame(1, DB::table(Tables::name('outbox'))->where('scope_key', $north->scope)->where('action', 'reminder_due')->count());
        self::assertSame(0, DB::table(Tables::name('outbox'))->where('scope_key', $south->scope)->where('action', 'reminder_due')->count());
        unset($this->membership['shared']);
        $this->error('context_branch_denied', fn () => $reminders->dispatch($worker));
    }

    public function test_existing_installation_keeps_data_when_migrations_are_run_again(): void
    {
        $context = $this->branch('north');
        $task = $this->service()->create($context, 'Preserved', 'create');
        $this->artisan('migrate')->assertExitCode(0);
        self::assertSame('Preserved', $this->service()->get($context, $task['public_key'])['title']);
        self::assertSame(1, DB::table(Tables::name('tasks'))->count());
        if (static::PREFIX === 'tm_') {
            config(['task-manager.table_prefix' => 'custom_']);
            $this->error('table_prefix_migration_required', fn () => $this->service());
            self::assertSame(1, DB::table('tm_tasks')->count());
            self::assertFalse(Schema::hasTable('custom_tasks'));
        }
    }

    public function test_every_package_table_has_a_scope_or_a_foreign_key_path_to_one(): void
    {
        $tables = [];
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if (! str_starts_with($name, static::PREFIX)) {
                continue;
            }
            $tables[$name] = [
                'scoped' => in_array('scope_key', array_column(Schema::getColumns($name), 'name'), true),
                'parents' => array_column(Schema::getForeignKeys($name), 'foreign_table'),
            ];
        }
        $resolved = array_keys(array_filter($tables, fn ($table) => $table['scoped']));
        do {
            $before = count($resolved);
            foreach ($tables as $name => $table) {
                if (! in_array($name, $resolved, true) && array_intersect($table['parents'], $resolved)) {
                    $resolved[] = $name;
                }
            }
        } while (count($resolved) !== $before);
        self::assertSame([], array_values(array_diff(array_keys($tables), $resolved)), 'Unscoped data islands must be reviewed.');
        self::assertGreaterThan(80, count($tables));
        file_put_contents(dirname(__DIR__).'/.runtime/tenancy-schema-'.static::PREFIX.'.json', json_encode($tables, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
