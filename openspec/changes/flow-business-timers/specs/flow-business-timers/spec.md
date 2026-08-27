## Purpose

Durable business time for tasks and flows: deadlines that fire weeks out and
survive restarts, that pause and resume where they left off, that escalate up
a ladder without repeating themselves, and that distinguish a date which merely
advises from one which enforces and one which has legal effect.

## ADDED Requirements

### Requirement: A business timer is durable, subject-bound and cancelled by completion

The system SHALL persist every business timer as a durable record, keyed by the
subject it measures (subject type and subject uuid), with the originating run
and node recorded as OPTIONAL provenance rather than as identity. A timer whose
subject has no run SHALL be first-class.

A timer SHALL fire correctly across process restarts, deployments and arbitrary
gaps in cron execution: firing SHALL be decided from persisted state, never from
a scheduled callback, an in-memory handle, or a sleep. A timer due while the
instance was down SHALL fire on the first sweep after it comes back, and SHALL
fire exactly once.

A subject MAY carry several timers at once. Timers are not columns on the
subject; the subject's own advisory and enforcing dates are a PROJECTION of the
timers that bear on it.

When the subject reaches a terminal state, every non-fired timer bound to it
SHALL be cancelled with a recorded reason, in the same operation that made the
subject terminal. A cancelled timer SHALL never fire afterwards. An orphaned
timer that outlives its subject SHALL be treated as a defect, not as a
tolerable condition.

#### Scenario: A timer set weeks out survives a restart

- **GIVEN** an armed timer due in six weeks
- **WHEN** the instance is restarted and the sweep runs after the due moment
- **THEN** the timer MUST fire
- **AND** it MUST fire exactly once across all subsequent sweeps
- @e2e exclude covered by timer-store integration tests over a seeded clock

#### Scenario: Completing the work cancels the deadline

- **GIVEN** a task carrying an armed escalation timer and an armed expiry timer
- **WHEN** the task is completed before either is due
- **THEN** both timers MUST be recorded as cancelled with a reason
- **AND** no escalation MUST be raised and no expiry outcome MUST be applied
- @e2e exclude covered by cancellation-propagation integration tests

#### Scenario: A run is not required

- **GIVEN** a standalone subject with no run and no node
- **WHEN** a timer is armed against it
- **THEN** the timer MUST be accepted and behave identically to a run-bound one
- @e2e exclude covered by unit tests over the timer service

### Requirement: An advisory due date notifies; an enforcing expiry transitions

The system SHALL distinguish two timer purposes with different consequences,
and SHALL NOT collapse them into one field:

- **`due` — advisory.** Reaching it SHALL raise the configured escalation and
  SHALL NOT change the subject's state. The work stays open, assigned and
  answerable after its due date passes.
- **`expiry` — enforcing.** Reaching it SHALL apply the timer's configured
  outcome, which transitions the subject away from the performer.

The enforcing outcome vocabulary SHALL be `error`, `skip`, `dead_letter` and
`transition:<action>`, and each value SHALL produce a DISTINCT, observable
result. In particular `skip` SHALL mean "the work is abandoned and the process
continues", which is not what `error` means; an implementation in which the two
produce the same subject state SHALL be treated as not meeting this requirement.

A subject MAY carry both a `due` timer and an `expiry` timer with different
moments. Neither SHALL be derived from the other.

#### Scenario: Passing the due date does not close the work

- **GIVEN** a task with a due timer and no expiry timer
- **WHEN** the due moment passes and the sweep runs
- **THEN** the escalation MUST be raised
- **AND** the task MUST remain in its current state, still assignable and still
  completable by its performer
- @e2e exclude covered by sweep integration tests

#### Scenario: skip and error are different outcomes

- **GIVEN** two expiry timers on comparable subjects, one `onExpiry: skip` and
  one `onExpiry: error`
