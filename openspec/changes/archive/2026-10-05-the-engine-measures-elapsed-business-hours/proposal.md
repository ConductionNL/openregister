---
kind: capability
---

# Proposal: the-engine-measures-elapsed-business-hours

## Why

Dossiq's `dwell-time-on-the-working-calendar` reports how long a case sat in
each phase. Today it divides seconds by 3600, so a phase entered Friday at
16:00 and left Monday at 09:00 reads 65 hours; the organisation worked one of
them. A manager comparing two teams on that number is comparing who drew the
Friday afternoon cases.

The engine owns the working calendar, so the measurement belongs here. It is
not here yet, and neither of the two measurements that exist can stand in.

`measure(..., 'hours', ...)` is the wall clock by construction: seconds over
3600, nights and weekends included. That is right for a deadline expressed in
hours and it is not elapsed working time.

`measure(..., 'businessDays', ...)` counts fractions of a CALENDAR day on
working days, so the same interval reads 0.71 business days and converts at
eight hours a day to 5.67. That number counts Friday evening and Monday
before dawn as work. Also defensible for a deadline, also not elapsed working
time.

Both are wrong for the same reason: `WorkingCalendar` knew which DAYS are
worked and how many hours one holds, and never what time the office opens. A
deadline never has to ask. Elapsed working time cannot avoid asking.

## What changes

- `WorkingCalendar` carries `dayStartsAt`, HH:MM, defaulting to 09:00. It
  closes `hoursPerWorkingDay` later, so the window and the day length cannot
  disagree, and it is clamped to its own day so no window crosses midnight.
- `SlaCalculator::elapsedBusinessHours(from, to, calendar)` walks the
  interval day by day and adds the overlap with each working day's window.
  Signed, like `measure()`.
- The admin preview echoes the validated opening and closing times, as it
  already echoes the validated weekdays.

Nothing existing changes meaning: no deadline, no timer and no escalation
reads the new field, and a calendar that declares no opening time behaves
exactly as before.

## Impact

`lib/Service/Flow/Timer/WorkingCalendar.php`,
`lib/Service/Flow/Timer/SlaCalculator.php`,
`lib/Controller/WorkingCalendarController.php`,
`lib/Settings/flow_timer_register.json`.

## Capabilities

- Modified: `flow-business-timers`: the calendar knows when the day opens,
  and the calculator can say how much of an interval was working time.
