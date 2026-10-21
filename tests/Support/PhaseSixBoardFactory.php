<?php

namespace Karnoweb\TaskManager\Tests\Support;

use Karnoweb\Kanban\Contracts\BoardFactory;
use Karnoweb\Kanban\Data\BoardContext;
use Karnoweb\Kanban\Data\BoardData;
use Karnoweb\Kanban\Data\BoardDefinition;
use Karnoweb\Kanban\Support\BoardBuilder;
use Karnoweb\TaskManager\UI\Kanban\TaskBoard;

final class PhaseSixBoardFactory implements BoardFactory
{
    public function make(BoardContext $context): BoardDefinition
    {
        $b = (new BoardBuilder)->key($context->boardKey)->board(new BoardData($context->boardKey, 'Host board', $context->scopeKey))->settings(['interaction' => ['transition_policy' => 'allow'], 'features' => ['reorder_cards' => false]]);
        if ($context->boardKey === 'my.tasks') {
            return $b->provider(TaskBoard::class)->handler(TaskBoard::class)->detailsProvider(TaskBoard::class)->build();
        }

        return $b->sources($context->boardKey === 'crm.native' ? ['deals'] : ['orders'])->build();
    }
}