- **WHEN** both expire
- **THEN** the resulting subject states MUST differ
- **AND** the `skip` subject's process MUST be able to continue while the
  `error` subject's MUST be marked failed
- @e2e exclude covered by expiry-outcome unit tests, one case per enum value

### Requirement: Business time is measured against ONE resolvable working calendar

The system SHALL accept a service level expressed as `{value, unit}` where
`value` is an integer from 1 to 10000 inclusive and `unit` is one of `hours`,
`businessDays` or `calendarDays`, and SHALL reject any other shape.

`businessDays` SHALL be resolved against a NAMED working calendar, not against a
hard-coded rule. A working calendar SHALL define which weekdays are working
days, which dates are non-working, and how many working hours a working day
contains. The working-hours figure is REQUIRED because without it `hours` and
`businessDays` are not commensurable, and the escalation constraint below
requires comparing them.

Calendar resolution SHALL be: the calendar named on the timer, else the calendar
configured for the subject's organisation, else the seeded national default.
Resolution SHALL be deterministic and SHALL NOT silently fall back to
"weekdays only" when a named calendar cannot be resolved — an unresolvable
calendar name SHALL be an error at arm time, not a quiet downgrade at fire time.

Non-working dates SHALL be COMPUTED for the rules that are computable rather
than enumerated as a fixed list of dates, so that the calendar does not expire.
A calendar whose correctness ends on a known date SHALL be rejected.

#### Scenario: A business-day deadline skips non-working days

- **GIVEN** a 3-businessDays SLA armed on a Thursday, against a calendar whose
  Saturday and Sunday are non-working
- **WHEN** the fire moment is computed
- **THEN** it MUST fall on the following Tuesday, not the following Sunday
- @e2e exclude covered by working-calendar unit tests

#### Scenario: The calendar does not expire

- **GIVEN** the seeded national default calendar
- **WHEN** a deadline is computed for a date more than five years in the future
- **THEN** the non-working dates for that year MUST still be correct
- **AND** no year MUST resolve to weekends-only through an exhausted table
- @e2e exclude covered by calendar unit tests asserting several future years

#### Scenario: An unknown calendar is refused, not downgraded

- **GIVEN** a timer configuration naming a calendar that does not exist
- **WHEN** the timer is armed
- **THEN** arming MUST fail with an error naming the missing calendar
- **AND** no timer MUST be created with a substituted calendar
- @e2e exclude covered by timer-service validation tests

### Requirement: A suspended deadline holds elapsed time, not a moment

The system SHALL support suspending a running timer (opschorting) and resuming
it, such that the time already elapsed before suspension is PRESERVED and the
time spent suspended does NOT count against the deadline.

The persisted state SHALL be sufficient to answer "how much of this term
remains" at any moment, including while suspended. A stored target timestamp
alone SHALL NOT be treated as sufficient: it cannot express a suspended term,
because there is no moment to which the remainder can be added until the term
resumes.

Remaining time SHALL be preserved in the timer's OWN unit. A term expressed in
business days that is suspended over a weekend SHALL resume with the same number
of business days remaining — the weekend consumed neither elapsed time nor
suspended time that mattered.

While suspended, a timer SHALL NOT fire, SHALL NOT escalate, and SHALL NOT be
reported as overdue. Suspending and resuming SHALL each be recorded with actor,
moment and reason, because the suspension of a legal term is itself a decision
that has to be evidenced.

#### Scenario: A hersteltermijn pause returns the remainder intact

- **GIVEN** an 8-week term with 19 days elapsed, suspended for a 14-day
  hersteltermijn
- **WHEN** the applicant responds on day 6 of the suspension and the term
  resumes
- **THEN** the remaining term MUST be the original 8 weeks minus 19 days
- **AND** the 6 suspended days MUST NOT have been consumed
- @e2e exclude covered by suspension arithmetic unit tests

#### Scenario: A suspended term is not overdue

- **GIVEN** a timer suspended before its fire moment, left suspended past that
  moment
