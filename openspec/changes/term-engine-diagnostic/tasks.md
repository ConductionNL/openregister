# Tasks: term-engine-diagnostic

## 1. Engine

- [x] 1.1a `WalkCollector`, passed INTO `SlaCalculator::add()` — the method
      the arm path calls (D-1). The arm path passes none and pays one null
      check per day. The rule NAME comes from the calendar's own
      `nonWorkingDates()`, the same map `isWorkingDay()` consults, so the
      diagnostic cannot name a rule the engine did not apply.
- [ ] 1.1b **The roll: there is none to record.** `SlaCalculator` has no
      roll — neither `add()` nor the arm path moves a landing off a
      non-working day — so a requested `rollToWorkingDay` is REFUSED with
      that as the reason rather than narrated. A diagnostic that applied a
      roll the engine does not would print a moment the engine never
      produces, and it would be believed precisely because it is the
      diagnostic. Adding the roll to the engine is its own change; the
      response reports `firesOnWorkingDay` so the reader can see the case a
      roll would have been for.
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
