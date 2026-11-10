## Purpose

A case page's view of the engine: the live-runs read narrowed to one
subject object, a completed-runs read for the same subject, and the row
contract a case-detail widget renders. Everything stays inside the
caller's existing organisation scope; the subject filter can only ever
narrow.

## ADDED Requirements

### Requirement: The live-runs read accepts a subject filter that only narrows

The live-runs read SHALL accept an optional `subject` parameter holding a
subject object uuid. When present, only runs whose `subject_uuid` equals
it SHALL be returned; when absent, the read SHALL behave exactly as it
does today.

The filter SHALL be applied in the datastore, as a predicate alongside
the existing organisation predicate, with the organisation scope applied
unconditionally. A run belonging to another organisation SHALL NOT be
returned or counted even when its subject uuid matches: a subject uuid is
guessable, and matching it grants nothing.

A caller whose organisation cannot be resolved SHALL receive an empty
result with no query issued, exactly as the unfiltered read already
requires. The reported total SHALL count the FILTERED set, so a case
widget can state what it could not fit.

Filtering SHALL NOT be performed client-side over a paginated result: a
page-then-filter read silently drops matching runs the page did not
contain.

#### Scenario: A case page lists only its own case's live runs

- **GIVEN** three live runs in the caller's organisation, two anchored to
  case X and one to case Y
- **WHEN** the live runs are read with `subject` = case X's uuid
- **THEN** exactly the two case X runs MUST be returned
- **AND** the reported total MUST be 2
- @e2e the case detail widget lists only the case's own runs

#### Scenario: A matching subject in another organisation stays invisible

- **GIVEN** a live run in organisation B anchored to a subject uuid known
  to a caller in organisation A
- **WHEN** the caller in organisation A reads the live runs with that
  `subject`
- **THEN** the result MUST be empty
- **AND** the total MUST be 0
- @e2e exclude covered by mapper unit tests over two organisations

#### Scenario: No subject means today's read

- **GIVEN** live runs across several subjects in the caller's organisation
- **WHEN** the live runs are read without `subject`
- **THEN** the result MUST equal the unfiltered org-scoped read
- @e2e exclude regression covered by the existing active-runs tests

### Requirement: A completed-runs read answers what already ran on this subject

The system SHALL expose a completed-runs read that returns terminal runs
(the run entity's terminal status set: completed, stopped, dead_letter,
failed) anchored to a REQUIRED subject uuid, inside the caller's
organisation scope.

The subject SHALL be required: a request without one SHALL be refused,
not treated as an org-wide history. The existing run history surface
SHALL keep its current filters, shape and visibility; this read is a new
surface beside it, not a widening of it.

Rows SHALL be ordered newest first, bounded by a capped limit, and
accompanied by an honest total counted with the same predicates. The
organisation and no-organisation rules of the live read SHALL apply
identically.

#### Scenario: A finished run shows up in the case's history

- **GIVEN** a run anchored to case X that has completed, and a live run on
  the same case
- **WHEN** the completed runs are read with `subject` = case X's uuid
- **THEN** the completed run MUST be returned with its terminal status
- **AND** the live run MUST NOT be in this result
- @e2e a finished flow appears in the case detail's run history

#### Scenario: History without a subject is refused

- **GIVEN** an authenticated caller
- **WHEN** the completed-runs read is requested without a subject
- **THEN** the request MUST be refused with an error naming the missing
  parameter
- @e2e exclude covered by controller unit tests

#### Scenario: A failed run is history too

- **GIVEN** a run anchored to case X that ended in `failed`
- **WHEN** the completed runs are read for case X
- **THEN** the failed run MUST be returned with status `failed`
- @e2e exclude covered by mapper unit tests over the terminal set

### Requirement: Both reads return the case-widget row and nothing heavier

Every row returned by the subject-filtered live read and by the
completed-runs read SHALL carry at minimum: the run's uuid, the flow's
human name (falling back to the flow id when no app claims it), the
current step or null when the run has no marking, the status, the
started-at timestamp, and the subject block (uuid, register, schema).

The two reads SHALL share one row shape, so a widget renders live and
finished runs as one list.

A row SHALL NOT carry the run's marking, its item list or its step log.
Those are kilobytes per run that a list never renders, and the item list
can hold the subject's own record data; the single-run read remains the
place to ask for a run's contents, and the run uuid in the row is the
deep link to it.

#### Scenario: The row carries the five widget fields

- **GIVEN** a live run anchored to a case
- **WHEN** the subject-filtered live runs are read
- **THEN** its row MUST carry uuid, flow name, step, status and the
  started-at timestamp
- @e2e exclude covered by controller unit tests over the summarised shape

#### Scenario: The row stays light

- **GIVEN** a completed run with items and a step log
- **WHEN** the completed runs are read for its subject
- **THEN** its row MUST NOT contain the marking, the items or the step log
- @e2e exclude covered by controller unit tests over the summarised shape
