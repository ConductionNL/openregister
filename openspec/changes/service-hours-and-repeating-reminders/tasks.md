# Tasks: service-hours-and-repeating-reminders

## 1. Service hours on the calendar

- [ ] 1.1 `serviceHours` per weekday on the working calendar object, validated on every write.
- [ ] 1.2 The admin surface edits the windows and previews a computed term from the unsaved definition.
- [ ] 1.3 `hoursPerWorkingDay` is derived from the windows when they are declared.

## 2. The hour clock

- [ ] 2.1 The calculator advances an hours term only inside the windows, in the calendar's zone.
- [ ] 2.2 A term computed on a day with no window moves to the next day that has one.
- [ ] 2.3 The term diagnostic names the calendar and the windows that produced the answer.

## 3. Reminders

- [ ] 3.1 A reminder declares its anchor property, an offset, a repeat interval and a maximum number of repeats.
- [ ] 3.2 A reminder declares a stop condition, evaluated before dispatch.
- [ ] 3.3 A stopped reminder records why it stopped and does not re-arm.
- [ ] 3.4 A moved anchor re-arms the reminder from the new date.

## 4. Tests

- [ ] 4.1 Unit tests with a clock fixture for the Friday-afternoon term, the no-window day, the repeat count and the stop condition.
- [ ] 4.2 An e2e over the admin surface editing a window and a term recomputing.
- [ ] 4.3 Deduplication check (ADR-012) recorded in the PR body.
