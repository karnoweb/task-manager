<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM025Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        [$r,$p,$t] = $this->projectTask();
        $target = $this->projects()->create($c, 'NEW', 'New', $r['id'], 'target');
        $preview = $this->projects()->previewTaskMove($c, $t['public_key'], $target['project_key'], 'task', ['new' => 'review']);
        $moved = $this->projects()->moveTask($c, $preview, 'move');
        self::assertSame($t['public_key'], $moved['public_key']);
        self::assertSame($target['id'], $moved['project_id']);
        self::assertSame('review', $moved['state']);
        $other = $this->manager('a', 'two');
        $this->error('not_found', fn () => $this->projects()->previewTaskMove($other, $t['public_key'], $target['project_key'], 'task', ['review' => 'new']));
    }
}
