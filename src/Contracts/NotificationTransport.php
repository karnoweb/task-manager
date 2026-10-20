<?php

namespace Karnoweb\TaskManager\Contracts;

use Karnoweb\TaskManager\Context\ExecutionContext;

interface NotificationTransport
{
    /** External adapters must deduplicate by event_id + recipient + channel. */
    public function deliver(ExecutionContext $context,string $eventId,string $recipient,string $channel,array $safeEnvelope):void;
}
