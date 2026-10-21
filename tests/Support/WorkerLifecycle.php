<?php

namespace Karnoweb\TaskManager\Tests\Support;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\Approval\ApprovalEngine;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\Handoff\HandoffLifecycle;
use Karnoweb\TaskManager\Responsibility\AssignmentEngine;
use Karnoweb\TaskManager\Responsibility\ContextResponsibilityResolver;
use Karnoweb\TaskManager\SLA\CalendarRegistry;
use Karnoweb\TaskManager\SLA\SlaEngine;
use Karnoweb\TaskManager\StageLifecycleChain;
use Karnoweb\TaskManager\TaskGraph\NativeTaskRules;

final class WorkerLifecycle
{
    public static function make(Connection $db, Clock $clock, ?SlaEngine $sla = null): StageLifecycleChain
    {
        $resolver = $db->getSchemaBuilder()->hasTable('host_people') ? new HostResponsibilityResolver($db) : new ContextResponsibilityResolver;

        return new StageLifecycleChain([new NativeTaskRules($db, $clock, new CalendarRegistry), new HandoffLifecycle, new ApprovalEngine($db, $clock, $resolver), new AssignmentEngine($db, $clock, $resolver), $sla ?? new SlaEngine($db, $clock, new CalendarRegistry)]);
    }
}
