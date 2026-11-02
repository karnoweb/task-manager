<?php

namespace Karnoweb\TaskManager;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Schema\Tables;

/**
 * Delivery is at least once. Consumers must deduplicate by event_id.
 * `attempts` counts tries. `published_at` is written only after the consumer returns, so a throw leaves the event unpublished.
 * An action filter does not acknowledge any other action.
 */
final class OutboxDispatcher
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock = new SystemClock)
    {
        Tables::assertConnection($db);
    }

    /**
     * @param  string|null  $action  When set, only this outbox action is offered. Other actions stay unpublished for their own consumers.
     */
    public function dispatch(string $scope, callable $consumer, int $limit = 100, ?string $action = null): int
    {
        if ($this->db->transactionLevel() !== 0) {
            throw new DomainError('dispatch_before_commit');
        }
        if (trim($scope) === '' || $limit < 1 || $limit > 1000 || ($action !== null && ! preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $action))) {
            throw new DomainError('dispatch_context_invalid');
        }
        $events = $this->db->table(Tables::name('outbox'))->where('scope_key', $scope)->whereNull('published_at')
            ->when($action !== null, fn ($query) => $query->where('action', $action))
            ->orderBy('event_id')->limit($limit)->get();
        $delivered = 0;
        foreach ($events as $event) {
            $this->db->table(Tables::name('outbox'))->where('event_id', $event->event_id)->increment('attempts');
            $consumer((array) $event);
            $this->db->table(Tables::name('outbox'))->where('event_id', $event->event_id)->update(['published_at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
            $delivered++;
        }

        return $delivered;
    }
}
