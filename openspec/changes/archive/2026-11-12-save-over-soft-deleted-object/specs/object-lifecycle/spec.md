# object-lifecycle

## ADDED Requirements

### Requirement: A save over a soft-deleted object is judged against the stored object

When a save with RBAC on names the uuid of a soft-deleted object, the system
SHALL judge it as it judges a save over any stored row the caller cannot
see: by the create rule on the incoming data and by the update rule against
the stored object.

#### Scenario: a caller saves over a soft-deleted object

- **GIVEN** a soft-deleted object owned by `agent`
- **WHEN** `mallory` saves to its uuid
- **THEN** the save is checked with action `create` and with action `update` against owner `agent` and the stored object
- @e2e exclude {covered by `ObjectServiceHiddenRowWriteTest::testASoftDeletedRowIsJudgedAgainstTheStoredObject`}
