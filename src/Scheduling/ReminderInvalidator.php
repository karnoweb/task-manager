<?php

namespace Karnoweb\TaskManager\Scheduling;

use Illuminate\Database\Connection;

final class ReminderInvalidator
{
    public static function cancelTask(Connection $db, int $taskId): void
    {
        $db->table('tm_reminders')->where('task_id', $taskId)->whereIn('state', ['pending', 'sent', 'invalidated'])->update(['state' => 'cancelled']);
    }
}
