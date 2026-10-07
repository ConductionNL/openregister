# incremental-sync

## ADDED Requirements

### Requirement: A soft delete and a restore move the object's updated time (REQ-SYNC-001)

A soft delete SHALL set the object's `updated` metadata to the time of the
deletion. A restore SHALL set `updated` to the time of the restore. A
changed-since read of the object list with `_includeDeleted=true` SHALL
therefore return every object deleted or restored after the given time.

#### Scenario: a deletion after the last sync shows up in a changed-since read

- **GIVEN** an object last changed on 1 October
- **AND** a consumer that last synced on 5 October
- **WHEN** the object is soft deleted on 6 October
- **AND** the consumer reads the object list with `@self[updated][gte]` set to 5 October and `_includeDeleted=true`
- **THEN** the object is in the result
- **AND** its `@self.deleted.deletedAt` is 6 October
- @e2e exclude {API-only behaviour, covered by integration tests and the Newman collection}

#### Scenario: a restore after the last sync shows up as a live object

- **GIVEN** an object soft deleted on 2 October
- **WHEN** it is restored on 6 October
- **AND** a consumer reads the object list with `@self[updated][gte]` set to 5 October
- **THEN** the object is in the result with `@self.deleted` null
- @e2e exclude {API-only behaviour, covered by integration tests and the Newman collection}

### Requirement: The deleted list filters by register, schema and deletion time (REQ-SYNC-002)

`GET /api/deleted` SHALL accept `register`, `schema` and `deletedSince`.
The filters SHALL apply before pagination and to the reported `total`.
An unknown register or schema SHALL be refused with HTTP 404. A
`deletedSince` that is not a valid ISO 8601 date-time SHALL be refused
with HTTP 400 naming the parameter. A filter SHALL never be silently
ignored.

#### Scenario: a consumer reads one schema's deletions since its last run

- **GIVEN** three objects of schema `lesson` deleted on 4, 6 and 7 October
- **AND** one object of schema `room` deleted on 6 October
- **WHEN** the consumer requests `GET /api/deleted?schema=lesson&deletedSince=2026-10-05T00:00:00+00:00`
- **THEN** the response lists the two `lesson` objects deleted on 6 and 7 October
- **AND** `total` is 2
- @e2e exclude {API-only behaviour, covered by integration tests and the Newman collection}

#### Scenario: a malformed date is refused

- **WHEN** a consumer requests `GET /api/deleted?deletedSince=yesterday`
- **THEN** the response is HTTP 400
- **AND** the error names `deletedSince`
- @e2e exclude {validation behaviour, covered by unit tests}

#### Scenario: an unknown schema is refused

- **WHEN** a consumer requests `GET /api/deleted?schema=does-not-exist`
- **THEN** the response is HTTP 404
- @e2e exclude {validation behaviour, covered by unit tests}

### Requirement: Destroyed objects are reported as tombstones (REQ-SYNC-003)

`GET /api/deleted` with `deletedSince` and `_includeDestroyed=true` SHALL
also list every object whose destruction was recorded in the audit trail
at or after `deletedSince`, within the register and schema filters. Each
tombstone SHALL carry the object id, register, schema, destruction time
and `tombstone: true`, and SHALL carry no object data. Tombstones SHALL
be limited to destruction records the caller may read. The response
SHALL state `destroyedCoverageFrom`, the oldest destruction entry still
held. `_includeDestroyed=true` without `deletedSince` SHALL be refused
with HTTP 400.

#### Scenario: an object destroyed since the last run is reported

- **GIVEN** an object soft deleted on 1 October with a 3-day bin window
- **AND** destroyed on 4 October
- **WHEN** a consumer that last synced on 2 October requests `GET /api/deleted?deletedSince=2026-10-02T00:00:00+00:00&_includeDestroyed=true`
- **THEN** the response contains a tombstone for that object id with `destroyedAt` 4 October
- **AND** the tombstone holds no property values of the object
- @e2e exclude {API-only behaviour, covered by integration tests and the Newman collection}

#### Scenario: a tombstone outside the caller's read scope is withheld

- **GIVEN** a destroyed object in an organisation the caller cannot read
- **WHEN** the caller requests destroyed tombstones since before the destruction
- **THEN** no tombstone for that object is returned
- @e2e exclude {authorisation behaviour, covered by integration tests}

#### Scenario: an unbounded destroyed scan is refused

- **WHEN** a consumer requests `GET /api/deleted?_includeDestroyed=true` without `deletedSince`
- **THEN** the response is HTTP 400 naming `deletedSince`
- @e2e exclude {validation behaviour, covered by unit tests}

### Requirement: List responses carry a server-side sync watermark (REQ-SYNC-004)

The object list and the deleted list SHALL return `syncWatermark` in the
response envelope: the server time, in ISO 8601 with offset, taken before
the query ran. A write committed while the read ran SHALL have an
`updated` at or after the watermark.

#### Scenario: the next run starts from the watermark

- **GIVEN** a consumer reads the object list and receives `syncWatermark` W
- **AND** an object is changed while that read was running
- **WHEN** the consumer next reads with `@self[updated][gte]` set to W
- **THEN** the changed object is in the result
- @e2e exclude {API-only behaviour, covered by integration tests and the Newman collection}
