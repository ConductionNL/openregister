# bulk-action-jobs Specification

## Purpose
A bulk action over many records runs as one named job. This spec covers what such a job remembers and how an operator undoes it: which actions can be reversed and for how long, the prior values a job keeps, the reversal as a job of its own, and the members that are skipped because someone changed them since.

## Requirements

### Requirement: A bulk action declares whether it can be undone, and for how long (REQ-UBA-001)

A bulk action SHALL declare `reversible` and, when reversible, a reversal
window. The preview SHALL report both before the job commits. An action that
destroys data, dispatches a message or transfers to an e-depot SHALL NOT be
declarable as reversible, and a schema declaring it so SHALL be refused at
save with HTTP 422 naming the action.

#### Scenario: the preview says the job can be undone

- **GIVEN** a status transition action declared reversible with a window of seven days
- **WHEN** a job for it is previewed
- **THEN** the preview reports the job as reversible and names the window

#### Scenario: a destruction is not declarable as reversible

- **GIVEN** a schema declaring a destruction action as reversible
- **WHEN** the schema is saved
- **THEN** the save fails with HTTP 422 naming the action

### Requirement: A reversible job records the prior value of every property it changes (REQ-UBA-002)

For a reversible action, each per-member outcome SHALL record the properties
the job changed together with their values before the change. An instance
ceiling SHALL bound how much a single job may store this way, and a job
whose selection and action would exceed it SHALL be refused at creation
naming the ceiling.

#### Scenario: the prior status is on the member outcome

- **GIVEN** a reversible job moving forty objects from `in behandeling` to `afgehandeld`
- **WHEN** the job completes
- **THEN** each member outcome records `status` with the value `in behandeling`

#### Scenario: a job above the storage ceiling is refused at creation

- **GIVEN** an instance ceiling and a job whose recorded prior values would exceed it
- **WHEN** the job is created
- **THEN** creation fails naming the ceiling
- @e2e exclude {a creation-time refusal with no HTTP fixture large enough to trip it; asserted in tests/Unit/Service/BulkJob/BulkJobPriorValueCaptureTest.php::testAJobAboveTheUndoCeilingIsRefusedAtCreationNamingTheCeiling}

### Requirement: A reversal is a new job that names the job it undoes (REQ-UBA-003)

Reversing a job SHALL create a new bulk job whose selection is the members
of the original, whose writes are the recorded prior values, and which names
the original job as its cause. The reversal SHALL run under the same
ceiling, preview, progress reporting and authorization as any other job, and
SHALL be refused outside the reversal window or for an action not declared
reversible, naming the reason. Every member's audit trail SHALL show the
original write and the reversal as two acts with their own actors.

#### Scenario: a hundred cases go back in one act

