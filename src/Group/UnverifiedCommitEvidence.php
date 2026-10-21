<?php

namespace Karnoweb\TaskManager\Group;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\CommitEvidenceProvider;

/** A pre-commit clock read is deliberately not accepted as commit evidence. */
final class UnverifiedCommitEvidence implements CommitEvidenceProvider
{
    public function committedAt(ExecutionContext $context, array $intent): ?\DateTimeImmutable
    {
        return null;
    }
}
