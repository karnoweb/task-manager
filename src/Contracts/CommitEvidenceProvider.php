<?php

namespace Karnoweb\TaskManager\Contracts;

use Karnoweb\TaskManager\Context\ExecutionContext;

/** Trusted host/DB adapter, never browser-supplied timestamps. */
interface CommitEvidenceProvider
{
    /** Exact committed time of this durable intent, or null when not provable yet. */
    public function committedAt(ExecutionContext $context, array $intent): ?\DateTimeImmutable;
}
