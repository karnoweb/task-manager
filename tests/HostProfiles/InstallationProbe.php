<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Karnoweb\Calendar\CalendarServiceProvider;
use Karnoweb\Calendar\Concerns\HasCalendarEvents;
use Karnoweb\Calendar\Contracts\EventProvider;
use Karnoweb\Calendar\Data\CalendarContext;
use Karnoweb\Calendar\Data\CalendarModelDefinition;
use Karnoweb\Calendar\Data\DateRange;
use Karnoweb\Calendar\Data\EventQuery;
use Karnoweb\Calendar\Providers\EloquentEventProvider;
use Karnoweb\Calendar\Support\CalendarModelRegistry;
use Karnoweb\Crm\CrmServiceProvider;
use Karnoweb\Kanban\Actions\EloquentKanbanActionHandler;
use Karnoweb\Kanban\Concerns\HasKanbanCards;
use Karnoweb\Kanban\Contracts\BoardFactory;
use Karnoweb\Kanban\Contracts\DataProvider;
use Karnoweb\Kanban\Data\ActionCommand;
use Karnoweb\Kanban\Data\BoardContext;
use Karnoweb\Kanban\Data\BoardData;
use Karnoweb\Kanban\Data\BoardDefinition;
use Karnoweb\Kanban\Data\BoardQuery;
use Karnoweb\Kanban\Enums\ActionStatus;
use Karnoweb\Kanban\Enums\ActionType;
use Karnoweb\Kanban\KanbanServiceProvider;
use Karnoweb\Kanban\Models\KanbanModelDefinition;
use Karnoweb\Kanban\Models\KanbanModelRegistry;
use Karnoweb\Kanban\Providers\EloquentKanbanDataProvider;
use Karnoweb\Kanban\Support\BoardBuilder;
use Karnoweb\Kanban\Support\BoardRegistry;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\TaskManager;
use Karnoweb\TaskManager\TaskManagerServiceProvider;
use Karnoweb\TaskManager\TaskService;
use Karnoweb\TaskManager\UI\IntegrationFactory;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase;

