<?php

namespace Karnoweb\TaskManager\Contracts;

use Karnoweb\TaskManager\Context\ExecutionContext;

interface NotificationPreferences
{
    /** null suppresses this channel; a later instant delays it. Never returns private content. */
    public function nextAllowed(ExecutionContext $context, string $channel, \DateTimeImmutable $at): ?\DateTimeImmutable;
}
