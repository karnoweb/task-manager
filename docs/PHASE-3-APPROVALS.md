# Approval checkpoint

Migration 000007 adds immutable stage/manual approval policies, one active step reservation per instance, versioned step state, unique step/actor votes and an explicit execution projection. Sequential and parallel modes require all listed approvers; quorum mode requires the configured positive threshold. Rejection is an explicit first-veto policy with a required catalog reason, not an undocumented majority rule.

ApprovalEngine participates in stage lifecycle guards. A pending step blocks state exit, pipeline moves, project conversion/revision migration and native Todo complete/archive/delete. Resolved consent permits only the configured outcome transition. Administrative cancellation requires approval.manage and an explicit repair/recall reason; it preserves the old policy and votes and permits a separately created replacement request. Self approval is denied by default.

ApprovalService exposes configureStage, begin, get/active, vote, execute and cancel. Mutations require explicit scope/capabilities, operation IDs and expected step/process versions. Current host eligibility is checked at every new vote. Duplicate actor votes do not count twice; contradictory votes are rejected. A real PostgreSQL process race exercises two votes at the same step version, explicit conflict/retry and one completion.

Voting uses authorized subject view plus approval.vote and the registered approver policy. It does not grant generic edit authority. A decision by view-only voters remains pending execution; a separately authorized executor may apply the fixed transition. A host veto retains valid votes but records manual_review and leaves workflow state unchanged. Execution is tied to the process version at the decision, so later process changes cannot be overwritten with old consent.

The workflow engine remains the only status writer. The step execution field is a projection of that guarded action and is completed when an approved/rejected source exits through its consented transition, including the native Todo facade path. Physical file signing, contract validation and commercial source revision checks remain host guard responsibilities.

Implemented owned IDs: TM-193, TM-194, TM-195. Supplemental tests cover native Todo and migration bypasses, view-only voters, host veto/retry, separate rejected outcome, invalid quorum/self approval, administrative repair and concurrency. Handoff outcomes and return policies are still pending; these tests do not count those scenarios as completed.
