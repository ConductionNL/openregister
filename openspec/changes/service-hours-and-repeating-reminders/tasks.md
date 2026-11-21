# Tasks: service-hours-and-repeating-reminders

## 1. Service hours on the calendar

- [x] 1.1 `serviceHours` per weekday on the working calendar object, validated on every write. The validator shipped with #3945; the schema did not declare the property, so an administrator's windows were dropped by the object store before any validator saw them. Declared now, and asserted by a read-back rather than by a save that returns 200.
- [~] 1.2 The admin surface edits the windows, per working weekday, in the calendar editor. The PREVIEW half is not built: the preview endpoint answers a year of non-working dates and cannot compute a term, so previewing an unsaved term needs an endpoint that does not exist. Left as its own task rather than half-built.
- [x] 1.3 `hoursPerWorkingDay` is derived from the windows when they are declared, in `WorkingCalendar::fromArray()`.

## 2. The hour clock

- [x] 2.1 The calculator advances an hours term only inside the windows, in the calendar's zone, and measures elapsed time in the same windows so the deadline and the report cannot disagree.
- [x] 2.2 A term computed on a day with no window moves to the next day that has one; a calendar that never opens throws rather than answering the cap's date.
- [x] 2.3 The term diagnostic names the calendar and the windows that produced the answer.
      > 2026-11-13: `TermDiagnostic::explain()` adds `serviceHours` (the calendar's windows by weekday, `HH:MM-HH:MM`) and `serviceHoursApplied` (an hours term on a calendar that declares windows), beside the `calendar` slug and `zone` it already named. `TermDiagnosticTest` computes one 6-hour term on a 09:00-12:30 and a 09:00-17:00 calendar and reads two fire moments and two named calendars.

## 3. Reminders

- [~] 3.1 A reminder declares its anchor property, an offset, a repeat interval and a maximum number of repeats.
      > 2026-11-13: the DECLARATION half is built: a rule's `reminder` block (`anchor`, `offset`, `repeat.interval`, `repeat.max`, `stopWhen`) is validated at schema save by `NotificationReminderValidator`, refusing with 422 an anchor that is not a declared date property (naming it), an unbounded repeat, an amount outside the timers' `{value, unit}` shape and a stop condition the scheduled filter grammar refuses (`NotificationReminderValidatorTest`). Arming and dispatching a reminder from its anchor is not built; it needs the repeat count and the stop reason in the dedupe state, which 3.2-3.4 add with it.
- [ ] 3.2 A reminder declares a stop condition, evaluated before dispatch.
- [ ] 3.3 A stopped reminder records why it stopped and does not re-arm.
- [ ] 3.4 A moved anchor re-arms the reminder from the new date.

## 4. Tests

- [x] 4.1 Unit tests for the Friday-afternoon term, the split day, the closed day, the undeclared calendar and the empty holiday list, all from the stored definition through `SlaCalculator`. The repeat count and the stop condition belong to section 3 and are not built.
- [x] 4.2 `tests/e2e/ci/service-hours-admin.spec.ts`: an administrator types a split day, saves, and the stored object is read back. Tagged, not run in this phase. The recompute half waits on 1.2.
- [ ] 4.3 Deduplication check (ADR-012) recorded in the PR body.
