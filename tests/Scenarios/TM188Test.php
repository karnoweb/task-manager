<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\AssignmentTestCase;

final class TM188Test extends AssignmentTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        [$i,$v,$p] = $this->instanceWithSla(['duration_seconds' => 7200]);
        $this->assignments()->configureStage($c, $p['pipeline_key'], 'review', 2, 'assignment-policy', ['mode' => 'team', 'reference' => 'contracts']);
        $this->clock->advance('+30 minutes');
        $next = $this->instances()->transition($c, $i['instance_key'], 1, 'review', 'review');
        self::assertSame('a', $next['owner_ref']);
        self::assertSame('c', $next['primary_assignee']);
        $history = $this->assignments()->get($c, $i['instance_key'])['history'];
        self::assertCount(2, $history);
        self::assertSame('a', $history[1]['actor_ref']);
        self::assertSame('c', $history[0]['actor_ref']);
        self::assertSame('2026-10-20 00:30:00', $history[1]['active_to']);
        self::assertNull($history[0]['active_to']);
    }
}
