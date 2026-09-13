# Tasks: end-date-roll-on-the-calendar

## 1. Arithmetic

- [ ] 1.1 Accept and validate `rollToWorkingDay` in `SlaCalculator::validateSla()`; store it on the timer row (migration adds `roll_to_working_day`).
- [ ] 1.2 Apply the roll at the end of `SlaCalculator::add()` for `next` and `previous`, reusing the memoised non-working dates.
- [ ] 1.3 Re-apply in `FlowTimerService::extend()`, `extendWithOverride()` and `supersede()`; evaluate D-6 against the rolled moment.

## 2. Explanation

- [ ] 2.1 `unrolledAt` and `rolledBy` in `describe()` and on the `armed`, `extended`, `superseded` ledger events.

## 3. Tests

- [ ] 3.1 Unit tests: Easter cluster, Koningsdag observed shift, weekend, `previous`, `businessDays` ignored, default `none`.
- [ ] 3.2 Newman: arm a timer with the option through the API and read the description.
