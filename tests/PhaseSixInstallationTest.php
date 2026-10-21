<?php

namespace Karnoweb\TaskManager\Tests;

use Symfony\Component\Process\Process;

final class PhaseSixInstallationTest extends PhaseSixTestCase
{
    public function test_kanban_only_host_runs_with_task_manager_absent(): void
    {
        $p = new Process([PHP_BINARY, dirname(__DIR__).'/tools/run_phase6_profile.php', 'kanban-only'], dirname(__DIR__));
        $p->setTimeout(90);
        $p->run();
        self::assertSame(0, $p->getExitCode(), $p->getOutput().$p->getErrorOutput());
        self::assertStringContainsString('OK (', $p->getOutput());
    }

    public function test_calendar_only_host_runs_with_task_manager_absent(): void
    {
        $p = new Process([PHP_BINARY, dirname(__DIR__).'/tools/run_phase6_profile.php', 'calendar-only'], dirname(__DIR__));
        $p->setTimeout(90);
        $p->run();
        self::assertSame(0, $p->getExitCode(), $p->getOutput().$p->getErrorOutput());
        self::assertStringContainsString('OK (', $p->getOutput());
    }

    public function test_task_only_host_runs_todo_project_facade_and_reporting_without_ui(): void
    {
        $p = new Process([PHP_BINARY, dirname(__DIR__).'/tools/run_phase6_profile.php', 'task-only'], dirname(__DIR__));
        $p->setTimeout(90);
        $p->run();
        self::assertSame(0, $p->getExitCode(), $p->getOutput().$p->getErrorOutput());
        self::assertStringContainsString('OK (', $p->getOutput());
    }

    public function test_orders_work_in_both_independent_ui_hosts_and_the_combined_host(): void
    {
        foreach (['kanban-only', 'calendar-only', 'all'] as $profile) {
            $p = new Process([PHP_BINARY, dirname(__DIR__).'/tools/run_phase6_profile.php', $profile], dirname(__DIR__));
            $p->setTimeout(90);
            $p->run();
            self::assertSame(0, $p->getExitCode(), $p->getOutput().$p->getErrorOutput());
            self::assertStringContainsString('OK (', $p->getOutput());
        }
    }
}
