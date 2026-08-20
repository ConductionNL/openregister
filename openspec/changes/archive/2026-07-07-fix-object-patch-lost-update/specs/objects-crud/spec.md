## ADDED Requirements

### Requirement: Partial object updates are protected against lost updates

A partial update (PATCH) of an object SHALL apply optimistic concurrency
control. The object's version captured at read time SHALL be asserted at write
time; if the persisted object changed since it was read, the update SHALL be
rejected with HTTP 409 Conflict rather than overwriting the concurrent change.

#### Scenario: Concurrent PATCHes do not lose data

- **WHEN** two clients read the same object version and each PATCHes a different
  field
- **AND** the first PATCH commits successfully
- **THEN** the second PATCH is rejected with HTTP 409
- **AND** the first client's field is not overwritten or lost

#### Scenario: Conditional update via If-Match

- **WHEN** a client sends a PATCH with an `If-Match` value that no longer matches
  the object's current version
- **THEN** the request is rejected with HTTP 409 and the current version is
  reported

#### Scenario: Non-conflicting PATCH succeeds

- **WHEN** a client PATCHes an object whose version has not changed since it was
  read
- **THEN** the update is applied and a new version/etag is returned
