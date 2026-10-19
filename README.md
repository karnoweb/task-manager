# Karnoweb Task Manager

Work in progress. This repository is not a complete or production-ready implementation of the seven-phase plan in `dev/`.

The current foundation implements personal tasks and lists, explicit execution context, per-operation capabilities, scoped reads/counts/facets, optimistic versions, command idempotency, workflow instance state, audit, and a persistent outbox. Laravel 13 and PHP 8.3+ are required. UI packages and CRM are not dependencies.

Install with Composer and run the package migrations from the host Laravel application. Resolve `Karnoweb\TaskManager\TaskService` or use the TaskManager facade. Every operation requires an explicit ExecutionContext; there is no implicit authenticated user or global tenant context.

Run `composer test` for the SQLite baseline. The PostgreSQL profile currently uses a dedicated local test cluster on 127.0.0.1:55439, database `tm_acceptance`, user `tm_test`. Set `TM_TEST_DRIVER=pgsql` to run it. **The PostgreSQL suite recreates the public schema in that test database; never target application data.**

OutboxDispatcher delivers committed events synchronously without requiring an external queue. Delivery is at least once: consumers must deduplicate using event_id. Never pass a browser-supplied scope directly to an operational dispatcher. Public task commands always recheck explicit context and visibility.

Current evidence is in `docs/foundation-junit.xml` and `docs/foundation-postgres-junit.xml`. These reports cover only implemented foundation tests and are not phase-seven acceptance results.
