# object-lifecycle

## ADDED Requirements

### Requirement: Whether a bulk write fires the save events is configured per schema

The system SHALL read a `fireEventsOnBulkWrites` boolean from the schema's
`configuration`, then from the register's `configuration`, then from the app
config value `fire_events_on_bulk_writes` (`true` or `false`). The first
explicit value SHALL decide. When none is set, the events SHALL fire for a
schema that declares `x-openregister-lifecycle` and SHALL NOT fire for any
other schema. A schema SHALL keep `fireEventsOnBulkWrites` through a save of
its configuration.

#### Scenario: a schema without a lifecycle keeps today's bulk behaviour

- **GIVEN** a schema with no `fireEventsOnBulkWrites`, no lifecycle, and nothing set on its register or the instance
- **WHEN** a caller bulk-saves rows to it
- **THEN** no pre-save event is dispatched and every permitted row is written
- @e2e exclude {server-side policy with no screen; covered by `BulkWriteEventsTest::testASchemaWithoutTheSettingFiresNothing`}

#### Scenario: a lifecycle schema fires the events without a setting

- **GIVEN** a schema that declares `x-openregister-lifecycle` and no `fireEventsOnBulkWrites` anywhere
- **WHEN** the policy is asked whether a bulk write to it fires the events
- **THEN** the answer is yes
- @e2e exclude {server-side policy with no screen; covered by `BulkWriteEventsTest::testALifecycleSchemaFiresByDefault`}

#### Scenario: the schema value beats the register and the instance

- **GIVEN** the instance value `true`, the register value `true`, and the schema value `false`
- **WHEN** the policy is asked for that schema
- **THEN** the answer is no
- @e2e exclude {server-side policy with no screen; covered by `BulkWriteEventsTest::testTheSchemaValueWins` and its register and instance siblings}

#### Scenario: the setting survives a schema save

- **WHEN** a schema is saved with `configuration.fireEventsOnBulkWrites: true`
- **THEN** reading the schema back returns `fireEventsOnBulkWrites: true`
- @e2e exclude {entity round trip; covered by `BulkWriteEventsTest::testTheSchemaKeepsTheSetting`}

### Requirement: A bulk write with events on runs each row's pre-save event and refuses only the stopped row

When the events are on for a row's schema, `SaveObjects::saveObjects()` SHALL
dispatch `ObjectCreatingEvent` for a row that creates and `ObjectUpdatingEvent`
for a row that updates, with the stored object as the old object. The event
SHALL run after the row's permission, property and validation checks and
before anything is written. A stopped event SHALL refuse that row into
`invalid` with the hook's message and the row's position, and the other rows
SHALL be written. Data a hook sets on the object or through
`setModifiedData()` SHALL be written. After the write, each row SHALL
dispatch its created or updated event, unless a `SystemOperationContext` is
active. This covers every caller of `saveObjects()`: `POST
/api/bulk/{register}/{schema}/save`, the CSV and Excel import, the import
preview and the flow `ObjectWriteNode`.

#### Scenario: a guard refuses one row of a bulk update

- **GIVEN** a schema with `fireEventsOnBulkWrites: true` and a listener that stops `ObjectUpdatingEvent` when the new status is `in-deliberation`
- **WHEN** a caller bulk-saves two stored objects, one moving to `in-deliberation` and one moving to `closed`
- **THEN** the first is in `invalid` with the listener's message and its position, and only the second is written
- @e2e exclude {needs an app guard listener; covered by `BulkWriteEventsTest::testAStoppedUpdateRefusesOnlyThatRow`, which fails on development}

#### Scenario: a hook fills in a value on a bulk create

- **GIVEN** the events on and a listener on `ObjectCreatingEvent` that sets `number` through `setModifiedData()`
- **WHEN** a caller bulk-creates a row without `number`
- **THEN** the written row carries the listener's `number`, and the created event follows the write
- @e2e exclude {needs a test listener; covered by `BulkWriteEventsTest::testACreatingHookValueIsWritten`}

#### Scenario: events off writes as before

- **GIVEN** a schema with `fireEventsOnBulkWrites: false` and the same stopping listener
- **WHEN** the caller sends the same batch
- **THEN** both rows are written and no pre-save event is dispatched
- @e2e exclude {covered by `BulkWriteEventsTest::testEventsOffWritesEveryRow`}

### Requirement: A bulk row is screened against the register and schema it is written to

When the caller of `SaveObjects::saveObjects()` names a register, every row
SHALL be screened against that register, whatever its `@self.register` says,
because the writer stores the row in that register's table. The same SHALL
hold for a schema the caller names. `@self.register` and `@self.schema` SHALL
decide only when the caller names none (mixed-schema mode). The create or
update decision, the permission, appendOnly and draft checks, and the
bulk-write events setting SHALL all use the register and schema the row is
written to.

#### Scenario: a row names another register for a stored object

- **GIVEN** a stored object in register 3, a route naming register 3, and a guard on its update
- **WHEN** a bulk row for that object carries `@self.register: 9`
- **THEN** the row is screened as an update in register 3, the guard refuses it, and nothing is written
- @e2e exclude {needs an app guard listener; covered by `BulkWriteEventsTest::testARowNamingAnotherRegisterIsScreenedAgainstTheRoute` and `testAnotherRegistersSettingDoesNotDecide`}

#### Scenario: a row names its own schema in a call that names another

- **GIVEN** a call naming schema 7, and a caller who may not create in schema 7
- **WHEN** a row carries `@self.schema: 8`
- **THEN** the row is refused for schema 7, the schema it would be written to
- @e2e exclude {covered by the `row names its own schema` case of `SaveObjectsBulkSafeguardsGoldenTest`; the `mixed batch row names its schema` case pins that `@self.schema` still decides without a call schema}

