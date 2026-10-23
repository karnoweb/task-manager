<?php

namespace App\TaskManager;

use Illuminate\Support\ServiceProvider;
use Karnoweb\TaskManager\Contracts\EntryRoutingProvider;
use Karnoweb\TaskManager\Contracts\ReminderRecipients;
use Karnoweb\TaskManager\SubjectRegistry;
use Karnoweb\TaskManager\Workflow\WorkflowService;

final class TaskManagerTicketingProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TicketWrites::class, fn ($app) => new TicketWrites($app['db']->connection()));
        $this->app->bind(TicketSubject::class, fn ($app) => new TicketSubject($app['db']->connection(), $app->make(WorkflowService::class), $app->make(TicketWrites::class)));
        $this->app->bind(EntryRoutingProvider::class, DepartmentRouting::class);
        $this->app->bind(ReminderRecipients::class, fn ($app) => new TicketReminderRecipients($app['db']->connection(), $app->make(TicketSubject::class)));
    }

    public function boot(): void
    {
        $handlers = $this->app->make(SubjectRegistry::class)->all();
        $this->app->instance(SubjectRegistry::class, new SubjectRegistry([...$handlers, 'support.ticket' => $this->app->make(TicketSubject::class)]));
    }
}
