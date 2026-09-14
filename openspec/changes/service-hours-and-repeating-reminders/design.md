# Design: service-hours-and-repeating-reminders

## D-1: windows, not an hours-per-day number

`hoursPerWorkingDay` exists so that hours and business days are
commensurable, and it stays for that. It cannot answer when the clock runs,
so the calendar gains windows per weekday. The scalar is then derived from
the windows when they are declared, which removes the way the two could
disagree.

## D-2: the hours ride the existing resolution

A second resolution order for hours would be a second thing to get wrong.
The windows are a property of the calendar object, so the record type, unit
and instance order of REQ-WCA-005 already picks them, and a calendar that
declares none behaves exactly as today.

## D-3: the zone is the calendar's

`calendar-time-zone` already gives a calendar a named zone and says reported
dates name theirs. Windows are stated in that zone, so a summer-time change
moves the window with the working day rather than shifting the whole
municipality by an hour.

## D-4: a reminder is a rule, not a job

Anchoring to an arbitrary date property, repeating and stopping are three
conditions over one object. The scheduled trigger already sweeps by index
with a due watermark and already re-arms on a watched field. A reminder is
that trigger with an anchor, an interval, a count and a stop condition, so
the sweep stays bounded and there is no per-reminder job class.

## D-5: the stop condition is evaluated before the send

A reminder that stops after the send has already been sent, which is the
noise the row is about. So the condition is evaluated at dispatch, the stop
is recorded with its reason, and the rule stops re-arming.

## D-6: reuse analysis (ADR-012)

- The working calendar object, its admin surface and its resolution order:
  reused from `working-calendar-admin`.
- The zone: reused from `calendar-time-zone`.
- The scheduled trigger, its dedup state, its watermark and its filter
  grammar: reused from `specs/notificatie-engine` and
  `notification-scheduled-filter-grammar`.
- No second calculator, no second scheduler.
