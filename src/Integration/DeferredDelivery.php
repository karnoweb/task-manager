<?php

namespace Karnoweb\TaskManager\Integration;

final class DeferredDelivery extends \RuntimeException
{
    public function __construct(public readonly \DateTimeImmutable $until)
    {
        parent::__construct('notification_quiet_hours');
    }
}
