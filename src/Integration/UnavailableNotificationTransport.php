<?php

namespace Karnoweb\TaskManager\Integration;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\NotificationTransport;
use Karnoweb\TaskManager\Exceptions\DomainError;

final class UnavailableNotificationTransport implements NotificationTransport
{
    public function deliver(ExecutionContext $context, string $eventId, string $recipient, string $channel, array $safeEnvelope): void
    {
        throw new DomainError('notification_transport_unavailable');
    }
}
