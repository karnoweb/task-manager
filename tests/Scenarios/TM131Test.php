<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\PhaseSixTestCase;
use Karnoweb\TaskManager\UI\IntegrationFactory;

final class TM131Test extends PhaseSixTestCase
{
    public function test_scenario(): void
    {
        [$lead,$deal] = $this->crm();
        $service = $this->app->make(IntegrationFactory::class)->followups();
        $link = $service->create($this->manager(), 'crm.deal', (string) $deal->id, 'Follow-up', 'followup');
        self::assertSame($link, $service->create($this->manager(), 'crm.deal', (string) $deal->id, 'Follow-up', 'followup'));
        self::assertSame(1, DB::table('tm_tasks')->count());
        $this->error('not_found', fn () => $service->tasks($this->manager('2'), 'crm.deal', (string) $deal->id));
        $deal->forceFill(['assigned_to' => 2])->save();
        $this->error('not_found', fn () => $service->tasks($this->manager(), 'crm.deal', (string) $deal->id));
        self::assertSame([], $service->tasks($this->manager('2'), 'crm.deal', (string) $deal->id));
    }
}
