# Design: calendar-change-recomputes-timers

## D-1: observe the object, not the page

The admin page, the objects API and an import all end in a save of a
`working-calendar` object, which dispatches `ObjectUpdatedEvent`. Listening
there covers every door at once and needs no knowledge of who wrote.

## D-2: a job per calendar version, never inline

Recomputing can touch thousands of timers. The listener only queues; the job
does the work in batches of 500 ordered by uuid, with a cursor stored in the
job argument so a killed pass resumes. The pair (calendar slug, object
version) is the idempotency key: a second event for the same version is a
no-op.

## D-3: which timers depend on a calendar

Three sets: timers naming the slug in `calendar_slug`; timers naming nothing
whose subject's organisation resolves to the slug; and, when the changed
calendar is `nl-national`, timers naming nothing whose organisation has no
calendar. The first is an index read. The other two need the organisation on
the timer row, so the row gains `organisation` at arm time (already known to
`resolve()`), indexed with `state`.

## D-4: supersede, do not mutate

The existing supersession path already writes history and handles fired
rungs. Reusing it means a calendar change looks exactly like an anchor move
in the ledger, with a different reason. Nothing new has to explain itself.

## D-5: what is not recomputed

Fired, cancelled and superseded timers. A suspended timer is recomputed
because its remaining budget is re-projected at resume against the calendar
anyway; recomputing now keeps `describe()` honest while it is paused.

## D-6: kind

Code, in OpenRegister.