final class InstallationProbe extends TestCase
{
    protected function getPackageProviders($app): array
    {
        $p = getenv('TM_PHASE6_INSTALL_PROFILE');
        $providers = [];
        if (in_array($p, ['task-only', 'all'], true)) {
            $providers[] = TaskManagerServiceProvider::class;
        }
        if (in_array($p, ['kanban-only', 'calendar-only', 'all'], true)) {
            $providers[] = LivewireServiceProvider::class;
        }
        if (in_array($p, ['kanban-only', 'all'], true)) {
            $providers[] = KanbanServiceProvider::class;
        }
        if (in_array($p, ['calendar-only', 'all'], true)) {
            $providers[] = CalendarServiceProvider::class;
        }
        if ($p === 'all') {
            $providers[] = CrmServiceProvider::class;
        }

        return $providers;
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('x', 32)));
    }

    public function test_installed_profile_boots_real_packages_and_persists_only_its_own_truth(): void
    {
        $p = getenv('TM_PHASE6_INSTALL_PROFILE');
        self::assertSame(in_array($p, ['task-only', 'all'], true), class_exists(TaskService::class));
        self::assertSame(in_array($p, ['kanban-only', 'all'], true), interface_exists(DataProvider::class));
        self::assertSame(in_array($p, ['calendar-only', 'all'], true), interface_exists(EventProvider::class));
        if (in_array($p, ['task-only', 'all'], true)) {
            $this->artisan('migrate:fresh')->run();
            $c = new ExecutionContext('1', '1', 'host-install');
            $s = $this->app->make(TaskService::class);
            $t = $s->create($c, 'Independent task', 'install-create');
            self::assertSame('Independent task', $s->get($c, $t['public_key'])['title']);
            $s->mutate($c, $t['public_key'], $t['version'], 'install-done', 'complete');
            self::assertSame('done', $s->get($c, $t['public_key'])['state']);
            $c = new ExecutionContext('1', '1', 'host-project', capabilities: [...$c->capabilities, 'workflow.manage', 'workflow.view', 'project.manage', 'project.view', 'report.view']);
            $manager = $this->app->make(TaskManager::class);
            $r = $manager->workflows()->publish($c, 'independent', 0, 'host-workflow', ['initial' => 'new', 'states' => ['new' => ['terminal' => 'none'], 'done' => ['terminal' => 'success']], 'transitions' => ['finish' => ['from' => 'new', 'to' => 'done']]]);
            $project = $manager->projects()->create($c, 'HOST', 'Independent project', $r['id'], 'host-project');
            self::assertSame('Independent project', $manager->projects()->get($c, $project['project_key'])['name']);
            self::assertSame(1, $manager->reports()->totals($c)['total_tasks']);
            self::assertInstanceOf(IntegrationFactory::class, $manager->integrations());
        }
        Schema::create('probe_orders', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->string('status');
            $t->string('scope_key');
            $t->string('owner_ref');
            $t->integer('version')->default(1);
            $t->timestamp('delivery_at');
            $t->timestamps();
        });
        DB::table('probe_orders')->insert(['title' => 'Host order', 'status' => 'new', 'scope_key' => '1', 'owner_ref' => '1', 'delivery_at' => '2026-10-22 09:00:00']);
        if (in_array($p, ['kanban-only', 'all'], true)) {
            $this->app->make(KanbanModelRegistry::class)->register('orders', ProbeKanbanOrder::class);
            $this->app->make(BoardRegistry::class)->register('host.orders', ProbeBoardFactory::class);
            $ui = new BoardContext('host.orders', '1', '1', 'probe');
            $provider = $this->app->make(EloquentKanbanDataProvider::class);
            $page = $provider->listCards($ui, new BoardQuery);
            self::assertCount(1, $page->items);
            $cmd = new ActionCommand('probe-move-1', ActionType::MoveCard, ['cardId' => 'orders:1', 'fromStageId' => 'new', 'toStageId' => 'sent'], '1');
            self::assertSame(ActionStatus::SUCCESS, $this->app->make(EloquentKanbanActionHandler::class)->execute($cmd, $ui)->status);
        }
        if (in_array($p, ['calendar-only', 'all'], true)) {
            $this->app->make(CalendarModelRegistry::class)->register('orders', ProbeCalendarOrder::class);
            $provider = $this->app->make(EloquentEventProvider::class, ['sourceKey' => 'orders']);
            $ui = new CalendarContext('host.calendar', '1', '1', 'en', 'UTC', 'probe');
            $q = new EventQuery(DateRange::ofLocalDays('2026-10-22', '2026-10-23', 'UTC'));
            $page = $provider->fetch($q, $ui);
            self::assertCount(1, $page->events);
            self::assertTrue($page->events[0]->isReadOnly());
        }
        self::assertSame(1, DB::table('probe_orders')->count());
    }
}

if (interface_exists(DataProvider::class)) {
    final class ProbeKanbanOrder extends Model
    {
        use HasKanbanCards;

        protected $table = 'probe_orders';

        protected $guarded = [];

        public static function kanbanDefinition(): KanbanModelDefinition
        {
            return KanbanModelDefinition::make('orders', self::class)->fields(['stage' => 'status', 'version' => 'version'])->stages(['new' => 'New', 'sent' => 'Sent'])->transitions(['new' => ['sent']])->visibleQuery(fn ($q, $c) => $q->where('scope_key', $c->scopeKey)->where('owner_ref', $c->actorId))->authorize(fn ($a, $r, $c) => $r !== null && (string) $r->scope_key === $c->scopeKey && (string) $r->owner_ref === $c->actorId);
        }
    }
    final class ProbeBoardFactory implements BoardFactory
    {
        public function make(BoardContext $c): BoardDefinition
        {
            return (new BoardBuilder)->key($c->boardKey)->board(new BoardData($c->boardKey, 'Orders', $c->scopeKey))->settings(['interaction' => ['transition_policy' => 'allow']])->sources(['orders'])->build();
        }
    }
}
if (interface_exists(EventProvider::class)) {
    final class ProbeCalendarOrder extends Model
    {
        use HasCalendarEvents;

        protected $table = 'probe_orders';

        protected $guarded = [];

        public static function calendarDefinition(): CalendarModelDefinition
        {
            return new CalendarModelDefinition(sourceKey: 'orders', startField: 'delivery_at', endField: null, scopeField: 'scope_key', ownerField: 'owner_ref', allDayField: null, descriptionField: null, locationField: null, categoryField: null, colorField: null, movableField: null, timezoneField: null, readOnly: true);
        }
    }
}
