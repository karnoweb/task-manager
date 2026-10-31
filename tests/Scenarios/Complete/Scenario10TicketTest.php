<?php

namespace Karnoweb\TaskManager\Tests\Scenarios\Complete;

use App\TaskManager\DepartmentRouting;
use App\TaskManager\TaskManagerTicketingProvider;
use App\TaskManager\TicketSubject;
use App\TaskManager\TicketWorkflow;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\EntryRoutingProvider;
use Karnoweb\TaskManager\Scheduling\ReminderService;
use Karnoweb\TaskManager\SubjectRegistry;
use Karnoweb\TaskManager\Tests\PhaseSixTestCase;
use Karnoweb\TaskManager\Tests\Support\Ticketing\User;
use Karnoweb\TaskManager\Workflow\EntryRoutingService;
use Karnoweb\TaskManager\Workflow\TaskSubjectHandler;
use Karnoweb\TicketChat\Contracts\ConversationServiceInterface;
use Karnoweb\TicketChat\Contracts\MessageServiceInterface;
use Karnoweb\TicketChat\Models\Department;
use Karnoweb\TicketChat\Models\Message;
use Karnoweb\TicketChat\TicketChatServiceProvider;

final class Scenario10TicketTest extends PhaseSixTestCase
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
        foreach (glob(dirname(dirname(__DIR__, 2)).'/examples/Ticketing/*.php') as $file) {
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

    public function test_complete_scenario_10(): void
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
}
