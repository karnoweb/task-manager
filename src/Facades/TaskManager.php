<?php

namespace Karnoweb\TaskManager\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Karnoweb\TaskManager\Context\ExecutionContext context(?\Karnoweb\TaskManager\Context\ExecutionContext $context = null, int|string|null $branch = null)
 * @method static \Karnoweb\TaskManager\Workflow\EntryRoutingService entryRouting()
 * @method static \Karnoweb\TaskManager\UI\IntegrationFactory integrations()
 * @method static \Karnoweb\TaskManager\TaskService tasks()
 * @method static \Karnoweb\TaskManager\Workflow\WorkflowService workflows()
 * @method static \Karnoweb\TaskManager\Workflow\PipelineService pipelines()
 * @method static \Karnoweb\TaskManager\Workflow\InstanceService instances()
 * @method static \Karnoweb\TaskManager\Workflow\ProjectService projects()
 * @method static \Karnoweb\TaskManager\Workflow\CaseService cases()
 * @method static \Karnoweb\TaskManager\SLA\SlaService sla()
 * @method static \Karnoweb\TaskManager\SLA\StagePolicyService stagePolicies()
 * @method static \Karnoweb\TaskManager\Responsibility\AssignmentService assignments()
 * @method static \Karnoweb\TaskManager\Approval\ApprovalService approvals()
 * @method static \Karnoweb\TaskManager\Handoff\HandoffService handoffs()
 * @method static \Karnoweb\TaskManager\Handoff\LocalRouteService routes()
 * @method static \Karnoweb\TaskManager\Handoff\ExternalSagaService sagas()
 * @method static \Karnoweb\TaskManager\Group\GroupService groups()
 * @method static \Karnoweb\TaskManager\Group\MilestoneService milestones()
 * @method static \Karnoweb\TaskManager\TaskGraph\HierarchyService hierarchy()
 * @method static \Karnoweb\TaskManager\TaskGraph\DependencyService dependencies()
 * @method static \Karnoweb\TaskManager\TaskGraph\TaskPlanningService planning()
 * @method static \Karnoweb\TaskManager\Collaboration\CollaborationService collaboration()
 * @method static \Karnoweb\TaskManager\Collaboration\ChecklistService checklists()
 * @method static \Karnoweb\TaskManager\Collaboration\AttachmentService attachments()
 * @method static \Karnoweb\TaskManager\Planning\TimeTrackingService time()
 * @method static \Karnoweb\TaskManager\Planning\SprintService sprints()
 * @method static \Karnoweb\TaskManager\Planning\PositionService positions()
 * @method static \Karnoweb\TaskManager\Planning\MarkerService markers()
 * @method static \Karnoweb\TaskManager\Planning\ReportingService reports()
 * @method static \Karnoweb\TaskManager\Planning\TemplateService templates()
 * @method static \Karnoweb\TaskManager\Planning\BulkService bulk()
 * @method static \Karnoweb\TaskManager\Scheduling\DateService dates()
 * @method static \Karnoweb\TaskManager\Scheduling\RecurrenceService recurrences()
 * @method static \Karnoweb\TaskManager\Scheduling\ReminderService reminders()
 * @method static \Karnoweb\TaskManager\Enterprise\CustomFieldService fields()
 * @method static \Karnoweb\TaskManager\Enterprise\QueryService queries()
 * @method static \Karnoweb\TaskManager\Enterprise\PresetService presets()
 * @method static \Karnoweb\TaskManager\Integration\AutomationService automation()
 * @method static \Karnoweb\TaskManager\Integration\EventPump events()
 * @method static \Karnoweb\TaskManager\Integration\WebhookService webhooks()
 * @method static \Karnoweb\TaskManager\Enterprise\ExportService exports()
 * @method static \Karnoweb\TaskManager\Enterprise\ReportService enterpriseReports()
 * @method static \Karnoweb\TaskManager\DTO\TaskResult createTodo(\Karnoweb\TaskManager\DTO\CreateTodo $command)
 * @method static \Karnoweb\TaskManager\TaskSession inContext(?\Karnoweb\TaskManager\Context\ExecutionContext $context = null)
 * @method static \Karnoweb\TaskManager\DTO\TaskResult change(\Karnoweb\TaskManager\DTO\MutateTask $command)
 * @method static array findTasks(\Karnoweb\TaskManager\DTO\TaskQuery $query)
 * @method static \Illuminate\Database\Query\Builder authorizedQuery(\Karnoweb\TaskManager\Context\ExecutionContext $context, string $capability = 'view')
 * @method static array get(\Karnoweb\TaskManager\Context\ExecutionContext $context, string $id)
 * @method static array resolveKey(\Karnoweb\TaskManager\Context\ExecutionContext $context, string $key)
 * @method static array query(\Karnoweb\TaskManager\Context\ExecutionContext $context, int $page = 1, int $limit = 30)
 * @method static int count(\Karnoweb\TaskManager\Context\ExecutionContext $context)
 * @method static array facets(\Karnoweb\TaskManager\Context\ExecutionContext $context)
 * @method static array lists(\Karnoweb\TaskManager\Context\ExecutionContext $context)
 * @method static array createList(\Karnoweb\TaskManager\Context\ExecutionContext $context, string $name, string $operation)
 * @method static array share(\Karnoweb\TaskManager\Context\ExecutionContext $context, string $id, int $version, string $operation, string $actor, bool $edit = false, bool $revoke = false)
 * @method static array changeList(\Karnoweb\TaskManager\Context\ExecutionContext $context, int $id, int $version, string $operation, array $values)
 * @method static array cloneTask(\Karnoweb\TaskManager\Context\ExecutionContext $context, string $id, string $operation)
 * @method static array activity(\Karnoweb\TaskManager\Context\ExecutionContext $context, string $id)
 * @method static array create(\Karnoweb\TaskManager\Context\ExecutionContext $context, string $title, string $operation)
 * @method static array mutate(\Karnoweb\TaskManager\Context\ExecutionContext $context, string $id, int $version, string $operation, string $action, array $values = [])
 *
 * @mixin \Karnoweb\TaskManager\TaskManager
 */
final class TaskManager extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Karnoweb\TaskManager\TaskManager::class;
    }
}
