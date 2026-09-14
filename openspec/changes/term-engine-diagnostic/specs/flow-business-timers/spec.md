# flow-business-timers

## ADDED Requirements

### Requirement: The engine explains a deadline for a chosen date without arming anything

The system SHALL provide an administrator-only endpoint that takes a
calendar, an anchor moment and an SLA shape, and returns the fire moment the
arm path would compute together with every day the walk examined, the rule
that made a day non-working, the roll applied and the calendar's zone. The
endpoint SHALL create no timer, no ledger event and no audit entry.

#### Scenario: the working of a term across Easter is printed

- **GIVEN** `nl-national`, an anchor on the Thursday before Easter and `2 businessDays`
- **WHEN** the diagnostic is called
- **THEN** the walk lists Goede Vrijdag, Saturday, Sunday and Tweede Paasdag as skipped with their names and the fire moment is the following Wednesday
- @e2e exclude {proposal only; task 3.2 adds tests/e2e/ci/term-diagnostic.spec.ts when the panel ships}

#### Scenario: the diagnostic leaves no trace

- **GIVEN** the timer table and the ledger before a diagnostic call
- **WHEN** the diagnostic is called ten times
- **THEN** both are unchanged
- @e2e exclude {write guard, covered by controller unit tests}

### Requirement: The diagnostic is reachable from the calendar admin page and by deep link

The working calendar admin page SHALL offer a panel that calls the diagnostic
and renders the walk, and SHALL accept `calendar` and `sla` query parameters
that preselect the form.

#### Scenario: a consuming app opens the panel preselected

- **GIVEN** a link `/settings/admin/openregister?section=calendars&calendar=nl-national&sla=42:calendarDays:next`
- **WHEN** an administrator opens it
- **THEN** the panel shows `nl-national` and the SLA filled in, waiting for an anchor date
- @e2e exclude {deep link, covered by the e2e spec of task 3.2}
