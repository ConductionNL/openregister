# flow-business-timers

## ADDED Requirements

### Requirement: A calendar change re-projects the timers that depend on it

When a `working-calendar` object is updated, the system SHALL queue one
recompute job for that calendar version and SHALL NOT recompute inline. The
job SHALL examine every armed and suspended timer whose resolved calendar is
the changed one, recompute the fire moment from the stored anchor, budget,
consumed value and roll, and supersede each timer whose moment changed with
reason `calendar-changed` carrying the calendar's object version. Timers
whose moment did not change SHALL be left untouched.

#### Scenario: a new closure day moves the deadlines that cross it

- **GIVEN** three armed `businessDays` timers on `nl-national`, two of which span 2027-05-05 and one which ends before it
- **WHEN** an administrator adds 2027-05-05 as an exception
- **THEN** the two spanning timers are superseded by successors one working day later with reason `calendar-changed`, and the third is unchanged
- @e2e exclude {proposal only; task 3.2 adds tests/e2e/ci/calendar-recompute.spec.ts when the job ships}

#### Scenario: a timer that inherits the default calendar is included

- **GIVEN** an armed timer naming no calendar, whose organisation has no calendar of its own
- **WHEN** `nl-national` changes
- **THEN** the timer is examined and, when its moment changed, superseded
- @e2e exclude {dependency resolution, covered by job unit tests}

### Requirement: The recompute is bounded and idempotent

The recompute job SHALL process timers in bounded batches with a resumable
cursor, SHALL log examined, moved and unchanged counts, and SHALL do nothing
when a job for the same calendar slug and object version already ran.

#### Scenario: the same version is not recomputed twice

- **GIVEN** a completed recompute for calendar `nl-national` at object version 7
- **WHEN** a second event for version 7 is processed
- **THEN** no timer is examined and the job logs the skip
- @e2e exclude {idempotency key, covered by job unit tests}

### Requirement: Fired rungs survive a calendar recompute

Escalation rungs already fired on a timer superseded by a calendar change
SHALL NOT be fired again by the successor unless the successor's moment puts
the rung back in the future.

#### Scenario: a 7-day warning already sent is not repeated

- **GIVEN** a timer whose `preBreach:7:calendarDays` rung fired, superseded by a calendar change that moves the deadline two days later
- **WHEN** the next sweep runs
- **THEN** the 7-day rung does not fire again and the 2-day rung fires on its new date
- @e2e exclude {rung ledger, covered by the existing supersession tests extended with the new reason}
