<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\SLA\CalendarRegistry;
use Karnoweb\TaskManager\SLA\WeeklyWorkingCalendar;
use Karnoweb\TaskManager\TaskGraph\DependencyService;
use Karnoweb\TaskManager\Tests\Support\FrozenClock;

abstract class PhaseFourTestCase extends HierarchyTestCase
{
    protected FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FrozenClock;
        $this->app->instance(Clock::class, $this->clock);
        $shifts = [];
        foreach ([6, 7, 1, 2, 3, 4] as $day) {
            $shifts[$day] = [['09:00', '17:00']];
        }
        $this->app->instance(CalendarRegistry::class, new CalendarRegistry(['iran-v1' => new WeeklyWorkingCalendar('Asia/Tehran', $shifts)]));
    }

    protected function manager(string $actor = 'a', string $scope = 'one'): ExecutionContext
    {
        $base = parent::manager($actor, $scope);

        return new ExecutionContext($scope, $actor, 'phase-four', capabilities: [...$base->capabilities, 'dependency.manage', 'schedule.update', 'report.view', 'task.reorder',
            'checklist.manage', 'comment.create', 'comment.edit_own', 'comment.moderate', 'attachment.add', 'attachment.remove', 'attachment.download', 'watcher.manage',
            'template.manage', 'sprint.manage', 'backlog.manage', 'milestone.manage', 'release.manage', 'time.log', 'time.manage_others', 'bulk.manage']);
    }

    protected function dependencies(): DependencyService
    {
        return $this->app->make(DependencyService::class);
    }

    protected function link(array $a, array $b, string $type = 'FS', array $policy = [], string $operation = 'dependency'): array
    {
        $a = $this->service()->get($this->manager(), $a['public_key']);
        $b = $this->service()->get($this->manager(), $b['public_key']);

        return $this->dependencies()->add($this->manager(), $a['public_key'], $a['version'], $b['public_key'], $b['version'], $type, $policy, $operation);
    }

    protected function step(array $task, string $edge, string $operation): array
    {
        $instance = DB::table('tm_workflow_instances')->where('task_id', $task['id'])->where('slot_key', 'main')->orderByDesc('id')->first();

        return $this->instances()->transition($this->manager(), $instance->instance_key, $instance->version, $operation, $edge, $edge === 'approve' ? ['reason_code' => 'approved'] : []);
    }
}
