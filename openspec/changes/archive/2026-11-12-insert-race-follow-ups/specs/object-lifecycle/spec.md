# object-lifecycle

## ADDED Requirements

### Requirement: A create written as an update is announced as an update

When a create finds its identifier taken and is written as an update of the
stored row, the system SHALL dispatch `ObjectUpdatedEvent` with the stored
row as the old object, and SHALL NOT dispatch `ObjectCreatedEvent` for it.

#### Scenario: a create whose identifier is taken

- **GIVEN** a stored object in status `open`
- **WHEN** a create with the same identifier and status `closed` is written as an update
- **THEN** the events are `ObjectCreatingEvent`, `ObjectUpdatingEvent` and `ObjectUpdatedEvent`, and the updated event's old object is `open`
- @e2e exclude {needs a concurrent writer; covered by `MagicMapperInsertRaceGuardTest::testALostInsertAnnouncesAnUpdateNotACreate`}

#### Scenario: a create that wins

- **WHEN** a create claims a free identifier
- **THEN** the events are `ObjectCreatingEvent` and `ObjectCreatedEvent`, also after an earlier insert on the same mapper replaced a row, and also when a listener on its creating event ran an insert that replaced a row
- @e2e exclude {covered by `MagicMapperInsertRaceGuardTest::testAWonInsertAnnouncesACreate`, `testAnInsertAfterALostOneAnnouncesACreateAgain` and `testANestedLostInsertDoesNotTurnTheOuterCreateIntoAnUpdate`}

### Requirement: A save over a stored row the caller cannot see is judged as an update of it

When a save with RBAC on names a uuid that is stored but hidden from the
caller, the system SHALL require both the schema's create rule on the
incoming data and the update rule against the stored row (its owner and the
row). A uuid that is not stored SHALL be judged by the create rule alone.
With RBAC off the stored row SHALL NOT be looked up.

#### Scenario: a caller writes over a row they cannot read

- **GIVEN** an object owned by `agent` that `mallory` cannot read
- **WHEN** `mallory` saves to its uuid
- **THEN** the save is checked with action `create` on the incoming data and with action `update` against owner `agent` and the stored row, and is refused when either refuses
- @e2e exclude {covered by `ObjectServiceHiddenRowWriteTest::testAStoredRowTheCallerCannotSeeMustPassCreateAndUpdate` and `testAStoredRowTheCallerMayNotUpdateIsNotWritten`}

### Requirement: A seed object is inserted once

A seed object SHALL be inserted once: through the routing mapper when one is
set, else through the object entity mapper.

#### Scenario: a configuration with seed data and a routing mapper

- **WHEN** a seed object is imported while a routing mapper is set
- **THEN** the routing mapper inserts it once and the object entity mapper does not
- @e2e exclude {covered by `ImportHandlerSeedMetadataKeysTest::testARoutingMapperWritesTheSeedOnce`}