- **WHEN** the sweep runs and the subject is listed
- **THEN** the timer MUST NOT fire
- **AND** the subject MUST NOT be reported as overdue
- @e2e exclude covered by sweep and derivation unit tests

#### Scenario: Suspension is evidenced

- **GIVEN** a timer suspended and later resumed
- **WHEN** its history is read
- **THEN** both events MUST carry the acting identity, the moment and the
  recorded reason
- @e2e exclude covered by timer-history integration tests

### Requirement: An extension is bounded and may only be granted before expiry

The system SHALL support extending a timer (verdaging) by a stated amount with a
stated rationale, and SHALL bound how many times a given timer may be extended.
The default bound SHALL be ONE.

An extension SHALL be REFUSED once the timer has fired or expired. A term that
has already run out cannot be lengthened retroactively; permitting it would let
a breach be erased after the fact.

An extension SHALL require a non-empty rationale and SHALL record the actor,
the moment, the prior fire moment and the new one. An extension request that
would exceed the bound SHALL be refused with an error naming the bound; an
authorized override path MAY exist but SHALL be a distinct, separately
authorized operation that is itself recorded as an override.

#### Scenario: The second extension is refused

- **GIVEN** a timer with an extension bound of one that has been extended once
- **WHEN** a second extension is requested through the standard path
- **THEN** it MUST be refused with an error naming the bound
- **AND** the fire moment MUST be unchanged
- @e2e exclude covered by extension unit tests

#### Scenario: Extending after expiry is refused

- **GIVEN** a timer that has already fired
- **WHEN** an extension is requested
- **THEN** it MUST be refused
- **AND** the recorded breach MUST remain recorded
- @e2e exclude covered by extension unit tests

### Requirement: Each escalation rung fires exactly once

The system SHALL support an ordered escalation ladder against a timer, where
each rung names a distance from the deadline and the recipients, priority and
message identity for that distance. Reaching a rung SHALL raise a NAMED
transition on the timer carrying the rung's recipients and priority.

Each rung SHALL fire AT MOST ONCE per timer. This SHALL be enforced by a
uniqueness constraint on the persisted fire record, not by a read-then-write
check on a document, so that two concurrent sweeps cannot both conclude the rung
is unfired.

A sweep pass that skips several rungs — because the instance was down, or
because an extension moved the deadline backwards — SHALL fire the rungs it
passed in ladder order, each once, and SHALL NOT collapse them into the most
severe one only.

The seeded default ladder SHALL be 14 days to the handler at low priority,
7 days to the handler and team leader at medium, 2 days to the handler, team
leader and manager at high, and 0 days to the same recipients at critical. The
ladder SHALL be data that an administrator can edit, not a compiled-in constant.

#### Scenario: A daily sweep does not repeat a rung

- **GIVEN** a timer whose 7-day rung fired yesterday
- **WHEN** the sweep runs again today, still more than 2 days from the deadline
- **THEN** no escalation MUST be raised
- @e2e exclude covered by ladder dedup integration tests

#### Scenario: Concurrent sweeps fire a rung once

- **GIVEN** two sweep passes evaluating the same unfired rung at the same moment
- **WHEN** both attempt to fire it
- **THEN** exactly one MUST succeed
- **AND** the other MUST observe the rung as already fired and take no action
- @e2e exclude covered by a concurrency test asserting the uniqueness constraint

#### Scenario: A downtime gap fires the skipped rungs in order

- **GIVEN** a timer whose 14-day and 7-day rungs are both unfired, and the
  deadline is now 5 days away
- **WHEN** the sweep runs
- **THEN** both rungs MUST fire, in ladder order, once each
- @e2e exclude covered by sweep integration tests over a seeded clock

### Requirement: An escalation rule is validated against its SLA in commensurable units

