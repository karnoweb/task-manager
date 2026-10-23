<?php

namespace App\TaskManager;

use Karnoweb\TaskManager\Workflow\Definition;

final class TicketWorkflow
{
    public const STATUS = ['open' => 'open', 'reviewing' => 'in_progress', 'waiting' => 'pending', 'done' => 'resolved', 'closed' => 'closed', 'archived' => 'archived'];

    public static function definition(): array
    {
        $states = array_fill_keys(array_keys(self::STATUS), ['terminal' => 'none']);
        $states['closed'] = $states['archived'] = ['terminal' => 'success'];

        return Definition::stateMachine($states, 'open', ['close_result' => ['to' => 'closed', 'capability' => 'ticket.close_result', 'required_fields' => ['result', 'visibility'], 'reason_required' => true, 'reason_codes' => ['resolved']]]);
    }

    public static function stages(): array
    {
        return array_combine(array_keys(self::STATUS), array_map(fn ($state) => ['state' => $state], array_keys(self::STATUS)));
    }
}
