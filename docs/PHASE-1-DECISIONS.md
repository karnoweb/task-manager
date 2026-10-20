# Phase 1 decisions and unresolved work

- Adopt Laravel 13 based on verified sibling manifests; actual execution currently covers PHP 8.4 only.
- Trust no implicit auth/session/tenant state. The host supplies ExecutionContext for each operation. Service actors require a capability allowlist.
- Native personal task state lives only in its workflow instance. Task table has no independent status column.
- Reserve a unique command journal key before executing writes; include actor and canonicalized payload in the hash; recheck authorization before returning a prior result.
- Keep events transactional and require at-least-once delivery with consumer deduplication. Acknowledged delivery is not retried; a crash between consumer action and acknowledgment may redeliver the same event ID.
- Use a project-specific PostgreSQL cluster on localhost:55439. Existing EnvKit service data is not used or changed. Runtime data is ignored by Git.
- The current user instruction restores a report and stop at each phase boundary. Earlier authorization to continue without pauses is superseded; tests and truthful scenario evidence remain required.

Remaining design review items are listed in PHASE-1-IMPLEMENTATION.md. No final release/version matrix is approved or claimed. No phase-seven result exists yet.

Migration 000012 and fresh schema defaults enforce exact binary/no-padding security identifiers on MySQL-family engines. This fixes a demonstrated cross-scope disclosure on a case-insensitive MariaDB default. New package migrations must use Schema\IdentifierCollation for their table defaults. See EXACT-IDENTIFIER-DECISION.md for reproduced failure, upgrade behavior and host-table obligations. Oracle MySQL 8 collation selection is implemented but its runtime is not yet verified.
