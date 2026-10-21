<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Karnoweb\Calendar\CalendarServiceProvider;
use Karnoweb\Calendar\Data\CalendarContext;
use Karnoweb\Crm\CrmServiceProvider;
use Karnoweb\Crm\Enums\LeadStatus;
use Karnoweb\Crm\Models\Deal;
use Karnoweb\Crm\Models\Followup;
use Karnoweb\Crm\Models\Lead;
use Karnoweb\Crm\Models\PipelineStage;
use Karnoweb\Crm\Models\RecurringTask;
use Karnoweb\Crm\Services\DealService;
use Karnoweb\Crm\Services\PipelineService;
use Karnoweb\Crm\Services\TaskService;
use Karnoweb\Kanban\Data\BoardContext;
use Karnoweb\Kanban\KanbanServiceProvider;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\SubjectRegistry;
use Karnoweb\TaskManager\UI\BoundCursor;
use Karnoweb\TaskManager\UI\Calendar\TaskCalendar;
use Karnoweb\TaskManager\UI\ContextBridge;
use Karnoweb\TaskManager\UI\Crm\CutoverService;
use Karnoweb\TaskManager\UI\Crm\DealSubject;
use Karnoweb\TaskManager\UI\Crm\HostAccess;
use Karnoweb\TaskManager\UI\Crm\LegacyWriteGuard;
use Karnoweb\TaskManager\UI\Kanban\TaskBoard;
use Karnoweb\TaskManager\UI\Surface;
use Karnoweb\TaskManager\Workflow\TaskSubjectHandler;
use Livewire\LivewireServiceProvider;

abstract class PhaseSixTestCase extends PhaseFiveTestCase
{
    protected function setUp(): void
    {
        if (! getenv('TM_PHASE6_HOST_AUTOLOAD')) {
            self::markTestSkipped('Run the locked optional-host profile for phase-six integration tests.');
        }
        parent::setUp();
        Carbon::setTestNow($this->clock->now());
        $access = new class implements HostAccess
        {
            public array $denied = [];

            public function constrain(Builder $q, ExecutionContext $c, string $type, string $capability): void
            {
                if ($type === 'crm.tasks') {
                    $q->where(function (Builder $r) use ($c) {
                        $r->where(fn (Builder $a) => $a->where('subject_type', 'crm_lead')->whereIn('subject_id', DB::table('crm_leads')->where('branch_id', $c->scope)->where('assigned_to', $c->actor)->select('id')))->orWhere(fn (Builder $a) => $a->where('subject_type', 'crm_deal')->whereIn('subject_id', DB::table('crm_deals')->where('branch_id', $c->scope)->where('assigned_to', $c->actor)->select('id')));
                    });
                } else {
                    $q->where('crm_deals.branch_id', $c->scope)->where('crm_deals.assigned_to', $c->actor);
                }
                if ($this->denied) {
                    $q->whereNotIn($type === 'crm.tasks' ? 'crm_followups.id' : 'crm_deals.id', $this->denied);
                }
            }

            public function scopeFor(object $model): string
            {
                if ($model instanceof Deal) {
                    return (string) $model->branch_id;
                } $s = $model->subject;

                return (string) ($s?->branch_id ?? 'missing');
            }
        };
        $this->app->instance(HostAccess::class, $access);
        $this->app->bind(SubjectRegistry::class, fn () => new SubjectRegistry(['task' => new TaskSubjectHandler($this->service()), 'crm.deal' => new DealSubject(DB::connection(), $access)]));
        $guard = new LegacyWriteGuard($access);
        Deal::observe($guard);
        Followup::observe($guard);
        RecurringTask::observe($guard);
    }

