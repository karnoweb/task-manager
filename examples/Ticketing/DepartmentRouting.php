<?php

namespace App\TaskManager;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\EntryRoutingProvider;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TicketChat\Models\Department;

/** This sample host explicitly scopes departments with meta.scope_key. */
final class DepartmentRouting implements EntryRoutingProvider
{
    private function department(ExecutionContext $context, string $key): Department
    {
        $context->require('ticket.route');
        if (! preg_match('/^department:([1-9][0-9]*)$/D', $key, $match)) {
            throw new DomainError('routing_key_invalid');
        }
        $department = Department::query()->whereKey($match[1])->where('is_active', true)->where('meta->scope_key', $context->scope)->first();
        if (! $department) {
            throw new DomainError('not_found');
        }

        return $department;
    }

    public function authorize(ExecutionContext $context, string $routingKey): void
    {
        $this->department($context, $routingKey);
    }

    public function parent(ExecutionContext $context, string $routingKey): ?string
    {
        $id = $this->department($context, $routingKey)->parent_id;

        return $id === null ? null : 'department:'.$id;
    }
}
