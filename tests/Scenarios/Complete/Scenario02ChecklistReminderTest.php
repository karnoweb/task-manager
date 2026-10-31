<?php

namespace Karnoweb\TaskManager\Tests\Scenarios\Complete;

use Karnoweb\TaskManager\Collaboration\ChecklistService;
use Karnoweb\TaskManager\Scheduling\ReminderService;
use Karnoweb\TaskManager\Tests\PhaseFiveTestCase;

final class Scenario02ChecklistReminderTest extends PhaseFiveTestCase
{
    public function test_complete_scenario_02(): void
    {

        $task = $this->todo('master');
        $checks = app(ChecklistService::class);
        $list = $checks->create($this->manager(), $task['public_key'], 'Checks', 'checklist');
        $item = $checks->addItem($this->manager(), $list['public_key'], 1, 'Verify', null, null, 'item');
        $checks->changeItem($this->manager(), $item['public_key'], 1, ['done' => true], 'checked');
        self::assertSame(100.0, $checks->progress($this->manager(), $task['public_key'])['percent']);
        $reminders = app(ReminderService::class);
        $reminder = $reminders->createGeneral($this->manager(), 'Follow up', new \DateTimeImmutable('2090-01-01T09:00:00Z'), 'UTC', 'reminder', 'task', $task['public_key']);
        $task = $this->service()->get($this->manager(), $task['public_key']);
        $series = $this->recurrence()->create($this->manager(), $task['public_key'], $task['version'], 'gregorian', '2026-10-20 09:00:00', 'UTC', ['frequency' => 'daily', 'after_completion' => true], 'series');
        $from = new \DateTimeImmutable('2026-10-20');
        $until = $from->modify('+10 days');
        self::assertSame([], $this->recurrence()->generate($this->manager(), $series['series_key'], 1, $from, $until, 'before')['created']);
        $this->service()->mutate($this->manager(), $task['public_key'], $task['version'], 'complete', 'complete');
        $result = $this->recurrence()->generate($this->manager(), $series['series_key'], 1, $from, $until, 'after');
        self::assertCount(1, $result['created']);
        self::assertSame([], $this->recurrence()->generate($this->manager(), $series['series_key'], 1, $from, $until, 'still-pending')['created']);
        $next = $this->service()->get($this->manager(), $result['created'][0]);
        $this->service()->mutate($this->manager(), $next['public_key'], $next['version'], 'complete-next', 'complete');
        self::assertCount(1, $this->recurrence()->generate($this->manager(), $series['series_key'], 1, $from, $until, 'next')['created']);

    }
}
