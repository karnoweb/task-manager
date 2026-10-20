<?php

namespace Karnoweb\TaskManager\Tests\Support;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\NotificationTransport;

final class RecordingNotificationTransport implements NotificationTransport
{
    public array $events=[];
    public function deliver(ExecutionContext $context,string $eventId,string $recipient,string $channel,array $safeEnvelope):void{$this->events[$eventId.':'.$recipient.':'.$channel]=$safeEnvelope;}
}
