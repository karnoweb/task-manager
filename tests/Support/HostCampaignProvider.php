<?php

namespace Karnoweb\TaskManager\Tests\Support;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\GroupSourceProvider;
use Karnoweb\TaskManager\Exceptions\DomainError;

final class HostCampaignProvider implements GroupSourceProvider
{
    public function __construct(private Connection $db) {}
    public function authorize(ExecutionContext $context, string $sourceId, string $action): void
    {
        if (!$this->db->table('host_campaigns')->where('id', $sourceId)->where('scope_key', $context->scope)->where('manager', $context->actor)->exists()) { throw new DomainError('not_found'); }
    }
    public function closure(ExecutionContext $context, string $sourceId): array
    {
        $this->authorize($context, $sourceId, 'close'); $row = $this->db->table('host_campaigns')->where('id', $sourceId)->where('scope_key', $context->scope)->first();
        if ($row->state !== 'closed' || $row->cutoff_at === null) { throw new DomainError('group_source_invalid'); }
        return ['revision' => $row->version, 'cutoff_at' => new \DateTimeImmutable($row->cutoff_at, new \DateTimeZone('UTC'))];
    }
}
