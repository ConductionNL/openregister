# object-lifecycle

## ADDED Requirements

### Requirement: A create that finds its identifier taken runs the update guard

When a single create finds its identifier already taken by the time it
writes, and the caller did not ask to fail in that case, the system SHALL
dispatch `ObjectUpdatingEvent` with the stored object as the old object
before it updates the row. A stopped event SHALL throw `HookStoppedException`,
and the row SHALL stay as stored. A caller that asked to fail SHALL get
`ObjectExistsException`, and no update event SHALL run.

#### Scenario: a concurrent create would move a stored object past its guard

- **GIVEN** a stored object in status `open` and a listener that stops an update into `in-deliberation`
- **WHEN** a create with the same identifier and status `in-deliberation` reaches the table
- **THEN** the create throws `HookStoppedException` and the stored row is not written again
- @e2e exclude {needs a concurrent writer; covered by `MagicMapperInsertRaceGuardTest::testALostInsertRunsTheUpdateGuard`}

#### Scenario: a create the guard allows becomes an update

- **GIVEN** the same stored object
- **WHEN** a create with the same identifier and status `closed` reaches the table
- **THEN** `ObjectUpdatingEvent` carries the stored `open` object as the old one, the row is updated, and the returned object has the stored id
- @e2e exclude {needs a concurrent writer; covered by `MagicMapperInsertRaceGuardTest::testALostInsertTheGuardAllowsIsWrittenAsAnUpdate`}

#### Scenario: a claim still refuses

- **WHEN** a create that asked to fail finds its identifier taken
- **THEN** it throws `ObjectExistsException` and only `ObjectCreatingEvent` was dispatched
- @e2e exclude {covered by `MagicMapperInsertRaceGuardTest::testAnInsertThatMustNotUpdateStillRefuses`}
