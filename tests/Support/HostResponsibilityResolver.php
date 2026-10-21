<?php

namespace Karnoweb\TaskManager\Tests\Support;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\ResponsibilityResolver;

final class HostResponsibilityResolver implements ResponsibilityResolver
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function candidates(ExecutionContext $context, string $reference, string $purpose): array
    {
        $column = match ($purpose) {
            'assignment' => 'can_assign', 'approval' => 'can_approve', default => null
        };
        if ($column === null) {
            return [];
        }

        return $this->db->table('host_people')->where('scope_key', $context->scope)->where('team', $reference)->where('active', true)->where($column, true)->orderBy('actor')->pluck('actor')->all();
    }

    public function eligible(ExecutionContext $context, string $actor, string $purpose): bool
    {
        $column = match ($purpose) {
            'assignment' => 'can_assign', 'approval' => 'can_approve', default => null
        };

        return $column !== null && $this->db->table('host_people')->where('scope_key', $context->scope)->where('actor', $actor)->where('active', true)->where($column, true)->exists();
    }
}