An escalation rule SHALL take the shape `{trigger, offset, offsetUnit,
notifyRole, escalateToRole, openIncident}` where `trigger` is `preBreach` or
`slaBreached` and `offsetUnit` is one of `hours`, `businessDays` or
`calendarDays` — the SAME unit set the SLA accepts, so that any SLA can carry a
warning expressed in its own terms.

An escalation rule SHALL be REFUSED unless an SLA is present on the same
configuration: a warning before a breach is meaningless without the term it
warns about.

For `trigger: preBreach`, the offset SHALL NOT exceed the SLA. This comparison
SHALL be made after NORMALISING both the offset and the SLA to an absolute
duration against the resolved working calendar. Comparing the two `value`
integers directly SHALL be treated as not meeting this requirement, because it
both rejects valid configurations and admits invalid ones whenever the units
differ.

#### Scenario: A short warning on a longer SLA is accepted across units

- **GIVEN** an SLA of `{value: 2, unit: calendarDays}` and a preBreach rule of
  `{offset: 24, offsetUnit: hours}`
- **WHEN** the configuration is validated
- **THEN** it MUST be accepted, because 24 hours is inside 2 calendar days
- @e2e exclude covered by escalation-rule validator unit tests

#### Scenario: A long warning on a shorter SLA is refused across units

- **GIVEN** an SLA of `{value: 48, unit: hours}` and a preBreach rule of
  `{offset: 5, offsetUnit: businessDays}`
- **WHEN** the configuration is validated
- **THEN** it MUST be refused with an error stating the offset exceeds the SLA
- @e2e exclude covered by escalation-rule validator unit tests

#### Scenario: An escalation rule without an SLA is refused

- **GIVEN** a configuration carrying an escalation rule and no SLA
- **WHEN** it is validated
- **THEN** it MUST be refused
- @e2e exclude covered by escalation-rule validator unit tests

### Requirement: Overdue is derived from the clock and never stored

The system SHALL derive overdue-ness by comparing the applicable deadline to the
current moment at read time. It SHALL NOT persist an `overdue` flag, an
`overdue` state value, or any equivalent field whose truth depends on the clock.

Derived time facts — whether a subject is past due, how long until it is due,
how long it has been overdue — SHALL be computed on read and SHALL account for
suspension: suspended time SHALL NOT contribute to being overdue.

A query for overdue subjects SHALL return the correct set whether or not any
background job has run. Correctness SHALL NOT depend on a sweep having
previously written a marker.

#### Scenario: Overdue is correct with the sweep disabled

- **GIVEN** a subject whose deadline passed while the sweep job was disabled
- **WHEN** the overdue query runs
- **THEN** the subject MUST be returned as overdue
- @e2e exclude covered by derivation unit tests with no job execution

#### Scenario: No writable overdue field is exposed

- **GIVEN** the timer and subject write surfaces
- **WHEN** a caller attempts to set overdue-ness directly
- **THEN** there MUST be no field accepting it
- @e2e exclude covered by API contract tests over the write surface

### Requirement: A deadline declares its legal effect, and only a legal one enforces

The system SHALL record, per timer, which kind of deadline it is:

- `none` — an internal or planned date;
- `servicenorm` — a service standard the organisation set itself;
- `wettelijk` — a term with legal effect.

These SHALL be able to coexist on one subject with DIFFERENT moments, because
they routinely do: the service standard is escalated on, the legal term is
alarmed on, and the planned date is neither.

An enforcing outcome — one that transitions the subject — SHALL be permitted
ONLY on a timer whose legal effect is `wettelijk`. A `servicenorm` or `none`
timer SHALL be advisory regardless of configuration, and an attempt to give one
an enforcing outcome SHALL be refused at arm time.

The breach of a `wettelijk` timer SHALL be recorded permanently and SHALL
survive the subject's completion, because the fact that a statutory term was
exceeded does not stop being true when the work is eventually done.

#### Scenario: Three deadlines on one subject

- **GIVEN** a subject with a planned date, a service standard and a statutory
  term at three different moments
