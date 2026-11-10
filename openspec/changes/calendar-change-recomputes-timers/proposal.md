---
kind: code
depends_on: [flow-business-timers, working-calendar-admin]
---

# Proposal: calendar-change-recomputes-timers

## Summary

When a working calendar changes, every armed or suspended timer measured
against it is re-projected. `flow-business-timers` re-arms a timer when its
anchor moves (requirement "A deadline's anchor is stored, so a moved anchor
re-arms the timer"); it does not react to a calendar edit, because nothing
observes one. `working-calendar-admin` gives the calendar a write path an
administrator will use. This change makes that write observable and bounded.

## Ledger rows

| row | capability | rating | size |
|---|---|---|---|
| Q8.17 | Does changing the calendar recompute the deadlines that depend on it | no | M |

## Why

The register's note: "Nothing recomputes. Five private holiday
implementations in `lib/Service/`, so there is no single place a calendar
change could be observed." The best competitor, verbatim from the `best`
column: "OpenProject 16: ApplyWorkingDaysChangeJob with the reason journaled
(`_round4/compare/proposed-rows.md`)".

The register's `why`: "recomputing armed timers when the calendar changes is
the engine's; flow-business-timers re-arms on an anchor move, not on a
calendar edit". With the calendar a register object, the observation point
is `ObjectUpdatedEvent` on the `working-calendar` schema.

## What changes

- A listener on `ObjectUpdatedEvent` for `flow-timers` / `working-calendar`
  queues one `RecomputeTimersForCalendarJob` per changed calendar, carrying
  the calendar slug and the object version before and after (ADR-078: the
  work is off the write).
- The job walks the armed and suspended timers whose resolved calendar is
  the changed one (named on the timer, or inherited through the organisation
  or the default) in bounded batches, recomputes `fire_at` from the stored
  anchor, budget, consumed value and roll, and supersedes every timer whose
  moment changed with reason `calendar-changed` and the calendar version.
- A timer whose moment did not change is left untouched and counted.
- Escalation rungs already fired on the superseded timer are not re-fired
  unless the successor puts them back in the future (the existing rule).
- The job logs counts: examined, moved, unchanged, and refuses to run twice
  for the same calendar version.

## Consumers

- dossiq: nothing to build; the superseded-timer history shows the handler
  why a date moved. The dossiq lane may add a sentence to its term detail.
- Every app with a business timer.

## ADRs

- ADR-078 (post-event listener work is asynchronous to the write).
- ADR-069 (background job conventions): a `QueuedJob` in `lib/BackgroundJob/`.
- ADR-098 decision 1: one clock.
- openregister ADR-009 (performance invariants): batches bounded by index.

## Impact

- Extends: `flow-business-timers` requirements "A deadline's anchor is
  stored, so a moved anchor re-arms the timer" and "The sweep is bounded to
  due work by index".
- Affected code: `lib/Listener/WorkingCalendarChangedListener.php`,
  `lib/BackgroundJob/RecomputeTimersForCalendarJob.php`,
  `FlowTimerService::supersede()` (a new reason), an index on
  `openregister_flow_timers (calendar_slug, state)`.
- Size: M.
