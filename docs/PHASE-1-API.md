# Phase 1 API checkpoint

TaskService accepts ExecutionContext(scope, actor, correlationId, serviceActor, capabilities). Scope/actor/correlation must be nonempty and bounded. A service actor requires an explicitly supplied capability list. The host must construct contexts from trusted authorization decisions.

Public services currently return arrays. create requires context/title/operation. mutate requires context/public task key/expected version/operation/action/values. Lifecycle actions are complete, reopen, archive, restore, delete and undelete. update only permits title/description/priority. move_list checks owner and target-list ownership. share is owner-only and grants view or view+edit; revoke is immediate. cloneTask always creates a fresh personal task without source grants/project/state. resolveKey applies scope and visibility before alias resolution.

query is bounded/paginated; count and facets apply SQL visibility predicates. activity authorizes the parent task before querying its scoped events. OutboxDispatcher refuses to deliver while its connection is in a transaction. Failed delivery stays pending for replay; downstream consumers must deduplicate.

These signatures remain provisional until phase-six API freeze. They do not yet constitute the final typed DTO contract required by the plan.
