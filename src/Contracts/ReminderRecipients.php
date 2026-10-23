<?php

namespace Karnoweb\TaskManager\Contracts;

use Karnoweb\TaskManager\Context\ExecutionContext;

/** The host verifies recipient eligibility; the engine never impersonates another actor. */
interface ReminderRecipients
{
    public function suggest(ExecutionContext $context, ?string $type, ?string $id): string;

    public function authorize(ExecutionContext $context, string $recipient, ?string $type, ?string $id): void;
}
