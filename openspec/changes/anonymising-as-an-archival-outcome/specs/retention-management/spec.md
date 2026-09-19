# retention-management

## ADDED Requirements

### Requirement: Anonymising is an archival action a result type can choose (REQ-AAO-001)

`anonymiseren` SHALL be an archival action beside keeping, keeping
permanently and destroying. A nomination SHALL be able to derive it, a
destruction list SHALL be able to carry an entry nominated for it, and a
reviewer SHALL be able to answer an entry with it, recorded in the same
decision history as destroy, retain and transfer. A declared outcome, such
as a result type, SHALL be able to name it, so the choice is made in
configuration once rather than by a person per record.

#### Scenario: a result type says anonymise, and the pass does it

- **GIVEN** a result type naming `anonymiseren` and records reaching their archiefactiedatum under it
- **WHEN** the retention pass runs
- **THEN** those records are nominated for anonymising, not for destruction

#### Scenario: a reviewer may answer with it

- **GIVEN** a destruction list entry
- **WHEN** the reviewer answers anonymise with a reason
- **THEN** the decision history records the answer, the reviewer and the reason

#### Scenario: an unknown action is still refused

- **GIVEN** a declaration naming an action outside the vocabulary
- **WHEN** it is saved
- **THEN** the save fails naming the action
- @e2e exclude {validator, covered by unit tests}

### Requirement: A schema declares how it is anonymised (REQ-AAO-002)

A schema MAY declare an anonymisation profile naming, per property, one
treatment: remove the value, replace it with a fixed value, replace it with
a stable pseudonym, or generalise it to a declared coarser form. A stable
pseudonym SHALL be reproducible for the same source value within the
instance, so anonymised rows stay joinable without identifying anybody. A
profile naming a property the schema does not declare SHALL be refused at
schema save, and anonymising a record whose schema declares no profile SHALL
be refused naming the schema.

#### Scenario: the statistics survive the person leaving

- **GIVEN** a profile generalising `birthDate` to a year and `postcode` to its district, and removing the name
- **WHEN** a record is anonymised
- **THEN** the year and the district remain
- **AND** the name is gone

#### Scenario: two records of one person stay joinable

- **GIVEN** a profile replacing an identifier with a stable pseudonym, and two records holding the same identifier
- **WHEN** both are anonymised
- **THEN** both hold the same pseudonym
- **AND** the original identifier is absent from both

#### Scenario: a schema without a profile is not guessed at

- **GIVEN** a record whose schema declares no anonymisation profile
- **WHEN** anonymising is attempted
- **THEN** it is refused naming the schema
- @e2e exclude {validator, covered by unit tests}

### Requirement: Anonymising reaches every derived copy, or does nothing (REQ-AAO-003)

Anonymising SHALL update the record, the search index, the history
projection and every derived copy of the affected values in one act. If any
of them cannot be reached, the act SHALL fail as a whole and the record
SHALL be left unchanged. A record under a legal hold SHALL NOT be
anonymised, and the refusal SHALL name the hold.

#### Scenario: the name is not findable afterwards

- **GIVEN** an anonymised record whose name property was removed
- **WHEN** the search index is queried for that name
- **THEN** the record is not returned

#### Scenario: the history does not keep what the record dropped

- **GIVEN** the same record, which formerly held the name in a projected property
- **WHEN** the history projection is read
- **THEN** the former value is absent

#### Scenario: a legal hold stops it

- **GIVEN** a record nominated for anonymising and under a legal hold
- **WHEN** the pass runs
- **THEN** the record is skipped and the reason names the hold

#### Scenario: nothing is half done

- **GIVEN** a derived copy that cannot be reached
- **WHEN** anonymising is attempted
- **THEN** the act fails and the record is unchanged
- @e2e exclude {failure path, covered by unit tests}

### Requirement: An anonymisation reports what it removed and what it kept (REQ-AAO-004)

Every anonymisation SHALL produce a record naming the properties it changed,
the treatment applied to each, and the properties it deliberately kept, with
the actor or the pass that ran it and the decision that authorised it. The
record SHALL be readable from the object and from the run afterwards.

#### Scenario: an auditor can see what is still in there

- **GIVEN** an anonymised record
- **WHEN** its anonymisation record is read
- **THEN** it lists the changed properties with their treatments and the properties that were kept

#### Scenario: the run collects its records

- **GIVEN** a retention pass that anonymised forty records
- **WHEN** the run is read
- **THEN** each record's anonymisation is reachable from it
- @e2e exclude {run read, covered by unit tests}
