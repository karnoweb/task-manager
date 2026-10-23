<?php

namespace Karnoweb\TaskManager\Tests;

use App\TaskManager\DepartmentRouting;
use App\TaskManager\TaskManagerTicketingProvider;
use App\TaskManager\TicketDepartmentRouter;
use App\TaskManager\TicketMessageAccess;
use App\TaskManager\TicketSubject;
use App\TaskManager\TicketWorkflow;
use App\TaskManager\TicketWrites;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Karnoweb\Kanban\Data\ActionCommand;
use Karnoweb\Kanban\Data\BoardContext;
use Karnoweb\Kanban\Enums\ActionStatus;
use Karnoweb\Kanban\Enums\ActionType;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\EntryRoutingProvider;
use Karnoweb\TaskManager\Handoff\LocalRouteService;
use Karnoweb\TaskManager\Handoff\SubjectRouteRegistry;
use Karnoweb\TaskManager\Scheduling\ReminderService;
use Karnoweb\TaskManager\SubjectRegistry;
use Karnoweb\TaskManager\Tests\Support\Ticketing\User;
use Karnoweb\TaskManager\UI\CardIdentity;
use Karnoweb\TaskManager\UI\Surface;
use Karnoweb\TaskManager\Workflow\EntryRoutingService;
use Karnoweb\TaskManager\Workflow\TaskSubjectHandler;
use Karnoweb\TicketChat\Contracts\ConversationServiceInterface;
use Karnoweb\TicketChat\Contracts\MessageServiceInterface;
use Karnoweb\TicketChat\Models\Conversation;
use Karnoweb\TicketChat\Models\Department;
use Karnoweb\TicketChat\Models\Message;
use Karnoweb\TicketChat\TicketChatServiceProvider;

final class TicketingIntegrationTest extends PhaseSixTestCase
{
    protected function setUp(): void
    {
        if (! getenv('TM_TICKETING_HOST_AUTOLOAD')) {
            self::markTestSkipped('Optional real Ticket Chat host: set TM_TICKETING_HOST_AUTOLOAD.');
        }
        parent::setUp();
        Notification::fake();
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
        DB::table('users')->insert([['id' => 1, 'name' => 'Agent', 'email' => 'agent@example.test', 'password' => 'fixture'], ['id' => 2, 'name' => 'Customer', 'email' => 'customer@example.test', 'password' => 'fixture'], ['id' => 3, 'name' => 'Other agent', 'email' => 'other@example.test', 'password' => 'fixture']]);
        // The phase-six fixture installs its CRM registry in setUp; preserve it and add the real Ticket alias.
        $existing = $this->app->make(SubjectRegistry::class)->all();
        $this->app->instance(SubjectRegistry::class, new SubjectRegistry([...$existing, 'task' => new TaskSubjectHandler($this->service()), 'support.ticket' => $this->app->make(TicketSubject::class)]));
        $this->app->instance(EntryRoutingProvider::class, new DepartmentRouting);
    }

