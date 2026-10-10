# object-source-providers — delta

## ADDED Requirements

### Requirement: Pluggable object-source provider interface
The system SHALL define a read-only `ObjectSourceProvider` interface exposing `getId()`,
`isEnabled()`, `find(register, schema, id)`, `findAll(register, schema, query)`, and
`count(register, schema, query)`, returning `ObjectEntity` instances built in memory and never
persisted to OpenRegister storage. The interface MUST NOT expose create/update/delete operations.

#### Scenario: Provider returns a virtual object
- **GIVEN** a registered, enabled `ObjectSourceProvider` for a source holding one record
- **WHEN** `find(register, schema, id)` is called for that record
- **THEN** it returns an `ObjectEntity` carrying the record's data with `register`/`schema`/`@self` set
- **AND** no row is written to any OpenRegister magic table.
- `@e2e exclude` Backend interface contract; covered by `ObjectSourceProviderTest` + `CalDavVtodoObjectSourceProviderTest`. No OR-side UI.

#### Scenario: Provider returns null for a missing record
- **GIVEN** a registered, enabled provider whose source does not hold record `x`
- **WHEN** `find(register, schema, 'x')` is called
- **THEN** it returns `null` (the read path translates this to a uniform 404).
- `@e2e exclude` Backend contract; unit-tested.

### Requirement: Object-source provider registry
The system SHALL provide an `ObjectSourceRegistry` that discovers providers via a dependency-injection
tag at bootstrap, keyed by `getId()`, and resolves a provider by id. Duplicate ids MUST follow a
first-wins policy in production and MUST throw in development.

#### Scenario: Resolve a registered provider by id
- **GIVEN** a provider tagged for discovery with `getId()` returning `caldav-vtodo`
- **WHEN** the registry is queried for `caldav-vtodo`
- **THEN** it returns that provider instance.
- `@e2e exclude` Backend registry; unit-tested.

#### Scenario: Duplicate id throws in development
- **GIVEN** two providers both returning the same `getId()`
- **WHEN** the registry is built in a development environment
- **THEN** it throws, surfacing the collision.
- `@e2e exclude` Backend registry; unit-tested.

### Requirement: x-openregister-object-source schema key
The system SHALL accept an `x-openregister-object-source` key on a schema declaring `{ provider,
readOnly, config }`, where `provider` is a non-empty string naming a registered provider. A schema
carrying this key SHALL have its objects served by the named provider instead of the magic table.

#### Scenario: Schema with an object source validates
- **GIVEN** a schema declaring `x-openregister-object-source` with `provider: caldav-vtodo`
- **WHEN** the schema is validated
- **THEN** validation passes and the parsed source is exposed via the schema entity.
- `@e2e exclude` Backend schema validation; unit-tested.

### Requirement: Read path delegates to the provider when an object source is present
For a schema declaring `x-openregister-object-source`, the system SHALL delegate
`find`/`findAll`/`count` to the named provider when it is enabled, and SHALL NOT read from the magic
table. For a schema WITHOUT the key, the system SHALL read from the magic table exactly as before
(no behavioural change). If the named provider is missing or disabled, the read SHALL return an
empty result and log a warning rather than erroring or falling back to the database.

#### Scenario: Sourced schema reads from the provider, not the database
- **GIVEN** a schema bound to an enabled `caldav-vtodo` provider
- **WHEN** `ObjectService::findAll()` is called for that schema
- **THEN** the results come from the provider
- **AND** the magic table for that schema is not queried.
- `@e2e exclude` Backend read-path delegation; integration-tested.

#### Scenario: Non-sourced schema is unaffected
- **GIVEN** an ordinary schema with no `x-openregister-object-source`
- **WHEN** its objects are read
- **THEN** the read goes through MagicMapper exactly as before this change.
- `@e2e exclude` Backend regression guard; covered by existing object-read tests.

#### Scenario: Missing provider degrades to empty, not error
- **GIVEN** a schema referencing an object-source provider id that is not registered
- **WHEN** its objects are read
- **THEN** an empty result is returned and a warning is logged (no 500, no DB read).
- `@e2e exclude` Backend degradation; unit-tested.

### Requirement: Object-source reads enforce RBAC and fail closed
Provider-served reads SHALL apply the schema's object-level read authorization for the acting user
and SHALL return a uniform 404 (or omit the object from a list) when the object is absent or access
is denied, without distinguishing the two.

#### Scenario: Denied access is indistinguishable from absent
- **GIVEN** a virtual object the acting user is not authorized to read
- **WHEN** `find` is called for it
- **THEN** the response is a 404 identical to the response for a non-existent id.
- `@e2e exclude` Backend authorization/anti-oracle; unit-tested.

### Requirement: Writes to a sourced schema are rejected
The system SHALL reject create/update/delete on a schema declaring `x-openregister-object-source`
with a clear error before any persistence, keeping the external source authoritative.

#### Scenario: Saving a virtual-sourced object is rejected
- **GIVEN** a schema bound to a read-only object-source provider
- **WHEN** `ObjectService::saveObject()` is called for that schema
- **THEN** it throws a clear read-only-projection error and writes nothing.
- `@e2e exclude` Backend write-guard; unit-tested.

### Requirement: CalDAV-VTODO reference object-source provider
The system SHALL provide a `caldav-vtodo` `ObjectSourceProvider` that reads VTODOs from the acting
user's calendars and maps VTODO properties (`SUMMARY`, `DESCRIPTION`, `ATTENDEE`, `DUE`, `STATUS`,
and `X-OPENREGISTER-*` link properties) onto the schema's fields, returning them as virtual objects.
It SHALL report `isEnabled()` based on Tasks/Calendar availability and SHALL never write to OR
storage.

#### Scenario: VTODOs surface as virtual objects
- **GIVEN** the acting user has VTODOs in a calendar selected by the provider config
- **WHEN** `findAll()` runs for a schema bound to `caldav-vtodo`
- **THEN** each VTODO appears as a virtual object with title/assignee/dueDate/status mapped from the VTODO.
- `@e2e exclude` Backend CalDAV mapping; covered by `CalDavVtodoObjectSourceProviderTest`.

#### Scenario: Provider disabled when Tasks/Calendar absent
- **GIVEN** the Calendar/Tasks capability is unavailable
- **WHEN** `isEnabled()` is checked
- **THEN** it returns false and reads of a bound schema degrade to empty + warning.
- `@e2e exclude` Backend capability gating; unit-tested.
