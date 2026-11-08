<?php

namespace Karnoweb\TaskManager;

use Illuminate\Support\ServiceProvider;
use Karnoweb\TaskManager\Approval\ApprovalEngine;
use Karnoweb\TaskManager\Approval\ApprovalService;
use Karnoweb\TaskManager\Collaboration\AttachmentService;
use Karnoweb\TaskManager\Collaboration\ChecklistService;
use Karnoweb\TaskManager\Collaboration\CollaborationService;
use Karnoweb\TaskManager\Collaboration\UnavailableAttachmentStorage;
use Karnoweb\TaskManager\Context\ContextFactory;
use Karnoweb\TaskManager\Contracts\AttachmentStorage;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\AuthorizationRevision;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\Contracts\CommitEvidenceProvider;
use Karnoweb\TaskManager\Contracts\DnsResolver;
use Karnoweb\TaskManager\Contracts\EntryRoutingProvider;
use Karnoweb\TaskManager\Contracts\ExportStorage;
use Karnoweb\TaskManager\Contracts\NotificationPreferences;
use Karnoweb\TaskManager\Contracts\NotificationTransport;
use Karnoweb\TaskManager\Contracts\ReminderRecipients;
use Karnoweb\TaskManager\Contracts\ResponsibilityResolver;
use Karnoweb\TaskManager\Contracts\SecretVault;
use Karnoweb\TaskManager\Contracts\StageLifecycle;
use Karnoweb\TaskManager\Contracts\WebhookTransport;
use Karnoweb\TaskManager\Enterprise\CustomFieldService;
use Karnoweb\TaskManager\Enterprise\ExportService;
use Karnoweb\TaskManager\Enterprise\LocalExportStorage;
use Karnoweb\TaskManager\Enterprise\PackageAuthorizationRevision;
use Karnoweb\TaskManager\Enterprise\PresetService;
use Karnoweb\TaskManager\Enterprise\QueryService;
use Karnoweb\TaskManager\Enterprise\ReportService;
use Karnoweb\TaskManager\Group\GroupService;
use Karnoweb\TaskManager\Group\GroupSourceRegistry;
use Karnoweb\TaskManager\Group\MilestoneService;
use Karnoweb\TaskManager\Group\PostgresCommitEvidence;
use Karnoweb\TaskManager\Handoff\ExternalSagaService;
use Karnoweb\TaskManager\Handoff\HandoffLifecycle;
use Karnoweb\TaskManager\Handoff\HandoffService;
use Karnoweb\TaskManager\Handoff\LocalRouteService;
use Karnoweb\TaskManager\Handoff\SubjectRouteRegistry;
use Karnoweb\TaskManager\Integration\AutomationActions;
use Karnoweb\TaskManager\Integration\AutomationService;
use Karnoweb\TaskManager\Integration\ConsumerRegistry;
use Karnoweb\TaskManager\Integration\CurlWebhookTransport;
use Karnoweb\TaskManager\Integration\EventAccess;
use Karnoweb\TaskManager\Integration\EventPump;
use Karnoweb\TaskManager\Integration\NativeDnsResolver;
use Karnoweb\TaskManager\Integration\NotificationConsumer;
use Karnoweb\TaskManager\Integration\UnavailableNotificationTransport;
use Karnoweb\TaskManager\Integration\UnavailableSecretVault;
use Karnoweb\TaskManager\Integration\WebhookService;
use Karnoweb\TaskManager\Integration\WebhookUrlPolicy;
use Karnoweb\TaskManager\Planning\BulkService;
use Karnoweb\TaskManager\Planning\MarkerService;
use Karnoweb\TaskManager\Planning\PositionService;
use Karnoweb\TaskManager\Planning\ReportingService;
use Karnoweb\TaskManager\Planning\SprintService;
use Karnoweb\TaskManager\Planning\TemplateService;
use Karnoweb\TaskManager\Planning\TimeTrackingService;
use Karnoweb\TaskManager\Responsibility\AssignmentEngine;
use Karnoweb\TaskManager\Responsibility\AssignmentService;
use Karnoweb\TaskManager\Responsibility\ContextResponsibilityResolver;
use Karnoweb\TaskManager\Scheduling\DailyQuietHours;
use Karnoweb\TaskManager\Scheduling\DateService;
use Karnoweb\TaskManager\Scheduling\RecurrenceService;
use Karnoweb\TaskManager\Scheduling\ReminderService;
use Karnoweb\TaskManager\Scheduling\SelfReminderRecipients;
use Karnoweb\TaskManager\SLA\CalendarRegistry;
use Karnoweb\TaskManager\SLA\SlaEngine;
use Karnoweb\TaskManager\SLA\SlaService;
use Karnoweb\TaskManager\SLA\StagePolicyService;
use Karnoweb\TaskManager\TaskGraph\DependencyService;
use Karnoweb\TaskManager\TaskGraph\HierarchyService;
use Karnoweb\TaskManager\TaskGraph\NativeTaskRules;
use Karnoweb\TaskManager\TaskGraph\TaskPlanningService;
use Karnoweb\TaskManager\Workflow\CaseService;
use Karnoweb\TaskManager\Workflow\EntryRoutingService;
use Karnoweb\TaskManager\Workflow\InstanceService;
use Karnoweb\TaskManager\Workflow\PipelineService;
use Karnoweb\TaskManager\Workflow\ProjectService;
use Karnoweb\TaskManager\Workflow\SubjectTaskService;
use Karnoweb\TaskManager\Workflow\TaskSubjectHandler;
use Karnoweb\TaskManager\Workflow\TransitionGuards;
use Karnoweb\TaskManager\Workflow\UnavailableEntryRoutingProvider;
use Karnoweb\TaskManager\Workflow\WorkflowService;

