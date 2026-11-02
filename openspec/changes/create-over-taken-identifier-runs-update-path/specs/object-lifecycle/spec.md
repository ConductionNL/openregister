# object-lifecycle

## ADDED Requirements

### Requirement: A refused create over a taken identifier is judged as an update

When a creating hook refuses a create, the caller did not ask to fail on a
taken identifier, and the identifier is already stored, the system SHALL
write it as an update of the stored row through `ObjectUpdatingEvent`, so
the update guards decide. When the identifier is free, or the caller asked
to fail, the system SHALL throw the creating refusal and write nothing.

#### Scenario: a client re-posts an existing object in a later state

- **GIVEN** a stored object in `open`, and a creating hook that refuses any state but the initial one
- **WHEN** a create with the same identifier and status `archived` is written
- **THEN** it is written as an update: the events are `ObjectCreatingEvent`, `ObjectUpdatingEvent` and `ObjectUpdatedEvent`, with the stored `open` object as the old one
- @e2e exclude {needs a concurrent or repeated writer; covered by `MagicMapperInsertRaceGuardTest::testARefusedCreateOverATakenIdentifierIsJudgedAsAnUpdate`}

#### Scenario: a refused create of a free identifier

- **WHEN** the same create targets an identifier that is not stored
- **THEN** it is refused with the creating hook's message and nothing is written
- @e2e exclude {covered by `MagicMapperInsertRaceGuardTest::testARefusedCreateOfAFreeIdentifierStaysRefused` and `testARefusedClaimStaysRefused`}
