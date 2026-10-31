<?php

namespace Karnoweb\TaskManager\Scheduling;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\ReminderRecipients;
use Karnoweb\TaskManager\Exceptions\DomainError;

final class SelfReminderRecipients implements ReminderRecipients
{
    public function suggest(ExecutionContext $context, ?string $type, ?string $id): string
    {
        $context->validateTenant();

        return $context->actor;
    }

    public function authorize(ExecutionContext $context, string $recipient, ?string $type, ?string $id): void
    {
        $context->validateTenant();
        if ($recipient !== $context->actor) {
            throw new DomainError('reminder_recipient_denied');
        }
    }
}
