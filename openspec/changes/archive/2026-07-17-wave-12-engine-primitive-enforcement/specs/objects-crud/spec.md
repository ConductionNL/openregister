# objects-crud Specification Delta

## ADDED Requirements

### Requirement: JSON-Schema readOnly is enforced on the UPDATE write path

A property declared `readOnly: true` SHALL NOT be modified after the object is
created. An UPDATE that carries a different value for such a property SHALL be
REJECTED.

Declaring `readOnly` SHALL NOT be satisfied by the declaration round-tripping
through the schema, nor by the OAS output rendering it: the validator has no
`readOnly` keyword and discards it silently. Only a rejected write is compliance.

CREATE SHALL NOT be covered: there is no prior value to violate. `readOnly` means
"immutable after creation", not "never settable".

Enforcement SHALL be a schema invariant, not a permission check. It SHALL apply
regardless of the caller's role, including admins, and SHALL apply regardless of
whether the schema runs hard validation — a register that validates softly may
still not rewrite an immutable field.

An UPDATE that resends a `readOnly` property with its stored value SHALL be
ALLOWED: a full-document PUT resends every field and is not a mutation.
Comparison SHALL be strict, so a type change is a mutation.

This requirement covers top-level properties. `readOnly` nested inside object or
array sub-schemas is out of scope.

#### Scenario: Mutating a readOnly property is rejected

- **GIVEN** a schema declaring `bsn` as `readOnly: true`
- **AND** a stored object whose `bsn` is `"111111111"`
- **WHEN** an UPDATE carries `bsn: "999999999"`
- **THEN** the write is REJECTED
- **AND** the stored value is unchanged

#### Scenario: Creation may set a readOnly property

- **GIVEN** a schema declaring `bsn` as `readOnly: true`
- **WHEN** an object is CREATED with a `bsn`
- **THEN** the write succeeds

#### Scenario: Resending the stored value is not a mutation

- **WHEN** an UPDATE carries a `readOnly` property with the value already stored
- **THEN** the write succeeds

#### Scenario: The invariant binds admins

- **WHEN** an admin attempts to mutate a `readOnly` property on UPDATE
- **THEN** the write is REJECTED

#### Scenario: Every violation is reported

- **WHEN** an UPDATE mutates two `readOnly` properties
- **THEN** the rejection names both

### Requirement: The bulk write path enforces the same invariants as the single-object path

The bulk write path is a separate pipeline that does not delegate to the
single-object save. It SHALL NOT therefore offer weaker guarantees: an invariant
enforced on `saveObject` SHALL hold for the same write submitted in bulk.

Each row's action SHALL be derived from whether it targets an object that ALREADY
EXISTS, resolved against the database. The presence of a client-supplied uuid
SHALL NOT be treated as evidence of an UPDATE — bulk save is an upsert and a
caller may choose the id of a new object.

A row that targets an existing object SHALL be authorized as `update`, not
`create`.

A row that targets an existing object on an append-only schema SHALL be REJECTED.
Inserts on append-only schemas SHALL remain allowed.

Rejection SHALL be per row: a rejected row SHALL NOT fail its batch, and the
remaining rows SHALL persist.

Existence resolution SHALL NOT introduce a per-row database round trip. Rows
without a uuid SHALL NOT be queried.

#### Scenario: A bulk update of an append-only row is rejected

- **GIVEN** an append-only schema and an existing object
- **WHEN** a bulk write carries a row with that object's uuid
- **THEN** the row is REJECTED as `AppendOnlyException`
- **AND** the row does not reach persistence

#### Scenario: A bulk insert into an append-only schema succeeds

- **GIVEN** an append-only schema
- **WHEN** a bulk write carries a row for a new object
- **THEN** the row persists

#### Scenario: A create with a caller-chosen uuid is not an update

- **GIVEN** an append-only schema
- **WHEN** a bulk write carries a row with a uuid that does not yet exist
- **THEN** the row persists

#### Scenario: An existing row is authorized as update

- **GIVEN** a caller granted `create` but not `update`
- **WHEN** a bulk write carries a row targeting an existing object
- **THEN** the row is REJECTED as `PermissionDeniedException`

#### Scenario: One rejected row does not fail the batch

- **WHEN** a bulk write carries one append-only violation and one valid insert
- **THEN** the violation is rejected
- **AND** the valid insert persists
