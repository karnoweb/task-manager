# Campaign cutoff decision — implementation contract

Protected milestone eligibility uses `committed_at <= cutoff_at`, inclusive at equality. A workflow stage name/position, browser timestamp, transaction start time and pre-commit application clock are not proof of commit. A later verification time does not change the original committed time.

Workflow edges declare named milestone intents. Intents must be committed with the actual authorized transition and its process version; a rollback removes the intent too. Verification is a separate committed-boundary command. The default unverified evidence provider cannot invent timestamps. Pending/unprovable intents require explicit review, not an assumed campaign failure or grandfathering decision.

The PostgreSQL evidence adapter reads the transaction's actual commit timestamp, only when tracking was enabled for that transaction and retained evidence is available. PostgreSQL documents that these timestamps require `track_commit_timestamp` and can be removed by vacuum: [official PostgreSQL 18 documentation](https://www.postgresql.org/docs/18/functions-info.html#FUNCTIONS-COMMIT-TIMESTAMP). Missing/expired proof remains unavailable; an operator must use a trusted historical ledger or review the item. The adapter does not change host database settings.

Other databases require a registered trusted commit-evidence adapter, such as an owner-service committed event ledger/CDC stream. A caller cannot submit `committed_at` through the milestone command. Cross-database timestamps need an explicit shared time contract and authoritative owner evidence; the package does not claim distributed clock ordering automatically.

The host owns campaign identity, authorization, immutable source closure revision and cutoff. Group closure cannot use an arbitrary caller-provided source state or cutoff to fail business records. Each item rechecks current subject authorization/version/guards. Group enrollment, run materialization and per-item outcomes preserve their own histories. The complete PostgreSQL suite exercises this group closure contract; scale and final release matrix evidence remain separate.
