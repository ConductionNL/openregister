## ADDED Requirements

### Requirement: A bulk save applies property authorization per row

Each row of a bulk save SHALL be decided by the same property authorization as a single save: a row writing a value
the caller may not write SHALL be refused, naming the properties; on an update, a stored property the row omits and
the caller may not read or update SHALL be carried forward. Unchanged values SHALL pass.

#### Scenario: A bulk row writes a protected property
- **GIVEN** `restricted` is writable only by group `service`, and a member of `staff` may update the object
- **WHEN** the staff member bulk-saves a row setting `restricted: false`
- **THEN** the row is refused into `invalid`, naming `restricted`, and is not written

#### Scenario: A bulk row omits a protected property
- **WHEN** the staff member bulk-saves the row without `restricted`
- **THEN** the stored `restricted` is kept

### Requirement: The mixed-schema bulk route checks every schema its rows name

`/api/bulk/{register}/0/save` SHALL refuse the batch with 403 when a row names a schema the caller may not manage,
or a schema that cannot be resolved.

#### Scenario: A row names a schema the caller may not manage
- **WHEN** a non-admin posts a mixed-schema batch whose row names schema `document` without a manage rule for them
- **THEN** the answer is 403 naming `document` and nothing is saved
