# bulk-action-jobs

## ADDED Requirements

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
