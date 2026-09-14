# objects-crud

## ADDED Requirements

### Requirement: A refused write names the values that conflict (REQ-CSO-001)

When a write is refused for a version mismatch, the HTTP 409 body SHALL
list, for every conflicting property, the value the caller sent, the value
the caller read, and the value now stored, together with the actor and the
moment of the change that caused the refusal. A property SHALL be listed as
conflicting only when the caller changed it and another write changed it
since the caller read it.

#### Scenario: the second person is told what the first one wrote

- **GIVEN** two callers who read the same object, the first saving `status` and the second saving `status` and `summary`
- **WHEN** the second save is refused
- **THEN** the body lists `status` with the value sent, the value read and the value stored
- **AND** `summary` is not listed

#### Scenario: an untouched property is not a conflict

- **GIVEN** a caller whose write touches only `summary` and a concurrent write that changed `status`
- **WHEN** the caller's write is refused for the version mismatch
- **THEN** `status` is not listed as a conflicting property of that write
- @e2e exclude {response shape, covered by unit tests}

#### Scenario: the refusal names who changed it

- **GIVEN** a refused write
- **WHEN** the body is read
- **THEN** it names the actor and the moment of the change that caused the refusal

### Requirement: The conflict body discloses no more than a read would (REQ-CSO-002)

The conflict body SHALL be filtered by field-level security for the calling
principal. A conflicting property the caller may not read SHALL be named as
conflicting with no values attached.

#### Scenario: a restricted property conflicts without showing itself

- **GIVEN** a conflicting property restricted to a group the caller is not in
- **WHEN** the caller's write is refused
- **THEN** the property is named as conflicting
- **AND** no sent, read or stored value is present for it
- @e2e exclude {field filter, covered by unit tests}

### Requirement: Every write path asserts the expected version (REQ-CSO-003)

The version assertion SHALL be made in the save pipeline, so that a full
replace asserts exactly as a partial update does. A write carrying an
expected version or an `If-Match` value that no longer matches SHALL be
refused with HTTP 409 and the conflict body. A write carrying neither SHALL
behave as it does today. A refused write SHALL leave an audit entry naming
the expected and the current version, the actor and the properties that
conflicted.

#### Scenario: a full replace from a stale read is refused

- **GIVEN** a caller who read an object, and a concurrent write that changed it
- **WHEN** the caller sends a full replace with the version they read
- **THEN** the write is refused with HTTP 409 and the conflict body
- **AND** the stored object is unchanged

#### Scenario: a write without an expected version is unchanged

- **GIVEN** a client that sends no expected version and no `If-Match`
- **WHEN** it writes an object another caller changed
- **THEN** the write behaves exactly as before this change

#### Scenario: the refusal is on the audit trail

- **GIVEN** a refused write
- **WHEN** the object's audit trail is read
- **THEN** it holds an entry naming the expected version, the current version and the actor
- @e2e exclude {audit entry, covered by unit tests}
