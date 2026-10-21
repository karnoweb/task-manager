<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Enterprise\CustomFieldService;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Integration\AutomationService;

final class EnterpriseHardeningTest extends PhaseFiveTestCase
{
    public function test_automation_current_state_conditions_throttle_and_rule_disabling_are_guarded(): void
    {
        $base = $this->manager();
        $worker = new ExecutionContext('one', 'a', 'worker', true, $base->capabilities);
        $automation = $this->app->make(AutomationService::class);
        $rule = $automation->publish($base, null, 0, 'created', ['conditions' => [['field' => 'status_category', 'operator' => 'equals', 'value' => 'todo']], 'hourly_limit' => 1, 'actions' => [['type' => 'notify']]], 'rule');
        $a = $this->todo('A');
        $event = DB::table('tm_outbox')->where('task_id', $a['id'])->where('action', 'created')->first();
        self::assertSame('completed', $automation->run($worker, $event->event_id)['actions'][0]['state']);
        $b = $this->todo('B');
        $eventB = DB::table('tm_outbox')->where('task_id', $b['id'])->where('action', 'created')->first();
        self::assertSame('rate_limited', $automation->run($worker, $eventB->event_id)['actions'][0]['reason_code']);
        $automation->toggle($base, $rule['rule_key'], 1, false, 'disable');
        self::assertSame([], $automation->run($worker, $eventB->event_id)['actions']);
        self::assertSame(2, DB::table('tm_automation_rule_revisions')->count());
    }

    public function test_restricted_field_forms_respect_host_authorization_before_paging(): void
    {
        $base = $this->manager();
        $context = new ExecutionContext('one', 'a', 'restricted', capabilities: [...$base->capabilities, 'finance.read']);
        $fields = $this->app->make(CustomFieldService::class);
        $fields->create($context, 'Private', 'number', [], 'finance.read', 'restricted');
        $this->app->instance(AuthorizationProvider::class, new class implements AuthorizationProvider
        {
            public function authorize(ExecutionContext $context, string $capability): void
            {
                if ($capability === 'finance.read') {
                    throw new DomainError('access_denied');
                }
            }

            public function constrain(Builder $query, ExecutionContext $context, string $resource, string $capability): void
            {
            }
        });
        self::assertSame([], $this->app->make(CustomFieldService::class)->forms($context));
    }

    public function test_moved_occurrence_is_listed_by_its_override_range_without_fabricating_new_identity(): void
    {
        $task = $this->todo('Move');
        $series = $this->recurrence()->create($this->manager(), $task['public_key'], 1, 'gregorian', '2026-10-21 09:00:00', 'UTC', ['frequency' => 'daily', 'count' => 1], 'series');
        $from = new \DateTimeImmutable('2026-10-21');
        $until = $from->modify('+1 day');
        $made = $this->recurrence()->generate($this->manager(), $series['series_key'], 1, $from, $until, 'generate');
        $child = $this->service()->get($this->manager(), $made['created'][0]);
        $row = DB::table('tm_recurrence_occurrences')->first();
        $this->recurrence()->exception($this->manager(), $series['series_key'], 1, $row->occurrence_key, $child['version'], new \DateTimeImmutable('2026-10-25T09:00:00Z'), false, 'move');
        self::assertSame([], $this->recurrence()->preview($this->manager(), $series['series_key'], $from, $until));
        $moved = $this->recurrence()->preview($this->manager(),$series['series_key'],new \DateTimeImmutable('2026-10-25'),new \DateTimeImmutable('2026-10-26'));
        self::assertCount(1,$moved);
        self::assertSame($row->occurrence_key,$moved[0]['occurrence_key']);
    }
}
