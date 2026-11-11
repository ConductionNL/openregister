# notificatie-engine

## ADDED Requirements

### Requirement: Every dispatch of a declared notification leaves a readable record

A notification declaration MAY carry a `key`. Each evaluation of a declared notification for one object event SHALL write one automation record with kind `notification`, the key, the notification slug, the schema, the object uuid, the event id, the time, counts per channel and an outcome `sent`, `partial`, `failed` or `skipped`, with the reason when skipped. The record SHALL NOT name recipients. `GET /api/automation-records` SHALL return these records filtered by key prefix, schema, object and time range, only for objects the caller may read.

#### Scenario: an app's automation shows its sends

- **GIVEN** a schema with a notification declared with `key` `aut-7f3a`
- **WHEN** an object event triggers it and two of three recipients are reached by mail
- **THEN** `GET /api/automation-records?kind=notification&key=aut-` returns one record for that object with outcome `partial` and mail counts 2 sent, 1 failed
- @e2e exclude {backend; task 3.1 adds a Newman case}

#### Scenario: a duplicate is recorded as skipped

- **GIVEN** the same notification with an idempotency key
- **WHEN** the same event fires twice within the dedup window
- **THEN** the second record has outcome `skipped` with reason `duplicate`
- @e2e exclude {backend; covered by a unit test on the dispatcher}

#### Scenario: a reader sees only what they may read

- **GIVEN** records for objects of a schema the caller may not read
- **WHEN** the caller reads `GET /api/automation-records?key=aut-`
- **THEN** those records are not returned
- @e2e exclude {authorisation; covered by a controller test with a real RBAC fixture}
