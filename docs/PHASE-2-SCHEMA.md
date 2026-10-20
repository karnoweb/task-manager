# Phase 2 schema and recovery

- Workflow heads serialize revision publication; definition rows remain immutable snapshots.
- Projects have BIGINT internal IDs and UUID public project keys, with member/type-map foreign keys. The unused phase-one UUID project placeholder is changed to an internal reference only after checking every legacy placeholder is null. A non-null legacy placeholder stops the migration for explicit mapping.
- Instances now support native task references or registered external subject aliases/IDs, with independent slots, lifecycle, pipeline/stage, owner/primary assignee and case references. Native task lookup retains a nonunique index when the original task uniqueness is relaxed.
- Active slots reserve (scope, subject type, subject ID, slot) with a real portable primary key. Terminal transitions release the reservation; history is retained.
- Stage visits append unique instance/visit numbers and close the prior interval without rewriting it. Pipeline-stage rows are locked in numeric order before capacity is counted and changed.
- Audit/outbox rows include aggregate type/public key. Security denials store normalized reason codes and hashed targets without successful transition events.
- Comments and attachment metadata use internal task foreign keys, public UUIDs, author references, optimistic versions and soft deletion. Actual file storage remains host-owned.

Lock order for process writes: authorized host/native subject, instance, sorted stage rows; project conversion/migration first serializes the project, then locks native tasks and instances in order. Unique command reservation surrounds the write transaction. Same-connection host guard writes roll back with domain writes on veto. Cross-database effects are not claimed atomic.

Downgrades refuse to discard external/multi-slot/pipeline/case/project identity or populated resource data. Export/migration is required first. This refusal is intentional and tested; it is not a destructive automatic downgrade. The nullable/nonunique native reference is retained during a safe process downgrade because rebuilding the original uniqueness can otherwise discard historical cycles.

SQLite and PostgreSQL are exercised. MySQL 8 remains an unexecuted compatibility requirement and must be covered before release acceptance.
