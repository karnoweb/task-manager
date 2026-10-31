<?php

namespace Karnoweb\TaskManager\Tests\Scenarios\Complete;

use Karnoweb\TaskManager\Tests\PhaseFourTestCase;

final class Scenario04HierarchyTest extends PhaseFourTestCase
{
    public function test_complete_scenario_04(): void
    {

        $root = $this->todo('root');
        $one = $this->attach($this->todo('one'), $root, 'attach-one');
        $two = $this->attach($this->todo('two'), $root, 'attach-two');
        $this->link($one, $two);
        $this->error('dependency_cycle', fn () => $this->link($two, $one, operation: 'cycle'));
        $one = $this->service()->get($this->manager(), $one['public_key']);
        $two = $this->service()->get($this->manager(), $two['public_key']);
        self::assertSame(0.0, $this->hierarchy()->progress($this->manager(), $root['public_key'])['percent']);
        $this->service()->mutate($this->manager(), $one['public_key'], $one['version'], 'done-one', 'complete');
        self::assertSame(50.0, $this->hierarchy()->progress($this->manager(), $root['public_key'])['percent']);
        $this->service()->mutate($this->manager(), $two['public_key'], $two['version'], 'done-two', 'complete');
        self::assertSame(100.0, $this->hierarchy()->progress($this->manager(), $root['public_key'])['percent']);
        self::assertSame('pending', $this->service()->get($this->manager(), $root['public_key'])['state']);

    }
}
