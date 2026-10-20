<?php

namespace Karnoweb\TaskManager\Tests\Support;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\Responsibility\AssignmentEngine;
use Karnoweb\TaskManager\Responsibility\ContextResponsibilityResolver;
use Karnoweb\TaskManager\SLA\CalendarRegistry;
use Karnoweb\TaskManager\SLA\SlaEngine;
use Karnoweb\TaskManager\StageLifecycleChain;

final class WorkerLifecycle
{
    public static function make(Connection $db, Clock $clock, ?SlaEngine $sla = null): StageLifecycleChain
    {
        $resolver = $db->getSchemaBuilder()->hasTable('host_people') ? new HostResponsibilityResolver($db) : new ContextResponsibilityResolver();
        return new StageLifecycleChain([new \Karnoweb\TaskManager\Approval\ApprovalEngine($db, $clock, $resolver), new AssignmentEngine($db, $clock, $resolver), $sla ?? new SlaEngine($db, $clock, new CalendarRegistry())]);
    }
}
