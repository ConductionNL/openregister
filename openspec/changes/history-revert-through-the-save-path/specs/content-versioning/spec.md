# content-versioning

## ADDED Requirements

### Requirement: A revert is saved like an edit and audited as a revert

`POST /api/objects/{register}/{schema}/{id}/revert` SHALL write the restored
state through the object save path as an update: validated against the
schema as it is now, passing the object update listeners and the lock check,
and recorded in the audit trail with action `revert` and the version restored
to. A restored state that the current schema refuses SHALL NOT be written, and
the answer SHALL be 422 with the validation errors.

#### Scenario: a record editor restores an earlier version

- **GIVEN** a record editor with `update` on record `contract-7`, which has versions 1.0.1, 1.0.2 and 1.0.3
- **WHEN** the editor calls `POST /api/objects/contracten/contract/{id}/revert` with `{"version": "1.0.1"}`
- **THEN** the record holds the values of 1.0.1 as a new version
- **AND** the audit trail has an entry with action `revert` and `revertedToVersion` 1.0.1
- @e2e exclude {specified only; task 3.1 adds tests/e2e/ci/revert-through-save.spec.ts}

#### Scenario: a version the schema no longer accepts is not restored

- **GIVEN** schema `contract` made `einddatum` required after version 1.0.1, which has no `einddatum`
- **WHEN** the editor reverts to 1.0.1
- **THEN** the answer is 422 naming `einddatum`, and the record is unchanged
- @e2e exclude {specified only; covered by RevertHandlerTest in task 1.3}

### Requirement: A refused revert answers a fixed sentence

The revert route SHALL answer 403, 404, 423 and 500 with fixed sentences and
SHALL NOT include exception text in any response body. A 500 SHALL carry a
request id that is also in the log entry holding the exception.

#### Scenario: a locked record

- **GIVEN** record `contract-7` locked by another user
- **WHEN** the editor calls the revert route
- **THEN** the answer is 423 with "This record is locked by someone else." and nothing about who or why beyond that sentence
- @e2e exclude {API contract; covered by RevertControllerTest in task 2.1}
