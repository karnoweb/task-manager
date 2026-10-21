<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\HierarchyTestCase;

final class TM027Test extends HierarchyTestCase
{
    public function test_scenario(): void
    {
        $root = $this->todo('root');
        $one = $this->attach($this->todo('one'), $root, 'attach-one');
        $two = $this->attach($this->todo('two'), $root, 'attach-two');
        self::assertSame(0.0, $this->hierarchy()->progress($this->manager(), $root['public_key'])['percent']);
        $this->service()->mutate($this->manager(), $one['public_key'], $one['version'], 'done-one', 'complete');
        self::assertSame(50.0, $this->hierarchy()->progress($this->manager(), $root['public_key'])['percent']);
        $this->service()->mutate($this->manager(), $two['public_key'], $two['version'], 'done-two', 'complete');
        self::assertSame(100.0, $this->hierarchy()->progress($this->manager(), $root['public_key'])['percent']);
        self::assertSame('pending', $this->service()->get($this->manager(), $root['public_key'])['state']);
    }
}
