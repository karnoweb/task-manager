# Phase 1 decisions and unresolved work

- Adopt Laravel 13 based on verified sibling manifests; actual execution currently covers PHP 8.4 only.
- Trust no implicit auth/session/tenant state. The host supplies ExecutionContext for each operation. Service actors require a capability allowlist.
- Native personal task state lives only in its workflow instance. Task table has no independent status column.
- Reserve a unique command journal key before executing writes; include actor and canonicalized payload in the hash; recheck authorization before returning a prior result.
- Keep events transactional and require at-least-once delivery with consumer deduplication. Acknowledged delivery is not retried; a crash between consumer action and acknowledgment may redeliver the same event ID.
- Use a project-specific PostgreSQL cluster on localhost:55439. Existing EnvKit service data is not used or changed. Runtime data is ignored by Git.
- Human phase pauses are removed by direct user instruction. This does not waive tests, hide failures or permit claiming features absent from source.

Remaining design review items are listed in PHASE-1-IMPLEMENTATION.md. No final release/version matrix is approved or claimed. No phase-seven result exists yet.
