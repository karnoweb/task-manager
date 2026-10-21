<?php

namespace Karnoweb\TaskManager\Tests\Support;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\CommitEvidenceProvider;

/** Test-only trusted ledger, populated after the actual transition call committed. */
final class CommittedFixtureLedger implements CommitEvidenceProvider
{
    private array $commits = [];

    public function __construct(private Connection $db)
    {
    }

    public function acknowledge(string $intentKey, \DateTimeImmutable $committedAt): void
    {
        if ($this->db->transactionLevel() !== 0) {
            throw new \RuntimeException('Fixture cannot attest an uncommitted transition');
        }
        $this->commits[$intentKey] = $committedAt;
    }

    public function committedAt(ExecutionContext $context, array $intent): ?\DateTimeImmutable
    {
        return $intent['scope_key'] === $context->scope ? ($this->commits[$intent['intent_key']] ?? null) : null;
    }
}
