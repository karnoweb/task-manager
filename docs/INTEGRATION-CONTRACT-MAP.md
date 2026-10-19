# Verified local contracts

Read on 2026-10-20. Neither sibling checkout was modified.

| Package | Locked local source | Observed contracts |
|---|---|---|
| Kanban | v13.3.0, c63c7859978f97f677e8417729bcec0dd1375868 | DataProvider supplies board/stages/stageCards/listCards/counts/card/archives/capabilities/operationStatus. ActionHandler executes ActionCommand with BoardContext and returns ActionResult. CardDetailsProvider supplies sections/loadSection/performAction with per-call authorization. |
| Calendar | 2.0.0, b4dfb75cccc4bdb681ecaa64f99bc6c662eac5e0 | EventProvider fetches EventQuery with CalendarContext as EventPage and resolves EventReference to nullable CalendarEventData. ActionHandler executes ActionCommand with CalendarContext and returns ActionResult. |

Accounting and inventory facades resolve dedicated manager services, exposing specialized services/builders. TaskManager follows explicit typed service entry points; no claim is made that its method names are interchangeable with those packages.

Integration DTOs, version transport, section capabilities, recurrence pagination and source selection still require implementation and actual integration tests in phase six. Reading these interfaces does not prove integration compatibility or security fixes in the UI packages. The historical review report referenced in the requirements has not yet been verified here.
