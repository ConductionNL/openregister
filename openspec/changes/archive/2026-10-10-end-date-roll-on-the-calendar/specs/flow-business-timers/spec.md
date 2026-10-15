# flow-business-timers

## ADDED Requirements

### Requirement: A budget may roll its end date to a working day

The SLA shape SHALL accept an optional `rollToWorkingDay` of `none`, `next`
or `previous`, defaulting to `none`, and SHALL reject any other value. When
the computed fire moment falls on a day the resolved calendar does not count
as working, `next` SHALL move it to the first following working day and
`previous` to the last preceding working day, keeping the time of day.
`businessDays` budgets SHALL accept the option and SHALL NOT need it.

#### Scenario: a six-week term ending on Easter Monday ends on Tuesday

- **GIVEN** a `42 calendarDays` budget with `rollToWorkingDay: next` anchored so that the 42nd day is Tweede Paasdag on the `nl-national` calendar
- **WHEN** the timer is armed
- **THEN** `fire_at` is the Tuesday after Easter at the anchor's time of day
- @e2e exclude {calendar arithmetic, covered by SlaCalculator unit tests}

#### Scenario: a term without the option keeps its Sunday

- **GIVEN** the same budget with `rollToWorkingDay` absent
- **WHEN** the timer is armed
- **THEN** `fire_at` is Tweede Paasdag
- @e2e exclude {default behaviour, covered by SlaCalculator unit tests}

### Requirement: A rolled deadline says where it came from

`describe()` SHALL report `unrolledAt` and `rolledBy` (the name of the
non-working rule or `weekend`) whenever the roll changed the moment, and the
timer event ledger SHALL carry the same two values on the `armed`,
`extended` and `superseded` events.

#### Scenario: the handler reads why the term moved

- **GIVEN** a timer whose end date rolled from a Saturday to a Monday
- **WHEN** the timer is described
- **THEN** `unrolledAt` is the Saturday and `rolledBy` is `weekend`
- @e2e exclude {describe output, covered by FlowTimerService unit tests}

### Requirement: Extension and supersession re-apply the roll

`extend()` and `supersede()` SHALL compute the new moment and then apply the
timer's `rollToWorkingDay` against the same calendar, and the escalation
constraint SHALL be evaluated against the rolled moment.

#### Scenario: an extension that lands on Koningsdag rolls

- **GIVEN** an armed timer with `rollToWorkingDay: next` extended by two calendar days onto 27 April
- **WHEN** the extension is granted
- **THEN** the new `fire_at` is 28 April and the `extended` event carries `unrolledAt` 27 April
- @e2e exclude {extend arithmetic, covered by FlowTimerService unit tests}
