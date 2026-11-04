# object-lifecycle

## ADDED Requirements

### Requirement: A refused create over a taken identifier is judged as an update

When a creating hook refuses a create, the caller did not ask to fail on a
taken identifier, and the identifier names a live stored row, the system
SHALL write it as an update of that row through `ObjectUpdatingEvent`, so
the update guards decide. When the identifier is free, names a soft-deleted
row, or the caller asked to fail, the system SHALL throw the creating refusal
and write nothing. Data a refusing hook set SHALL NOT be written.

#### Scenario: a client re-posts an existing object in a later state

- **GIVEN** a stored object in `open`, and a creating hook that refuses any state but the initial one
- **WHEN** a create with the same identifier and status `archived` is written
- **THEN** it is written as an update: the events are `ObjectCreatingEvent`, `ObjectUpdatingEvent` and `ObjectUpdatedEvent`, with the stored `open` object as the old one
- @e2e exclude {needs a concurrent or repeated writer; covered by `MagicMapperInsertRaceGuardTest::testARefusedCreateOverATakenIdentifierIsJudgedAsAnUpdate`}

#### Scenario: a refused create of a free identifier

- **WHEN** the same create targets an identifier that is not stored
- **THEN** it is refused with the creating hook's message and nothing is written
- @e2e exclude {covered by `MagicMapperInsertRaceGuardTest::testARefusedCreateOfAFreeIdentifierStaysRefused` and `testARefusedClaimStaysRefused`}

#### Scenario: a refused create over a soft-deleted object

- **GIVEN** a soft-deleted object and a creating hook that refuses the create
- **WHEN** a create with the same identifier is written
- **THEN** it is refused with the creating hook's message, the object stays deleted, and nothing is written
- @e2e exclude {covered by `MagicMapperInsertRaceGuardTest::testARefusedCreateOverASoftDeletedRowStaysRefused`}

#### Scenario: the row is deleted between the check and the update

- **GIVEN** a live stored object when a refused create checks it, which is soft-deleted before the update is written
- **WHEN** the update path reads the row again
- **THEN** the creating refusal is thrown and nothing is written
- @e2e exclude {needs a concurrent delete; covered by `MagicMapperInsertRaceGuardTest::testARefusedCreateIsNotWrittenWhenItsRowIsDeletedBeforeTheUpdate`}

#### Scenario: a listener deletes the row while the update runs

- **GIVEN** a refused create carried out as an update of a live row, and an `ObjectUpdatingEvent` listener that soft-deletes or hard-deletes that row
- **WHEN** the update is written
- **THEN** the write changes only a row whose `_deleted` is empty and never inserts, so nothing is written and the creating refusal is thrown; a lost claim without a refusal is written as before
- @e2e exclude {covered by `MagicMapperInsertRaceGuardTest::testARefusedCreateIsNotWrittenWhenAnUpdatingListenerSoftDeletesItsRow`, `...HardDeletesItsRow`, `testALostClaimIsWrittenEvenWhenAnUpdatingListenerDeletesItsRow` and `MagicMapperLiveRowWriteTest`}

#### Scenario: a write of the same object runs inside the update's listener

- **GIVEN** a refused create carried out as an update of a live row, and an `ObjectUpdatingEvent` listener that writes the same object again
- **WHEN** the nested write finishes and the row is then soft-deleted before the outer update is written
- **THEN** the outer update still changes only a live row, so nothing is written and the creating refusal is thrown; the nested write is conditional only when it is itself a refused create
- @e2e exclude {covered by `MagicMapperRefusedCreateComposedTest::testNestedRefusedCreateSameUuidThenSoftDelete` and `testNestedPlainWriteSameUuidIsUnconditional`}

