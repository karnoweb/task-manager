# Phase 1 schema checkpoint

Initial schema only; no existing application database was migrated. Fresh installation and rollback/reinstallation were tested against dedicated SQLite/PostgreSQL databases.

Domain rows use BIGINT internal IDs and public task/instance UUIDs. Lists and tasks have internal foreign keys. Instances reference native tasks and personal workflow definitions. Grants, transitions and aliases reference their corresponding internal domain rows. Audit/outbox rows preserve immutable event UUIDs and optional task references. No foreign key targets host users or CRM models.

Unique command reservation is (scope_key, operation_id). First requests insert the reservation before locking/reading, which avoids missing-row locking assumptions on PostgreSQL. A failure rolls the reservation back with the domain write. Inbox initialization uses a unique owner/scope/name key. Display sequences use a locked scope sequence row. Task locks precede instance locks in lifecycle mutations.

Historical schema is not yet frozen. MySQL 8 has not been tested. The installed EnvKit mysql directory contains MariaDB 11.8.2 and is not silently treated as MySQL 8 evidence.
