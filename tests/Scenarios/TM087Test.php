<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\PhaseSixTestCase;
use Symfony\Component\Process\Process;

final class TM087Test extends PhaseSixTestCase
{
    public function test_scenario(): void
    {
        foreach (['kanban-only', 'calendar-only', 'all'] as $profile) {
            $p = new Process([PHP_BINARY, dirname(__DIR__, 2).'/tools/run_phase6_profile.php', $profile], dirname(__DIR__, 2));
            $p->setTimeout(90);
            $p->run();
            self::assertSame(0, $p->getExitCode(), $p->getOutput().$p->getErrorOutput());
            self::assertStringContainsString('OK (', $p->getOutput());
        }
    }
}
