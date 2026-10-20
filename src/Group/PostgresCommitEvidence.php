<?php

namespace Karnoweb\TaskManager\Group;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\CommitEvidenceProvider;

/** Exact PostgreSQL evidence when commit tracking is enabled and still retained. */
final class PostgresCommitEvidence implements CommitEvidenceProvider
{
    public function __construct(private readonly Connection $db) {}
    public function committedAt(ExecutionContext $context, array $intent): ?\DateTimeImmutable
    {
        if ($this->db->getDriverName() !== 'pgsql' || ($intent['scope_key'] ?? null) !== $context->scope
            || !preg_match('/^pgsql:([0-9]{1,20})$/D', $intent['transaction_ref'] ?? '', $match)) { return null; }
        try {
            return $this->db->transaction(function () use ($match) {
                if ($this->db->selectOne("SELECT current_setting('track_commit_timestamp') AS setting")->setting !== 'on') { return null; }
                if ($this->db->selectOne('SELECT pg_xact_status(CAST(? AS xid8)) AS status', [$match[1]])->status !== 'committed') { return null; }
                $row = $this->db->selectOne('SELECT pg_xact_commit_timestamp(CAST(CAST(? AS xid8) AS xid)) AS committed_at', [$match[1]]);
                return $row->committed_at === null ? null : new \DateTimeImmutable($row->committed_at);
            });
        } catch (QueryException $error) {
            // Expired/unavailable evidence remains unknown; never substitute transaction start time.
            return null;
        }
    }
}