final class TaskManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(ContextFactory::class, fn ($app) => new ContextFactory($app, $app['config']));
        $this->app->bindIf(EntryRoutingProvider::class, UnavailableEntryRoutingProvider::class);
        $this->app->bind(EntryRoutingService::class, fn ($app) => new EntryRoutingService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(PipelineService::class), $app->make(InstanceService::class), $app->make(EntryRoutingProvider::class)));
        $this->mergeConfigFrom(__DIR__.'/../config/task-manager.php', 'task-manager');
        $this->app->bindIf(ReminderRecipients::class, SelfReminderRecipients::class);
        $this->app->bindIf(AttachmentStorage::class, UnavailableAttachmentStorage::class);
        $this->app->bind(TimeTrackingService::class, fn ($app) => new TimeTrackingService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(TaskService::class)));
        $this->app->bind(SprintService::class, fn ($app) => new SprintService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(TaskService::class), $app->make(ProjectService::class)));
        $this->app->bind(PositionService::class, fn ($app) => new PositionService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(TaskService::class), $app->make(ProjectService::class), $app->make(SprintService::class)));
        $this->app->bind(MarkerService::class, fn ($app) => new MarkerService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(TaskService::class), $app->make(ProjectService::class)));
        $this->app->bind(ReportingService::class, fn ($app) => new ReportingService($app['db']->connection(), $app->make(AuthorizationProvider::class), $app->make(TaskService::class), $app->make(ProjectService::class)));
        $this->app->bind(TemplateService::class, fn ($app) => new TemplateService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(TaskService::class), $app->make(ProjectService::class), $app->make(WorkflowService::class), $app->make(HierarchyService::class), $app->make(TaskPlanningService::class)));
        $this->app->bind(BulkService::class, fn ($app) => new BulkService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(TaskService::class)));
        $this->app->bind(DateService::class, fn ($app) => new DateService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(TaskService::class)));
        $this->app->bind(RecurrenceService::class, fn ($app) => new RecurrenceService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(TaskService::class), $app->make(DateService::class), $app->make(ProjectService::class), $app->make(WorkflowService::class), $app->make(CalendarRegistry::class)));
        $this->app->bindIf(NotificationPreferences::class, fn ($app) => new DailyQuietHours($app['config']->get('task-manager.quiet_hours.timezone', 'UTC'), $app['config']->get('task-manager.quiet_hours.start'), $app['config']->get('task-manager.quiet_hours.end'), $app['config']->get('task-manager.notification_channels', ['in_app'])));
        $this->app->bind(ReminderService::class, fn ($app) => new ReminderService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(TaskService::class), $app->make(NotificationPreferences::class), $app->make(SubjectRegistry::class), $app->make(ReminderRecipients::class)));
        $this->app->bindIf(AuthorizationRevision::class, PackageAuthorizationRevision::class);
        $this->app->bind(CustomFieldService::class, fn ($app) => new CustomFieldService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(TaskService::class), $app->make(ResponsibilityResolver::class)));
        $this->app->bind(QueryService::class, fn ($app) => new QueryService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(AuthorizationRevision::class), $app->make(TaskService::class), $app->make(CustomFieldService::class)));
        $this->app->bind(PresetService::class, fn ($app) => new PresetService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(TaskService::class), $app->make(ChecklistService::class)));
        $this->app->bindIf(ExportStorage::class, fn ($app) => new LocalExportStorage($app['config']->get('task-manager.export_directory') ?? storage_path('app/private/task-manager-exports'), $app['config']->get('task-manager.export_max_bytes', 104857600)));
        $this->app->bind(ExportService::class, fn ($app) => new ExportService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(AuthorizationRevision::class), $app->make(QueryService::class), $app->make(TaskService::class), $app->make(InstanceService::class), $app->make(GroupService::class), $app->make(ExportStorage::class)));
        $this->app->bind(EventAccess::class, fn ($app) => new EventAccess($app['db']->connection(), $app->make(TaskService::class), $app->make(InstanceService::class), $app->make(GroupService::class), $app->make(HandoffService::class), $app->make(ReminderService::class)));
        $this->app->bind(AutomationActions::class, fn ($app) => new AutomationActions($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(TaskService::class), $app->make(InstanceService::class), $app->make(AssignmentService::class), $app->make(ApprovalService::class), $app->make(HandoffService::class), $app->make(HierarchyService::class), $app->make(ProjectService::class), $app->make(WorkflowService::class), $app->make(ExportService::class), $app->make(SlaService::class)));
        $this->app->bind(AutomationService::class, fn ($app) => new AutomationService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(TaskService::class), $app->make(EventAccess::class), $app->make(AutomationActions::class)));
        $this->app->bindIf(NotificationTransport::class, UnavailableNotificationTransport::class);
        $this->app->bind(NotificationConsumer::class, fn ($app) => new NotificationConsumer($app['db']->connection(), $app->make(NotificationPreferences::class), $app->make(NotificationTransport::class), $app->make(Clock::class)));
        $this->app->singleton(ConsumerRegistry::class, function ($app) {
            $registry = new ConsumerRegistry;
            $registry->register('notifications', fn ($context, $event) => $app->make(NotificationConsumer::class)->deliver($context, $event));
            $registry->register('automation', fn ($context, $event) => $app->make(AutomationService::class)->run($context, $event['event_id']));
            $registry->register('webhooks', fn ($context, $event) => $app->make(WebhookService::class)->queue($context, $event['event_id']));

            return $registry;
        });
        $this->app->bind(EventPump::class, fn ($app) => new EventPump($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(EventAccess::class), $app->make(ConsumerRegistry::class)));
        $this->app->bindIf(DnsResolver::class, NativeDnsResolver::class);
        $this->app->bindIf(SecretVault::class, UnavailableSecretVault::class);
        $this->app->bindIf(WebhookTransport::class, CurlWebhookTransport::class);
        $this->app->bind(WebhookUrlPolicy::class, fn ($app) => new WebhookUrlPolicy($app->make(DnsResolver::class), $app['config']->get('task-manager.webhook_allowed_hosts', [])));
        $this->app->bind(WebhookService::class, fn ($app) => new WebhookService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(EventAccess::class), $app->make(WebhookUrlPolicy::class), $app->make(SecretVault::class), $app->make(WebhookTransport::class)));
        $this->app->bind(ReportService::class, fn ($app) => new ReportService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(AuthorizationRevision::class), $app->make(InstanceService::class), $app->make(ProjectService::class), $app->make(GroupService::class)));
        $this->app->bindIf(TransitionGuards::class, fn () => new TransitionGuards);
        $this->app->bindIf(Clock::class, SystemClock::class);
        $this->app->bindIf(AuthorizationProvider::class, ContextAuthorization::class);
        $this->app->bindIf(SubjectRouteRegistry::class, fn () => new SubjectRouteRegistry);
        $this->app->bindIf(GroupSourceRegistry::class, fn () => new GroupSourceRegistry);
        $this->app->bindIf(CommitEvidenceProvider::class, fn ($app) => new PostgresCommitEvidence($app['db']->connection()));
        $this->app->bind(GroupService::class, fn ($app) => new GroupService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(InstanceService::class), $app->make(GroupSourceRegistry::class)));
        $this->app->bind(MilestoneService::class, fn ($app) => new MilestoneService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(InstanceService::class), $app->make(CommitEvidenceProvider::class)));
        foreach ([LocalRouteService::class, ExternalSagaService::class] as $routeService) {
            $this->app->bind($routeService, fn ($app) => new $routeService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(InstanceService::class), $app->make(CaseService::class), $app->make(SubjectRouteRegistry::class)));
        }
        $this->app->bind(TaskService::class, fn ($app) => new TaskService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(StageLifecycle::class)));
        $this->app->bind(HierarchyService::class, fn ($app) => new HierarchyService($app['db']->connection(), $app->make(Clock::class), $app->make(TaskService::class), $app->make(ProjectService::class), $app->make(AuthorizationProvider::class)));
        $this->app->bind(DependencyService::class, fn ($app) => new DependencyService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(TaskService::class), $app->make(CalendarRegistry::class)));
        $this->app->bind(TaskPlanningService::class, fn ($app) => new TaskPlanningService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(TaskService::class), $app->make(ProjectService::class), $app->make(CalendarRegistry::class)));
        $this->app->singleton(SubjectRegistrations::class);
        $this->app->bind(SubjectTaskService::class, fn ($app) => new SubjectTaskService($app['db']->connection(), $app->make(TaskService::class), $app->make(SubjectRegistry::class), $app->make(Clock::class)));
        $this->app->bindIf(SubjectRegistry::class, fn ($app) => new SubjectRegistry(['task' => new TaskSubjectHandler($app->make(TaskService::class)), ...$app->make(SubjectRegistrations::class)->handlers($app)]));
        $this->app->bindIf(CalendarRegistry::class, fn () => new CalendarRegistry);
        $this->app->bind(SlaEngine::class, fn ($app) => new SlaEngine($app['db']->connection(), $app->make(Clock::class), $app->make(CalendarRegistry::class)));
        $this->app->bindIf(ResponsibilityResolver::class, ContextResponsibilityResolver::class);
        $this->app->bind(AssignmentEngine::class, fn ($app) => new AssignmentEngine($app['db']->connection(), $app->make(Clock::class), $app->make(ResponsibilityResolver::class)));
        $this->app->bind(ApprovalEngine::class, fn ($app) => new ApprovalEngine($app['db']->connection(), $app->make(Clock::class), $app->make(ResponsibilityResolver::class)));
        $this->app->bindIf(StageLifecycle::class, fn ($app) => new StageLifecycleChain([new NativeTaskRules($app['db']->connection(), $app->make(Clock::class), $app->make(CalendarRegistry::class)), new HandoffLifecycle, $app->make(ApprovalEngine::class), $app->make(AssignmentEngine::class), $app->make(SlaEngine::class)]));
        $this->app->bind(HandoffService::class, fn ($app) => new HandoffService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(InstanceService::class), $app->make(WorkflowService::class), $app->make(CaseService::class), $app->make(SlaService::class), $app->make(AssignmentEngine::class)));
        $this->app->bind(ApprovalService::class, fn ($app) => new ApprovalService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(ApprovalEngine::class), $app->make(InstanceService::class), $app->make(PipelineService::class)));
        $this->app->bind(AssignmentService::class, fn ($app) => new AssignmentService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(AssignmentEngine::class), $app->make(InstanceService::class), $app->make(PipelineService::class)));
        $this->app->bind(StagePolicyService::class, fn ($app) => new StagePolicyService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(CalendarRegistry::class), $app->make(PipelineService::class)));
        $this->app->bind(SlaService::class, fn ($app) => new SlaService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(SlaEngine::class), $app->make(InstanceService::class)));
        $this->app->bind(TaskResourceService::class, fn ($app) => new TaskResourceService($app['db']->connection(), $app->make(Clock::class), $app->make(TaskService::class), $app->make(AuthorizationProvider::class)));
        $this->app->bind(CollaborationService::class, fn ($app) => new CollaborationService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(TaskService::class), $app->make(TaskResourceService::class)));
        $this->app->bind(AttachmentService::class, fn ($app) => new AttachmentService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(TaskService::class), $app->make(TaskResourceService::class), $app->make(AttachmentStorage::class)));
        $this->app->bind(ChecklistService::class, fn ($app) => new ChecklistService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(TaskService::class), $app->make(ResponsibilityResolver::class), $app->make(HierarchyService::class), $app->make(ProjectService::class), $app->make(WorkflowService::class)));
        $this->app->bind(WorkflowService::class, fn ($app) => new WorkflowService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class)));
        $this->app->bind(PipelineService::class, fn ($app) => new PipelineService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(WorkflowService::class)));
        $this->app->bind(InstanceService::class, fn ($app) => new InstanceService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(SubjectRegistry::class), $app->make(WorkflowService::class), $app->make(PipelineService::class), lifecycle: $app->make(StageLifecycle::class), transitionGuards: $app->make(TransitionGuards::class)));
        $this->app->bind(ProjectService::class, fn ($app) => new ProjectService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(WorkflowService::class), $app->make(TaskService::class), $app->make(StageLifecycle::class)));
        $this->app->bind(CaseService::class, fn ($app) => new CaseService($app['db']->connection(), $app->make(Clock::class), $app->make(AuthorizationProvider::class), $app->make(InstanceService::class)));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'task-manager');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/task-manager.php' => config_path('task-manager.php'),
            ], 'task-manager-config');

            // publishes(), not publishesMigrations(): filenames stay exactly as shipped.
            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'task-manager-migrations');

            $this->publishes([
                __DIR__.'/../lang' => lang_path('vendor/task-manager'),
            ], 'task-manager-lang');
        }
    }
}