- **WHEN** each is reached in turn
- **THEN** each MUST produce its own outcome independently
- **AND** none MUST be overwritten or superseded by another
- @e2e exclude covered by lattice integration tests

#### Scenario: A service standard cannot enforce

- **GIVEN** a timer with legal effect `servicenorm` configured with an enforcing
  outcome
- **WHEN** it is armed
- **THEN** arming MUST be refused with an error naming the legal-effect
  constraint
- @e2e exclude covered by timer-service validation tests

#### Scenario: A statutory breach outlives completion

- **GIVEN** a `wettelijk` timer that fired as breached
- **WHEN** the subject is later completed
- **THEN** the breach record MUST remain readable
- @e2e exclude covered by timer-history integration tests

### Requirement: A deadline's anchor is stored, so a moved anchor re-arms the timer

The system SHALL store a timer's clock ANCHOR — the named event the term runs
from, plus any offset from it — alongside the computed fire moment, and SHALL
NOT store only the computed moment.

The anchor is stored because a term frequently does not start when the subject
was created. A decision term on an objection runs from the day AFTER the
objection window closes, not from the day the objection was received; storing
only "fires on the 15th" loses the fact that the 15th was derived from a window
that can itself move.

When the anchoring event moves, the system SHALL re-arm the timer from the new
anchor: the prior timer SHALL be marked superseded rather than mutated, and a
new timer SHALL carry the recomputed moment, so the history shows what the
deadline used to be and why it changed. Escalation rungs already fired on the
superseded timer SHALL NOT be re-fired by the successor unless the successor's
deadline puts them back in the future.

#### Scenario: The clock starts after the window closes

- **GIVEN** an objection received on the 3rd, an objection window closing on the
  20th, and a decision term anchored to `window_closed` with an offset of one
  calendar day
- **WHEN** the timer is armed
- **THEN** the term MUST start on the 21st, not the 3rd
- @e2e exclude covered by anchor-resolution unit tests

#### Scenario: A moved window supersedes the timer

- **GIVEN** an armed timer anchored to a window that is subsequently extended
- **WHEN** the anchoring event moves
- **THEN** the prior timer MUST be marked superseded and a successor MUST carry
  the recomputed moment
- **AND** the superseded timer MUST NOT fire
- @e2e exclude covered by re-arm integration tests

### Requirement: The sweep is bounded to due work by index, not by a page of candidates

The sweep SHALL select the work it processes by a query bounded on state AND
fire moment together, so that every row it reads is a row it acts on.

It SHALL NOT select a fixed page of open records and then discard the not-yet-due
ones in application code. That shape has a measurable failure mode: once the
number of open records exceeds the page size, a due record outside the page is
never reached, and the job reports a clean pass while doing nothing about it.

Each pass SHALL be bounded by a batch limit and SHALL be safely re-entrant: two
overlapping passes SHALL NOT double-fire, and an interrupted pass SHALL leave no
timer half-fired. A pass SHALL log the counts it acted on, and those counts
SHALL reflect work performed rather than rows examined.

#### Scenario: A due timer beyond the batch size is still reached

- **GIVEN** a batch limit of N, more than N armed timers that are not yet due,
  and one armed timer that is due
- **WHEN** the sweep runs
- **THEN** the due timer MUST be processed in that pass
- @e2e exclude covered by sweep integration tests seeding beyond the batch limit

#### Scenario: Overlapping passes do not double-fire

- **GIVEN** two sweep passes overlapping over the same due timer
- **WHEN** both run to completion
- **THEN** the timer MUST fire exactly once
- **AND** its outcome MUST be applied exactly once
- @e2e exclude covered by a concurrency test over the sweep

#### Scenario: Counts report work, not reads

- **GIVEN** a sweep pass over a store containing many not-due timers and three
  due ones
- **WHEN** the pass logs its result
- **THEN** the reported fired count MUST be three
- @e2e exclude covered by sweep logging unit tests
