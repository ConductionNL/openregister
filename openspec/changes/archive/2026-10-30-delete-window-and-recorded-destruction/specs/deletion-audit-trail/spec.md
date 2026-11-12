# deletion-audit-trail

## ADDED Requirements

### Requirement: The recovery window is published, not only computed (REQ-DWD-001)

A soft-deleted object SHALL publish the date from which it may be
destroyed and the number of days remaining. The value SHALL appear on the
object's deletion metadata, in the trash listing and in the refusal a
caller receives when reading the object through the normal path. A restore
before that date SHALL remain a single act and SHALL be recorded with its
actor.

#### Scenario: a caseworker sees how long they have

- **GIVEN** an object soft-deleted today under a 30-day retention
- **WHEN** the trash is listed
- **THEN** the entry names the destroyable-from date and 30 days remaining

#### Scenario: the refusal says where the object went

- **GIVEN** the same object
- **WHEN** a user reads it through the normal object path
- **THEN** the refusal states that it is deleted and names the destroyable-from date

### Requirement: Destruction is a named right and a recorded act (REQ-DWD-002)

Permanently destroying an object SHALL require a right declared in the
permission catalogue rather than an administrator check, and a refusal
SHALL name the rule that refused it. The act SHALL record the actor, the
time, the scope destroyed and the rule it ran under, and that record SHALL
survive the object it describes.

#### Scenario: a caseworker cannot destroy

- **GIVEN** a soft-deleted document and a user without the destroy right
- **WHEN** the user tries to destroy it
- **THEN** the refusal names the missing right and the object is unchanged

#### Scenario: the record outlives the object

- **GIVEN** a record manager who destroys a soft-deleted object
- **WHEN** the destruction record is read afterwards
- **THEN** it names the actor, the time and what was destroyed
- **AND** the object itself is gone

### Requirement: Destruction has a declared scope (REQ-DWD-003)

A schema SHALL be able to declare what is destroyed with an object: its
versions, its notes, its files, its tasks, its timeline entries and the
audit rows that carry its content. The act SHALL preview that scope with
counts before it runs and SHALL report what was destroyed afterwards. The
evidence that the destruction happened SHALL NOT be inside the scope.

#### Scenario: what hangs off the object goes with it

- **GIVEN** an object with four notes, two files and eleven timeline entries, all inside the declared scope
- **WHEN** it is destroyed
- **THEN** the preview names 4, 2 and 11
- **AND** afterwards none of them resolves

#### Scenario: the proof of destruction remains

- **GIVEN** the same destruction
- **WHEN** the audit trail is read
- **THEN** the destruction entry is present and readable
- @e2e exclude {audit path, covered by unit tests}

### Requirement: The AVG clock and the Archiefwet clock stay apart (REQ-DWD-004)

An object SHALL carry the date its personal data loses its lawful purpose
and the date its archive retention expires, each with the rule that
produced it. Where the two disagree the object SHALL NOT be destroyed and
the conflict SHALL be reported for a person to decide. An object under a
legal hold SHALL be exempt from both clocks.

#### Scenario: the two dates are readable and separate

- **GIVEN** an object whose processing activity gives an AVG date of 2027 and whose selectielijst gives an archive date of 2032
- **WHEN** the object is read
- **THEN** both dates are returned, each naming its rule

#### Scenario: a disagreement is reported, not resolved silently

- **GIVEN** the same object when the AVG date has passed and the archive date has not
- **WHEN** the retention pass runs
- **THEN** the object is not destroyed and the conflict is reported naming both rules
- @e2e exclude {background pass, covered by unit tests}

#### Scenario: a hold outranks both clocks

- **GIVEN** an object under a legal hold with both dates in the past
- **WHEN** the retention pass runs
- **THEN** the object is untouched and the hold is named as the reason
- @e2e exclude {background pass, covered by unit tests}
