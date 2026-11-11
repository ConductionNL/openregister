# Tasks: end-date-roll-on-the-calendar

## 1. Arithmetic

- [x] 1.1 Accept and validate `rollToWorkingDay` in `SlaCalculator::validateSla()`; store it on the timer row (migration adds `roll_to_working_day`).
- [x] 1.2 Apply the roll at the end of `SlaCalculator::add()` for `next` and `previous`, reusing the memoised non-working dates.
- [x] 1.3 Re-apply in `FlowTimerService::extend()`, `extendWithOverride()` and `supersede()`; evaluate D-6 against the rolled moment.

## 2. Explanation

- [x] 2.1 `unrolledAt` and `rolledBy` in `describe()` and on the `armed`, `extended`, `superseded` ledger events.

## 3. Tests

- [x] 3.1 Unit tests: Easter cluster, Koningsdag observed shift, weekend, `previous`, `businessDays` ignored, default `none`.
- [ ] 3.2 Newman: arm a timer with the option through the API and read the description.

## Status, 2026-09-18

**The mechanism is built. The list and the default are not this lane's to
decide, and both are left as they are.**

- `rollToWorkingDay` is `none`, `next` or `previous`, defaulting to `none`, and
  an unknown value is REFUSED rather than read as `none`. On a deadline with
  legal effect a silent default is the worst kind.
- `SlaCalculator::roll()` walks off a non-working day and answers what it did:
  where the budget had put the deadline, and the name of the rule that moved
  it. The name comes from the CALENDAR'S own rule. This class knows exactly one
  name, `weekend`, because it is the one rule it decides itself; every other
  name is whatever the administrator called the day they declared.
- The roll is applied in `FlowTimerService::recompute()`, which is the single
  place `fire_at` is set — arm, extend, supersede, suspend and resume all pass
  through it — so the roll cannot be forgotten on one path, and the escalation
  ladder is measured against the rolled moment, which is the deadline the term
  actually has.
- The timer and every ledger event carry `unrolled_at` and `rolled_by`, and
  `describe()` reports both. A timer holds one deadline; an auditor reading why
  a term ended on Tuesday a year later is reading the ledger.
- 1.3 is satisfied through `recompute()` rather than by three separate edits to
  `extend()`, `extendWithOverride()` and `supersede()`. Three copies of one
  rule is how it comes to hold on two of them.

**`TermDiagnostic` no longer refuses a roll.** It refused deliberately — the
engine had none, and a diagnostic that applied one would have printed a moment
the arm path never produces, believed precisely because it is the diagnostic.
It now calls the engine's own `roll()`, not a second walk of the same calendar,
and prints `unrolledAt` and `rolledBy` beside the moment.

**Nothing is switched on.** The default is `none`, no shipped calendar changed,
no schema gained the option, and the migration leaves every armed timer with
the deadline it has: a migration that rolled existing terms would move
deadlines with legal effect, retroactively, without anybody deciding to. What
an administrator has to supply before this can be turned on is in the PR body,
and it is a legal question, not a configuration one.

**3.2, the Newman run, is not done.** It needs a live instance to arm a timer
through the API, which this lane does not have.
