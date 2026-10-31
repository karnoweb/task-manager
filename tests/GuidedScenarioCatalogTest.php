<?php

namespace Karnoweb\TaskManager\Tests;

use PHPUnit\Framework\TestCase;

final class GuidedScenarioCatalogTest extends TestCase
{
    public function test_fifteen_independent_scenarios_and_preserved_regressions_exist(): void
    {
        $root = dirname(__DIR__);
        $directory = $root.'/tests/Scenarios/Complete';
        $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(15, $manifest);
        foreach ($manifest as $scenario) {
            self::assertFileExists($directory.'/'.$scenario['id'].'.md');
            self::assertNotEmpty($scenario['methods']);
            self::assertFileExists($root.'/'.$scenario['file']);
            foreach ($scenario['methods'] as $method) {
                self::assertTrue(method_exists($scenario['class'], $method));
                $reflection = new \ReflectionMethod($scenario['class'], $method);
                self::assertSame($scenario['class'], $reflection->getDeclaringClass()->getName());
                self::assertGreaterThanOrEqual(15, $reflection->getEndLine() - $reflection->getStartLine());
            }
            foreach ($scenario['preserved_methods'] as [$class, $method]) {
                self::assertTrue(method_exists(__NAMESPACE__.'\\'.$class, $method), $class.'::'.$method);
            }
        }
    }

    public function test_all_original_tm_scenarios_remain_and_have_reading_mapping(): void
    {
        $root = dirname(__DIR__);
        $mapping = file_get_contents($root.'/tests/Scenarios/COVERAGE-MAPPING.md');
        $tests = glob($root.'/tests/Scenarios/TM*Test.php');
        self::assertCount(236, $tests);
        foreach ($tests as $file) {
            self::assertStringContainsString(basename($file), $mapping);
        }
    }

    public function test_every_guide_and_mapping_link_resolves(): void
    {
        $directory = __DIR__.'/Scenarios';
        foreach ([...glob($directory.'/*.md'), ...glob($directory.'/Complete/*.md')] as $file) {
            preg_match_all('/\[[^\]]+\]\(([^)#]+)(?:#[^)]*)?\)/u', file_get_contents($file), $matches);
            foreach ($matches[1] as $link) {
                self::assertNotFalse(realpath(dirname($file).'/'.$link), basename($file).' -> '.$link);
            }
        }
    }
}