    protected function getPackageProviders($app): array
    {
        require_once getenv('TM_TICKETING_HOST_AUTOLOAD');
        foreach (glob(dirname(__DIR__).'/examples/Ticketing/*.php') as $file) {
            require_once $file;
        }

        return [...parent::getPackageProviders($app), TicketChatServiceProvider::class, TaskManagerTicketingProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('ticket-chat.general.user_model', User::class);
        $app['config']->set('ticket-chat.features.broadcasting', false);
        $app['config']->set('ticket-chat.features.notifications', false);
    }

    protected function manager(string $actor = '1', string $scope = '1'): ExecutionContext
    {
        $base = parent::manager($actor, $scope);

        return new ExecutionContext($scope, $actor, 'ticketing-integration', capabilities: [...$base->capabilities, 'ticket.route', 'ticket.close_result', 'reminder.assign']);
    }

    private function ticket(): array
    {
        $department = Department::query()->create(['name' => 'Support', 'is_active' => true, 'meta' => ['scope_key' => '1']]);
        $department->users()->attach(1, ['role' => 'agent', 'is_active' => true]);
        $department->users()->attach(3, ['role' => 'agent', 'is_active' => true]);
        $ticket = $this->app->make(ConversationServiceInterface::class)->createTicket(['title' => 'Real ticket', 'created_by' => 2, 'department_id' => $department->id, 'branch_id' => 1]);
        $this->app->make(ConversationServiceInterface::class)->assign($ticket, 1);
        $revision = $this->workflows()->publish($this->manager(), 'tickets', 0, 'ticket-workflow', TicketWorkflow::definition());
        $pipeline = $this->pipelines()->create($this->manager(), 'Support', $revision['id'], TicketWorkflow::stages(), 'support-pipeline', 'sequential');

        return [$ticket->fresh(), $department, $revision, $pipeline];
    }

    public function test_real_ticket_entry_assignment_wait_reply_reminder_and_close_result_share_one_truth(): void
    {
        [$ticket, $department, $revision, $pipeline] = $this->ticket();
        $routing = $this->app->make(EntryRoutingService::class);
        $routing->setDefault($this->manager(), 'department:'.$department->id, $pipeline['pipeline_key'], 0, 'default');
        $i = $routing->enter($this->manager(), 'support.ticket', (string) $ticket->id, $revision['id'], 'department:'.$department->id, 'enter');
        $i = $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'review', 'move.open.reviewing');
        $i = $this->instances()->transition($this->manager(), $i['instance_key'], 2, 'wait', 'move.reviewing.waiting');
        self::assertSame('pending', $ticket->fresh()->status->value);
        $this->app->make(MessageServiceInterface::class)->send($ticket->fresh(), 2, 'Customer response');
        self::assertSame('pending', $ticket->fresh()->status->value);
        self::assertSame($i, $this->instances()->get($this->manager(), $i['instance_key']));
        $reminders = $this->app->make(ReminderService::class);
        $r = $reminders->createGeneral($this->manager(), 'Follow up', $this->clock->now()->modify('+1 hour'), 'UTC', 'reminder', 'support.ticket', (string) $ticket->id, '3');
        self::assertSame('3', $r['actor_ref']);
        $updated = $reminders->updateGeneral($this->manager(), $r['reminder_key'], 1, ['body' => 'Follow up tomorrow'], 'reminder-edit');
        self::assertSame('3', $updated['actor_ref']);
        $payload = ['result' => 'Confidential resolution', 'visibility' => 'internal', 'reason_code' => 'resolved'];
        $closed = $this->instances()->executeBusinessCommand($this->manager(), $i['instance_key'], 3, 'close', 'close_result', $payload);
        self::assertSame('closed', $closed['current_state_key']);
        self::assertSame('closed', $ticket->fresh()->status->value);
        self::assertSame($closed, $this->instances()->executeBusinessCommand($this->manager(), $i['instance_key'], 3, 'close', 'close_result', $payload));
        self::assertSame(1, Message::query()->internalNotes()->count());
        self::assertSame('pending', $reminders->get($this->manager(), $r['reminder_key'])['state']);
        self::assertSame(0, DB::table('tm_tasks')->count());
        self::assertStringNotContainsString('Confidential resolution', DB::table('tm_audit_events')->pluck('payload')->implode(''));
    }

    public function test_real_ticket_result_validation_and_outer_rollback_remove_all_partial_host_changes(): void
    {
        [$ticket, $department, $revision, $pipeline] = $this->ticket();
        $i = $this->instances()->start($this->manager(), 'support.ticket', (string) $ticket->id, $revision['id'], 'start', pipelineKey: $pipeline['pipeline_key']);
        $this->error('ticket_result_invalid', fn () => $this->instances()->executeBusinessCommand($this->manager(), $i['instance_key'], 1, 'invalid', 'close_result', ['result' => ' ', 'visibility' => 'internal', 'reason_code' => 'resolved']));
        self::assertSame(0, Message::query()->count());
        $before = DB::table('tm_outbox')->count();
        DB::beginTransaction();
        $this->instances()->executeBusinessCommand($this->manager(), $i['instance_key'], 1, 'rollback', 'close_result', ['result' => 'Will roll back', 'visibility' => 'public', 'reason_code' => 'resolved']);
        self::assertSame(1, Message::query()->count());
        DB::rollBack();
        self::assertSame(0, Message::query()->count());
        self::assertSame('open', $ticket->fresh()->status->value);
        self::assertSame($before, DB::table('tm_outbox')->count());
        self::assertSame(0, DB::table('tm_command_journal')->where('operation_id', 'rollback')->count());
        self::assertSame('open', $this->instances()->get($this->manager(), $i['instance_key'])['current_state_key']);
    }

    public function test_real_department_move_preserves_ticket_owner_reminder_and_hides_previous_private_notes(): void
    {
        [$ticket, $department, $revision, $pipeline] = $this->ticket();
        $department->users()->detach(3);
        $sales = Department::query()->create(['name' => 'Sales', 'is_active' => true, 'meta' => ['scope_key' => '1']]);
        $sales->users()->attach([1 => ['role' => 'admin', 'is_active' => true], 3 => ['role' => 'agent', 'is_active' => true]]);
        $destination = $this->pipelines()->create($this->manager(), 'Sales', $revision['id'], TicketWorkflow::stages(), 'sales-pipeline', 'free');
        $i = $this->instances()->start($this->manager(), 'support.ticket', (string) $ticket->id, $revision['id'], 'start', pipelineKey: $pipeline['pipeline_key']);
        Message::withoutEvents(fn () => Message::query()->create(['conversation_id' => $ticket->id, 'user_id' => 1, 'body' => 'Old support private note', 'type' => 'internal_note', 'meta' => ['department_id' => $department->id]]));
        $reminder = $this->app->make(ReminderService::class)->createGeneral($this->manager(), 'Independent reminder', $this->clock->now()->modify('+1 hour'), 'UTC', 'reminder', 'support.ticket', (string) $ticket->id);
        $this->app->instance(SubjectRouteRegistry::class, new SubjectRouteRegistry(['ticket.department' => new TicketDepartmentRouter(DB::connection(), $this->app->make(TicketSubject::class), $this->app->make(TicketWrites::class), [$destination['pipeline_key'] => $sales->id])]));
        $preview = $this->instances()->previewMove($this->manager(), $i['instance_key'], $destination['pipeline_key'], ['open' => 'open']);
        $moved = $this->app->make(LocalRouteService::class)->move($this->manager(), 'ticket.department', $preview, 'sales_identified', 'move');
        self::assertSame($i['instance_key'], $moved['instance_key']);
        self::assertSame($i['owner_ref'], $moved['owner_ref']);
        self::assertSame('open', $moved['current_state_key']);
        self::assertSame($sales->id, $ticket->fresh()->department_id);
        self::assertSame(2, $ticket->fresh()->created_by);
        self::assertSame('pending', $this->app->make(ReminderService::class)->get($this->manager(), $reminder['reminder_key'])['state']);
        $access = new TicketMessageAccess(DB::connection(), $this->app->make(TicketSubject::class));
        self::assertSame(0, $access->query($this->manager('3'), (string) $ticket->id)->count());
        self::assertSame(1, $access->query($this->manager(), (string) $ticket->id)->count());
        self::assertSame(1, Conversation::query()->count());
        self::assertSame(1, DB::table('tm_workflow_instances')->count());
    }

