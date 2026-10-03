# Tasks: the-engine-measures-elapsed-business-hours

- [x] 1.1 `WorkingCalendar` carries `dayStartsAt` (HH:MM, default 09:00),
  validated and refused rather than coerced, with `getDayEndsAtMinute()`
  derived from `hoursPerWorkingDay` and clamped to its own day.
- [x] 1.2 `dayStartsAt` is declared on the `working-calendar` schema and set
  on the seeded `nl-national` calendar, so OpenRegister does not drop it.
- [x] 1.3 `SlaCalculator::elapsedBusinessHours()`: the overlap of the
  interval with each working day's window, signed.
- [x] 1.4 The admin preview echoes the validated opening and closing times.
- [x] 2.1 Unit tests including the Friday-16:00 fixture and both controls.
  `openspec validate the-engine-measures-elapsed-business-hours --strict`.
