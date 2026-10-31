<?php

namespace Karnoweb\TaskManager\Integration;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\Contracts\NotificationPreferences;
use Karnoweb\TaskManager\Contracts\NotificationTransport;
use Karnoweb\TaskManager\Schema\Tables;

final class NotificationConsumer
{
    public function __construct(private readonly Connection $db, private readonly NotificationPreferences $preferences, private readonly NotificationTransport $transport, private readonly Clock $clock)
    {
        Tables::assertConnection($db);
    }

    public function deliver(ExecutionContext $context, array $event): void
    {
        $context->validateTenant();
        $data = $event['data'];
        if (str_starts_with($event['event_type'], 'sla_') && isset($data['visit_id']) && ! $this->db->table(Tables::name('stage_visits'))->where('scope_key', $context->scope)->where('id', $data['visit_id'])->whereNull('exited_at')->exists()) {
            return;
        } if ($event['event_type'] === 'reminder_due' && ! $this->db->table(Tables::name('reminders'))->where('scope_key', $context->scope)->where('actor_ref', $context->actor)->where('reminder_key', $data['reminder_key'] ?? '')->where('generation', $data['generation'] ?? 0)->where('state', 'sent')->exists()) {
            return;
        }if (isset($data['recipient_ref']) && $data['recipient_ref'] !== $context->actor) {
            return;
        }$channel = $data['channel'] ?? 'in_app';
        $allowed = $this->preferences->nextAllowed($context, $channel, $this->clock->now());
        if ($allowed === null) {
            return;
        }if ($allowed > $this->clock->now()) {
            throw new DeferredDelivery($allowed);
        }$this->transport->deliver($context, $event['event_id'], $context->actor, $channel, $event);
    }
}
