<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\Crm\Models\DealStageHistory;
use Karnoweb\TaskManager\Tests\PhaseSixTestCase;
use Karnoweb\TaskManager\UI\IntegrationFactory;

final class TM088Test extends PhaseSixTestCase
{
    public function test_scenario(): void
    {
        [$lead,$deal] = $this->crm();
        $before = $deal->getAttributes();
        $association = $this->app->make(IntegrationFactory::class)->followups()->create($this->manager(), 'crm.deal', (string) $deal->id, 'Follow-up for deal '.$deal->id, 'task');
        $t = $this->service()->get($this->manager(), $association['task_key']);
        self::assertCount(1, $this->app->make(IntegrationFactory::class)->followups()->tasks($this->manager(), 'crm.deal', (string) $deal->id));
        $this->service()->mutate($this->manager(), $t['public_key'], $t['version'], 'finish', 'complete');
        self::assertSame($before, $deal->fresh()->getAttributes());
        self::assertSame(0, DealStageHistory::query()->count());
    }
}
