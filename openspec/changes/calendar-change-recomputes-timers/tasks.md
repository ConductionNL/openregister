# Tasks: calendar-change-recomputes-timers

## 1. Data

- [x] 1.1a 🔑 **MEASURED, NOT BUILT: the column is already there.**
      `FlowTimer` carries `organisation` and `calendarSlug` today, both filled
      at arm time, so the migration this task asks for is half unnecessary.
      Worth stating rather than silently skipping.
- [ ] 1.1b The two indexes. Until they exist the job pages the OPEN timers by
      id — which is an index read with a resumable cursor over the small set,
      not a scan of every timer ever armed. When the indexes land this becomes
      the two reads D-3 describes and the RULE does not change, because the
      rule is `CalendarDependency` either way.
- [x] 1.2a `supersede()` already takes a free-text reason and already writes
      it to the ledger event, so `CalendarRecompute::REASON` is the constant
      and no engine change was needed. The actor is a named machine identity,
      not the administrator who pressed Save: the edit and the supersession are
      different acts, and attributing thousands of them to one person reads as
      though they moved each deadline by hand.
- [ ] 1.2b The calendar VERSION inside the ledger event. The reason string
      carries `calendar-changed`; threading the version through
      `FlowTimerService::record()` means widening that signature, which touches
      every other supersession reason.

## 2. Observation and job

- [x] 2.1 On `ObjectUpdatedEvent`, not `ObjectUpdatingEvent`: nothing should
      be recomputed against a calendar whose save might still be refused. A
      calendar with no slug, or no version, queues NOTHING and says so — a job
      that cannot be deduplicated would walk every open timer on every save of
      an unchanged calendar, which is the shape of a job somebody switches off
      six months later.
- [x] 2.2 The three dependency sets are ASKED, not re-derived:
      `CalendarDependency` puts the question to
      `WorkingCalendarService::resolve()`, the same method that armed the
      timers, because two implementations of "which calendar does this timer
      use" is how a recompute silently skips the timers it exists for. Batches
      of 500 through a generator with an id cursor, so a hundred thousand
      timers never exist in memory at once. Idempotency on (slug, version) —
      keyed on the slug alone, a second edit of the day would be a no-op.
      Counts logged: examined, moved, unchanged, deferred, unresolvable.
- [x] 2.3 Registered.

## 3. Tests

- [x] 3.1a 11 tests: both spec scenarios against the SHIPPED `nl-national`
      descriptor plus one exception, the unchanged-calendar control, a timer on
      another calendar, the inherited default, idempotency, a LATER version
      still running, the suspended timer deferred rather than superseded, the
      unresolvable calendar counted rather than skipped, one failing timer not
      abandoning the batch, and the projection being the engine's own formula.
      Two mutation checks.
- [ ] 3.1b Fired rungs not repeated, and resume after a killed pass: both are
      properties of `FlowTimerService::supersede()` and of the job's cursor
      against a real database, so they want the live-DB suite rather than a
      double.
- [ ] 3.2 `tests/e2e/ci/calendar-recompute.spec.ts`: add an exception on the admin page, run the job, read the superseded timer's history.