    protected function getPackageProviders($app): array
    {
        require_once getenv('TM_PHASE6_HOST_AUTOLOAD');

        return [...parent::getPackageProviders($app), LivewireServiceProvider::class, KanbanServiceProvider::class, CalendarServiceProvider::class, CrmServiceProvider::class];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('x', 32)));
        $app['config']->set('crm.keys.user_key_type', 'int');
        $app['config']->set('crm.keys.branch_key_type', 'int');
    }

    protected function manager(string $actor = '1', string $scope = '1'): ExecutionContext
    {
        $c = parent::manager($actor, $scope);

        return new ExecutionContext($scope, $actor, 'phase-six', capabilities: [...$c->capabilities, 'migration.manage', 'handoff.manage', 'handoff.view', 'assignment.manage']);
    }

    protected function bridge(): ContextBridge
    {
        return new ContextBridge(fn ($ui) => $this->manager($ui->actorId, $ui->scopeKey));
    }

    protected function board(?Surface $surface = null, ?\Closure $handoff = null): TaskBoard
    {
        return new TaskBoard(DB::connection(), $this->service(), $this->instances(), $this->workflows(), $this->pipelines(), $this->app->make(SubjectRegistry::class), $this->app->make(AuthorizationProvider::class), $surface ?? new Surface('my.tasks', ['tasks']), $this->bridge(), new BoundCursor(str_repeat('a', 32)), $handoff);
    }

    protected function uiBoard(string $actor = '1', string $scope = '1', string $key = 'my.tasks'): BoardContext
    {
        return new BoardContext($key, $scope, $actor, 'board-instance');
    }

    protected function calendar(?Surface $surface = null): TaskCalendar
    {
        return new TaskCalendar(DB::connection(), $this->service(), $this->instances(), $this->projects(), $this->dates(), $this->recurrence(), $surface ?? new Surface('my.calendar', ['tm.schedule', 'tm.due', 'tm.recurrence', 'tm.sla', 'tm.milestones']), $this->bridge(), new BoundCursor(str_repeat('b', 32)));
    }

    protected function uiCalendar(string $actor = '1', string $scope = '1', string $key = 'my.calendar'): CalendarContext
    {
        return new CalendarContext($key, $actor, $scope, 'fa', 'Asia/Tehran', 'calendar-instance');
    }

    protected function cutover(): CutoverService
    {
        return new CutoverService(DB::connection(), $this->clock, $this->app->make(AuthorizationProvider::class), $this->app->make(HostAccess::class), $this->service(), $this->instances(), $this->workflows(), $this->projects(), $this->pipelines(), $this->dates());
    }

    protected function crm(): array
    {
        $lead = Lead::query()->create(['status' => LeadStatus::New, 'source' => 'phase-six', 'captured_at' => now(), 'score' => 0, 'total_interactions' => 0, 'branch_id' => 1, 'assigned_to' => 1]);
        $p = $this->app->make(PipelineService::class)->createWithDefaultStages(['name' => 'Real CRM'], ['Inbox', 'Review']);
        $stages = PipelineStage::query()->where('pipeline_id', $p->id)->orderBy('order')->get();
        $deal = $this->app->make(DealService::class)->create(['lead_id' => $lead->id, 'pipeline_id' => $p->id, 'pipeline_stage_id' => $stages[0]->id, 'branch_id' => 1, 'assigned_to' => 1, 'value' => '100.00']);

        return [$lead->fresh(), $deal->fresh(), $stages];
    }

    protected function migrationTasks(): array
    {
        [$lead,$deal,$stages] = $this->crm();
        $legacy = $this->app->make(TaskService::class)->create($deal, Carbon::parse('2026-10-22 09:00:00'), 1, ['title' => 'Legacy follow-up']);
        $r = $this->workflows()->publish($this->manager(), 'crm_tasks', 0, 'legacy-workflow', ['initial' => 'new', 'states' => ['new' => ['terminal' => 'none'], 'done' => ['terminal' => 'success'], 'cancelled' => ['terminal' => 'cancelled']], 'transitions' => ['complete' => ['from' => 'new', 'to' => 'done'], 'cancel' => ['from' => 'new', 'to' => 'cancelled', 'reason_required' => true, 'reason_codes' => ['cancelled']]]]);
        $project = $this->projects()->create($this->manager(), 'CRM', 'CRM tasks', $r['id'], 'legacy-project');
        $b = $this->cutover()->configure($this->manager(),'crm.tasks',['revision_id' => $r['id'], 'project_key' => $project['project_key'], 'states' => ['open' => 'new', 'done' => 'done', 'cancelled' => 'cancelled']],'migration-configure');

        return [$legacy->fresh(), $b, $deal];
    }
}
