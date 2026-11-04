# Changelog

## 13.0.2

- Migrations can be published with `php artisan vendor:publish --tag=task-manager-migrations`. The provider uses `publishes()`, so the shipped filenames stay unchanged.

## Unreleased — phase-six checkpoint — 2026-10-21

- Added explicit optional Kanban and Calendar adapters against the actual locally locked package contracts; the package core retains no mandatory UI/CRM dependency.
- Added server-owned source/context boundaries, signed pagination, domain-owned moves, transactional UI retry handling, current-permission Drawer sections and stable Calendar occurrence projections.
- Added authorized host-subject follow-up associations, real CRM Lead/Deal subject adapters and controlled legacy task/series/process imports with mapping validation, history preservation, reconciliation, writer cutover and conservative rollback.
- Added four isolated Composer host profiles, actual Livewire/Calendar/CRM integration tests, six functional host E2E flows and all 21 owned phase-six scenario artifacts.
- Added public service, installation, extension, migration, rollback and troubleshooting documentation; recorded exact source/dependency baselines and phase-seven freeze obligations.

Validation: SQLite final regression 564 tests / 4960 assertions / no failure or error / 22 engine-specific skips. This is an implementation checkpoint, not release acceptance.

## Earlier implementation checkpoints

- Phase five: scheduling, Persian/Gregorian recurrence, reminders, typed fields, normalized search, automation, durable event consumption, signed webhooks, exports and historical reporting.
- Phase four: hierarchy/dependencies/planning, collaboration, project templates, sprint snapshots, ordering, time tracking, reports and bulk commands.
- Phases one through three: independent tasks/projects and workflow instances, stage visits/SLA, assignments, approvals, handoffs/routes/Saga, groups and committed milestones. Historical evidence and database scope are retained in the corresponding phase reports.
