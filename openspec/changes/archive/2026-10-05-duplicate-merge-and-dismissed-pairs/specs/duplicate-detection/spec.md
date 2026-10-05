# duplicate-detection

## ADDED Requirements

### Requirement: A reviewed pair is recorded as not a duplicate and stops being offered (REQ-DMD-003)

Two objects SHALL be recordable as reviewed and not the same. The record
SHALL carry both uuids in a canonical order, the actor, a reason, the moment
and a fingerprint of the property values that were compared. The duplicate
scorer SHALL exclude a pair whose fingerprint still matches its dismissal,
and SHALL offer the pair again once the fingerprint differs. A dismissal
SHALL be reversible by a caller in the declared group, and both the
dismissal and its reversal SHALL be on the audit trail.

#### Scenario: the same false pair is not offered twice

- **GIVEN** two objects the scorer pairs, dismissed as not a duplicate
- **WHEN** the candidate list is read again with neither object changed
- **THEN** the pair is absent from the list

#### Scenario: a changed record is offered again

- **GIVEN** the same dismissed pair
- **WHEN** one of the two objects changes a compared property and the candidate list is read
- **THEN** the pair is offered again
- @e2e exclude {scorer behaviour, covered by unit tests}

#### Scenario: a dismissal names who made it

- **GIVEN** a dismissed pair
- **WHEN** an administrator reads the dismissal
- **THEN** it names the actor, the reason and the moment

### Requirement: A nominated property warns when its value already exists (REQ-DMD-004)

A property MAY carry `x-openregister-unique-hint`, validated at schema save
against the properties the schema declares. A create or an update whose
value for that property already exists on another object of the same schema
SHALL return a warning naming the objects that hold it, RBAC- and
tenant-scoped and bounded by the same candidate cap as the duplicate check.
The write SHALL still succeed: the hint is an alert, not a constraint. A
schema that requires a refusal declares `onCreate: "block"`, which is a
different declaration and keeps its meaning.

#### Scenario: a second case on the same KvK number is noticed at once

- **GIVEN** a schema nominating `kvkNumber` and an existing object holding `69599084`
- **WHEN** a second object is saved with the same value
- **THEN** the save succeeds and the response warns, naming the existing object

#### Scenario: the warning fires on an update too

- **GIVEN** the same schema and an existing object holding the value
- **WHEN** another object is updated to that value
- **THEN** the response warns, naming the existing object

#### Scenario: a hint on an undeclared property is refused at schema save

- **GIVEN** a schema nominating a property it does not declare
- **WHEN** the schema is saved
- **THEN** the save fails with HTTP 422 naming the property
- @e2e exclude {annotation validator, covered by unit tests}

#### Scenario: the warning respects what the caller may see

- **GIVEN** an existing object holding the value that the caller may not read
- **WHEN** the caller saves an object with the same value
- **THEN** the response reports that a match exists without naming the object
- @e2e exclude {RBAC path, covered by unit tests}
