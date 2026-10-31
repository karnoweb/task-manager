<?php

namespace Karnoweb\TaskManager\Scheduling;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\Schema\Tables;

final class ReminderInvalidator
{
    public static function cancelTask(Connection $db, int $taskId): void
    {
        $db->table(Tables::name('reminders'))->where('task_id', $taskId)->whereIn('state', ['pending', 'sent', 'invalidated'])->update(['state' => 'cancelled']);
    }
}
