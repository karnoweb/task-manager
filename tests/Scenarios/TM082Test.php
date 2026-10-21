<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\PhaseSixTestCase;
use Symfony\Component\Process\Process;

final class TM082Test extends PhaseSixTestCase
{
    public function test_scenario(): void
    {
        $p = new Process([PHP_BINARY, dirname(__DIR__, 2).'/tools/run_phase6_profile.php', 'calendar-only'], dirname(__DIR__, 2));
        $p->setTimeout(90);
        $p->run();
        self::assertSame(0, $p->getExitCode(), $p->getOutput().$p->getErrorOutput());
        self::assertStringContainsString('OK (', $p->getOutput());
    }
}