    public function test_actual_kanban_contract_advertises_scalar_policy_metadata_and_denies_a_sequential_jump(): void
    {
        [$ticket, $department, $revision, $pipeline] = $this->ticket();
        $i = $this->instances()->start($this->manager(), 'support.ticket', (string) $ticket->id, $revision['id'], 'start', pipelineKey: $pipeline['pipeline_key']);
        $board = $this->board(new Surface('tickets', ['support.ticket'], ['support.ticket' => $pipeline['pipeline_key']]));
        $ui = new BoardContext('tickets', '1', '1', 'test');
        $id = CardIdentity::make('support.ticket', $i['instance_key']);
        $card = $board->card($ui, $id);
        $candidates = json_decode($card->metadata['transition_candidates_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['reviewing'], array_column($candidates, 'state'));
        $result = $board->execute(new ActionCommand(operationId: 'jump', type: ActionType::MoveCard, payload: ['cardId' => $id, 'toStageId' => CardIdentity::make('support.ticket', 'waiting')], expectedVersion: '1'), $ui);
        self::assertSame(ActionStatus::DOMAIN_REJECTED, $result->status);
        self::assertSame('open', $ticket->fresh()->status->value);
        self::assertSame(0, DB::table('tm_instance_transitions')->count());
    }

    public function test_default_recipient_is_suggested_at_creation_but_edit_and_retry_keep_the_original_choice(): void
    {
        [$ticket] = $this->ticket();
        $owner = $this->app->make(ConversationServiceInterface::class);
        $owner->assign($ticket, 3);
        $service = $this->app->make(ReminderService::class);
        self::assertSame('3', $service->suggestedRecipient($this->manager(), 'support.ticket', (string) $ticket->id));
        $due = $this->clock->now()->modify('+1 hour');
        $first = $service->createGeneral($this->manager(), 'Suggested', $due, 'UTC', 'create', 'support.ticket', (string) $ticket->id);
        self::assertSame('3', $first['actor_ref']);
        $owner->assign($ticket->fresh(), 1);
        $edited = $service->updateGeneral($this->manager(), $first['reminder_key'], 1, ['body' => 'Only text edited'], 'edit');
        self::assertSame('3', $edited['actor_ref']);
        $retry = $service->createGeneral($this->manager(), 'Suggested', $due, 'UTC', 'create', 'support.ticket', (string) $ticket->id);
        self::assertSame($first, $retry);
        self::assertSame(1, DB::table('tm_reminders')->count());
    }

    public function test_retiring_operational_board_keeps_real_ticket_status_priority_assignment_and_reminder(): void
    {
        [$ticket, $department, $revision, $pipeline] = $this->ticket();
        $i = $this->instances()->start($this->manager(), 'support.ticket', (string) $ticket->id, $revision['id'], 'start', pipelineKey: $pipeline['pipeline_key']);
        $reminder = $this->app->make(ReminderService::class)->createGeneral($this->manager(), 'Keep reminder', $this->clock->now()->modify('+1 hour'), 'UTC', 'reminder', 'support.ticket', (string) $ticket->id);
        $before = $ticket->fresh()->getAttributes();
        $routing = $this->app->make(EntryRoutingService::class);
        $routing->retirePipeline($this->manager(), $pipeline['pipeline_key'], 1, 'retire');
        self::assertSame(['processed' => 1, 'visible_remaining' => 0], $routing->detachRetired($this->manager(), $pipeline['pipeline_key']));
        self::assertSame($before, $ticket->fresh()->getAttributes());
        self::assertSame('pending', $this->app->make(ReminderService::class)->get($this->manager(), $reminder['reminder_key'])['state']);
        $after = $this->instances()->get($this->manager(), $i['instance_key']);
        self::assertNull($after['pipeline_id']);
        self::assertSame('open', $after['current_state_key']);
        self::assertSame(1, Conversation::query()->count());
    }
}
