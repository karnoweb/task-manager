# Phase 2 API

The TaskManager facade resolves a stateless manager exposing tasks(), workflows(), projects(), pipelines(), instances() and cases(). Existing task methods forward to TaskService for compatibility; immutable task sessions retain context only on the returned local session object.

WorkflowService publishes a validated definition using context/key/expected revision/operation/definition and returns a new immutable revision. Definitions declare an initial state, state terminal kinds, named edges, required fields, allowed roles, reason catalogs and whitelisted conditions. Host conditions read trusted subject fields, not overriding caller payload. Registered guard callables and SubjectHandler veto before commit.

PipelineService creates a revision-bound pipeline with explicit stage/state/capacity mappings. InstanceService starts a subject process by alias/ID/revision/slot and optional pipeline/stage/case; get/query/count/facets apply authorized subject subqueries. Transitions require expected version and operation ID, and support explicit cancellation without writes. previewMove and moveToPipeline preserve subject/instance identity while validating mappings, capacities and host acceptance. visits returns authorized stage intervals. reconcile reports a host-state conflict without changing the domain state.

ProjectService creates projects, updates members/archive/type mappings with expected version, previews and applies task conversion/project moves, and previews/applies explicit workflow revision migration. A viewer may query but cannot mutate. Revocation is reflected directly by database predicates and does not rely on a stale cache.

CaseService creates owner-controlled cases and returns only events of currently visible linked subjects. Empty and inaccessible timelines reveal no private subject events. TaskResourceService provides audited metadata foundations with parent-task authorization and version guards; it does not upload or serve physical files.

Custom external integrations bind an immutable SubjectRegistry with stable aliases and handlers. Handlers must honor current host permissions and use the same database connection for atomic guards. External effects belong in the outbox/Saga path, never in an assumed global transaction.
