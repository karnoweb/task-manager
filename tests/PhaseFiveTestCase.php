<?php

namespace Karnoweb\TaskManager\Tests;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Scheduling\DateService;
use Karnoweb\TaskManager\Scheduling\RecurrenceService;

abstract class PhaseFiveTestCase extends AgileTestCase
{
    protected function manager(string $actor = 'a', string $scope = 'one'): ExecutionContext
    {
        $base = parent::manager($actor, $scope);

        return new ExecutionContext($scope, $actor, 'phase-five', capabilities: [...$base->capabilities, 'recurrence.manage', 'reminder.manage', 'custom_field.manage', 'custom_field.view', 'automation.manage', 'webhook.manage', 'export.create', 'subscription.manage']);
    }

    protected function dates(): DateService
    {
        return $this->app->make(DateService::class);
    }

    protected function recurrence(): RecurrenceService
    {
        return $this->app->make(RecurrenceService::class);
    }
}
