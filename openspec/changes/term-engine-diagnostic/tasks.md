# Tasks: term-engine-diagnostic

## 1. Engine

- [x] 1.1a `WalkCollector`, passed INTO `SlaCalculator::add()` — the method
      the arm path calls (D-1). The arm path passes none and pays one null
      check per day. The rule NAME comes from the calendar's own
      `nonWorkingDates()`, the same map `isWorkingDay()` consults, so the
      diagnostic cannot name a rule the engine did not apply.
- [x] 1.1b The roll, narrated through the engine's own roll. When this task
      was first written `SlaCalculator` had no roll, so a requested
      `rollToWorkingDay` was refused rather than narrated. The engine gained
      the roll in #3939 (`SlaCalculator::roll()`, "a term can roll off a day
      nobody works, and say what moved it"), and `TermDiagnostic::explain()`
      calls that same method (D-1): the response carries `roll`, `firesAt`,
      `unrolledAt` (absent when the roll moved nothing) and `rolledBy` (the
      rule that stopped the landing). A roll outside the vocabulary is still
      refused, not defaulted. Covered by `TermDiagnosticTest` ("the roll is
      NARRATED, through the engine's own roll").
- [x] 1.2 `POST /api/flow-timers/diagnostic`, administrator only, returning
      the fire moment, the walk, the skipped days with their rules, the roll,
      the zone and each rung's instant. It takes a calendar DEFINITION rather
      than a slug, exactly as its neighbour `workingCalendar#preview` does: a
      slug would mean a read through the object stack on a path whose whole
      promise is that it touches nothing.
      🔴 "No writes" is STRUCTURAL, not promised: a test asserts the
      constructor's parameter list, and neither the controller nor
      `TermDiagnostic` holds a mapper, a connection or a dispatcher.

## 2. Surface

- [ ] 2.1 The "Try a date" panel and the deep link. Vue, on the
      `working-calendar-admin` section, which is where the calendar
      definition the endpoint wants is already in hand. Not started.

## 3. Tests

- [x] 3.1 21 tests. The Easter walk against the SHIPPED `nl-national`
      descriptor, not a hand-written calendar; the control of a term inside
      one working week; the diagnostic agreeing instant-for-instant with the
      arm path; no writes, structurally; the ladder measured from the anchor;
      the refused roll; the truncated narration that does not truncate the
      walk; and the ordinary logged-in user refused 403.
      🔑 One finding while writing them: two business days from Thursday
      09:00 lands on the WEDNESDAY, not the Tuesday, because the anchor
      spends only 0.625 of Thursday. The spec's scenario says Wednesday and
      the engine agrees; the intuitive answer is wrong, and the test says so
      in a comment so nobody "fixes" it.
- [ ] 3.2 The e2e, which needs the panel from 2.1 to open.

## Status, 2026-11-13

**1.1b ticked:** it was done in #3939 and this file still said "refused"; see
the task text for what the diagnostic returns and which test covers it.

**2.1 and 3.2 stay open, and why.** The "Try a date" panel belongs beside the
"Preview a year" section of `EditWorkingCalendarModal`, which already holds the
calendar definition the endpoint takes. It needs about a dozen new prose labels
(none of them exists as a catalogue key yet: "Try a date", the unit names, the
walk's "counted"/"skipped", the fire moment, the roll), and openregister's
l10n parity gate holds 36 locales to key-for-key parity, so each label must be
translated into every one of them before the panel can land. That is a
translation pass in its own right (`docs/l10n-workflow.md` §6.15, one language
per commit), not something to fold into this change. The e2e (3.2) opens that
panel and waits for it.

