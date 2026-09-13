# Design: working-calendar-admin

## D-1: the calendar stays a register object

`FlowTimerDefinitionStore` already reads calendars from the `flow-timers`
register through `ObjectService`. A dedicated table would give the calendar a
second write path and a second RBAC model. The admin page writes through the
objects API like any other client, so every rule below holds for it too.

## D-2: one validator, three doors

`WorkingCalendar::fromArray()` is the validating constructor. It runs today
at resolve time, which is arm time. This change also runs it at write time
through a schema hook on `working-calendar` (schema-hooks, "Hook Lifecycle
Events", sync hook). A refused write returns 422 with the same message the
resolver would have thrown. The three doors are the admin page, the objects
API and a configuration import.

## D-3: preview is a read, not a write

The year preview calls `WorkingCalendar::nonWorkingDates(year)` on the
unsaved definition sent in the request body. It never stores anything, so an
administrator can try a rule before committing it. The endpoint is
`POST /api/flow-timers/calendars/preview` and requires admin.

## D-4: a referenced calendar cannot be deleted

`openregister_flow_timers.calendar_slug` names the calendar. Deleting a
calendar that an armed or suspended timer names would make the next sweep
throw for every one of them. The delete hook counts those timers and
refuses with the count and the first ten uuids. Fired, cancelled and
superseded timers do not block.

## D-5: recompute is not here

Changing a rule does not move any armed timer in this change. That is
`calendar-change-recomputes-timers`, which depends on this one. Until it
lands, the admin page shows a notice on save: "Armed deadlines keep their
dates until the recompute job ships."

## D-6: kind

Code, in OpenRegister. Consuming apps change nothing.
