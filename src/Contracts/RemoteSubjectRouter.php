<?php

namespace Karnoweb\TaskManager\Contracts;

use Karnoweb\TaskManager\Context\ExecutionContext;

/** Remote owner must durably deduplicate create/compensate by operation key. */
interface RemoteSubjectRouter
{
    /** Select only approved export fields. No implicit copy of source content. */
    public function prepare(ExecutionContext $context, array $origin): array;

    /** Confirmed receipt: scope_key, subject_type, subject_id, external_process_key. */
    public function create(ExecutionContext $context, array $payload, string $operation): array;

    /** Durable receipt lookup by the same create key, including after a lost response. */
    public function lookup(ExecutionContext $context, string $operation): ?array;

    public function compensate(ExecutionContext $context, array $receipt, string $operation): bool;
}
