<?php

namespace Karnoweb\TaskManager\Scheduling;

use Illuminate\Database\Connection;

final class ReminderSchedule
{
    public static function refresh(Connection $db, object $task, array $data): void
    {
        $reminders = $db->table('tm_reminders')->where('task_id', $task->id)->whereIn('state', ['pending', 'sent', 'invalidated'])->orderBy('id');
        $reminders->chunkById(500, function ($rows) use ($db, $task, $data) {
            foreach ($rows as $reminder) {
                $anchor = match ($reminder->anchor) {
                    'due' => $data['due_at'], 'start' => $data['starts_at'], default => $reminder->absolute_at
                };
                $oldAnchor = match ($reminder->anchor) {
                    'due' => $task->due_at, 'start' => $task->starts_at, default => $reminder->absolute_at
                };
                if ($anchor === $oldAnchor || ($anchor !== null && $oldAnchor !== null && (new \DateTimeImmutable($anchor, new \DateTimeZone('UTC')))->format('U.u') === (new \DateTimeImmutable($oldAnchor, new \DateTimeZone('UTC')))->format('U.u'))) {
                    $db->table('tm_reminders')->where('id', $reminder->id)->update(['source_revision' => $task->date_revision + 1]);

                    continue;
                }
                $scheduled = $anchor === null ? null : LocalTime::stamp((new \DateTimeImmutable($anchor, new \DateTimeZone('UTC')))->modify(($reminder->offset_seconds >= 0 ? '+' : '').$reminder->offset_seconds.' seconds'));
                $db->table('tm_reminders')->where('id', $reminder->id)->update(['source_revision' => $task->date_revision + 1, 'scheduled_at' => $scheduled, 'state' => $scheduled === null ? 'invalidated' : 'pending', 'generation' => $reminder->generation + 1, 'version' => $reminder->version + 1]);
            }
        });
    }
}