- **GIVEN** a completed reversible job over a hundred objects
- **WHEN** it is reversed inside its window
- **THEN** a new job runs restoring the recorded prior value on each member
- **AND** the new job names the original as its cause
- @e2e exclude {needs the background worker to have walked the original's members, which no HTTP call can guarantee; asserted in tests/Unit/Service/BulkJob/BulkJobReversalTest.php::testAHundredCasesGoBackAsOneJobNamingTheOriginal and tests/Unit/BulkAction/RestorePriorValuesActionTest.php::testTheRecordedPriorValueIsWrittenBack}

#### Scenario: the reversal is authorised for the person doing it

- **GIVEN** a completed job created by an administrator
- **WHEN** a caller without write access to the members requests the reversal
- **THEN** the reversal is refused and nothing is written
- @e2e exclude {needs a completed job, so the same worker dependency; asserted in tests/Unit/Controller/BulkJobsControllerTest.php::testACallerWhoCannotReadTheJobCannotUndoIt and ::testTheReversalRunsAsThePersonAskingForItNotTheOriginalActor}

#### Scenario: a reversal outside the window is refused

- **GIVEN** a job whose reversal window has passed
- **WHEN** a reversal is requested
- **THEN** it is refused naming the window
- @e2e exclude {time-dependent, and the HTTP surface exposes no clock; asserted in tests/Unit/Service/BulkJob/BulkJobReversalTest.php::testAReversalOutsideTheWindowIsRefusedNamingTheWindow}

### Requirement: A member changed since the job is not silently overwritten (REQ-UBA-004)

A reversal SHALL skip any member whose recorded properties changed after the
original job wrote them, SHALL report those members by name with the reason,
and SHALL restore the rest. A reversal SHALL never write a prior value over
a later change.

#### Scenario: somebody's later correction survives the undo

- **GIVEN** a reversible job over ten objects, one of which a colleague edited afterwards
- **WHEN** the job is reversed
- **THEN** nine objects are restored
- **AND** the tenth is reported as not reversible, naming the later change
- @e2e exclude {needs the background worker to have walked both jobs; asserted in tests/Unit/BulkAction/RestorePriorValuesActionTest.php::testALaterEditIsReportedByNameAndNeverOverwritten}

#### Scenario: the report is readable after the reversal

- **GIVEN** a completed reversal with skipped members
- **WHEN** the reversal job is read
- **THEN** each skipped member is listed with its reason
- @e2e exclude {needs a completed reversal, so the same worker dependency; asserted in tests/Unit/Controller/BulkJobsControllerTest.php::testTheMembersRouteCarriesTheOutcomeAndItsReason and tests/Unit/BulkAction/RestorePriorValuesActionTest.php::testALaterEditIsReportedByNameAndNeverOverwritten}

### Requirement: A bulk action is one job with an actor, a selection and a reason (REQ-BAJ-001)

The system SHALL run a bulk action as a named job carrying the action, the
selection, the actor, the job state and a per-member outcome. The
selection SHALL be recorded either as an explicit list of ids or as the
query with its filters, together with the count at creation, and the job
SHALL report which of the two it holds. An action MAY declare that a
justification is required; where it does, the job SHALL NOT commit without
one and the text SHALL appear in the audit entry of every member. An
instance-level ceiling SHALL bound the largest selection one job may
carry, and a larger selection SHALL be refused at creation naming the
ceiling.

#### Scenario: selecting every match is not the same as selecting the page

- **GIVEN** a query matching 400 objects and a page showing 25
- **WHEN** a job is created from every match
- **THEN** the job records the query and the count 400
- **AND** the response says the selection is the whole result set, not the page

#### Scenario: a distribution without a reason is refused

- **GIVEN** an action declaring that a justification is required
- **WHEN** a job for it is committed with no justification
- **THEN** the commit is refused naming the action
- **AND** no object was modified

#### Scenario: a selection above the ceiling is refused at creation

- **GIVEN** an instance ceiling of 1,000 and a selection of 4,000
- **WHEN** the job is created
- **THEN** the creation fails naming the ceiling and the count
- @e2e exclude {a creation-time validator; asserted in tests/Unit/Service/BulkJob/BulkJobServiceTest.php}

### Requirement: A bulk action is previewed before it commits (REQ-BAJ-002)

A bulk job SHALL be created in a previewed state that reports, per object,
whether the action would be applied, skipped with a reason, or refused
with the rule that refused it, and SHALL write nothing. The preview and
the commit SHALL run the same executor, differing only in whether the
write is made. An object the actor may not write SHALL be reported as
refused, never as skipped. Where the selection is a query, the commit
SHALL re-resolve it and report the difference from the count at creation.

#### Scenario: the preview names what would be skipped

- **GIVEN** a selection of 400 objects of which 12 are in a state the action does not apply to
- **WHEN** the job is created
- **THEN** the preview reports 388 to apply and 12 skipped, each with its reason
- **AND** no object was modified

#### Scenario: a refusal is not a skip

- **GIVEN** a selection including three objects the actor may not write
- **WHEN** the job is previewed
- **THEN** those three are reported refused with the rule that refused them
- **AND** they are not counted as skipped

#### Scenario: a selection that grew is reported at commit

- **GIVEN** a query-backed job created when the query matched 400 objects
- **WHEN** it is committed and the query now matches 406
- **THEN** the job reports the delta of 6 before applying
- @e2e exclude {the query has to change between two calls, which HTTP cannot stage without a race; asserted in tests/Unit/Service/BulkJob/BulkJobServiceTest.php}

### Requirement: A bulk action reports progress, is cancellable and is safe to retry (REQ-BAJ-003)

A running job SHALL report its position and its counts, and a user SHALL
read their own running and finished jobs. Cancelling SHALL stop before the
next object, leave already committed objects committed, and report the
boundary. A retried job SHALL NOT repeat a member it already applied. A
finished job SHALL offer its per-object outcome for download, including
every skipped member and its reason. An action MAY declare guards, and the
homogeneity guard SHALL refuse a selection spanning more than one schema
version, naming the versions and their counts.

#### Scenario: cancelling says where it stopped

- **GIVEN** a running job that has applied 120 of 400 objects
- **WHEN** the actor cancels it
- **THEN** the job stops before object 121, reports 120 applied
- **AND** the 120 remain modified
- @e2e exclude {needs the background worker to have walked part of the job, so an HTTP assertion would be a timing race; asserted in tests/Unit/Service/BulkJob/BulkJobExecutorTest.php}

#### Scenario: a retry does not act twice

- **GIVEN** a job that failed after applying 300 of 400 members
- **WHEN** it is retried
- **THEN** the 300 applied members are skipped as already applied
- **AND** the remaining 100 are processed
- @e2e exclude {needs a job that already failed part way, which the worker produces and HTTP cannot; asserted in tests/Unit/Service/BulkJob/BulkJobServiceTest.php}

#### Scenario: a mixed-version attribute write is refused

- **GIVEN** an attribute write action declaring the homogeneity guard, and a selection spanning two schema versions
- **WHEN** the job is previewed
- **THEN** the job is refused naming both versions and their counts
- @e2e exclude {needs two live schema versions over one population; asserted in tests/Unit/Service/BulkJob/BulkJobExecutorTest.php}
