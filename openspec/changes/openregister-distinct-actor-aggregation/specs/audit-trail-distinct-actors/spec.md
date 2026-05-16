## ADDED Requirements

### Requirement: REQ-ORDA-001 `getDistinctActorCount` method exists on `AuditTrailMapper`

`OCA\OpenRegister\Db\AuditTrailMapper` SHALL declare a public method with exact signature:

```php
public function getDistinctActorCount(array $schemaIds, int $hours): int
```

The method SHALL return the count of distinct non-NULL `user_id` values from rows in the `audit_trail` table where `schema_id` is in `$schemaIds` AND `created` is on or after `(now() - $hours hours)`.

#### Scenario: Method is callable from a typed in-process caller

- **WHEN** a calling app obtains the `AuditTrailMapper` instance from the DI container and calls `getDistinctActorCount([1, 2, 3], 24)`
- **THEN** the method returns an `int` representing the distinct actor count over the last 24 hours across schemas 1, 2, and 3

#### Scenario: Method is part of the public API surface

- **WHEN** PHPStan analyses the openregister codebase at strict mode
- **THEN** the method is recognised as a public member of `AuditTrailMapper` with the declared types

### Requirement: REQ-ORDA-002 Empty `$schemaIds` returns 0 without a DB call

The method SHALL short-circuit and return `0` when `$schemaIds` is an empty array. No SQL query SHALL be issued against the database.

#### Scenario: Empty schema list short-circuits

- **WHEN** a caller invokes `getDistinctActorCount([], 24)`
- **THEN** the method returns `0`
- **AND** the DBAL query counter for the test does not increment (no SQL executed)

### Requirement: REQ-ORDA-003 Non-positive `$hours` raises `\InvalidArgumentException`

The method SHALL reject `$hours` values less than or equal to zero by throwing `\InvalidArgumentException` with a message identifying the invalid parameter.

#### Scenario: Zero hours is rejected

- **WHEN** a caller invokes `getDistinctActorCount([1], 0)`
- **THEN** the method throws `\InvalidArgumentException`
- **AND** the exception message contains the string `"hours"` and the rejected value

#### Scenario: Negative hours is rejected

- **WHEN** a caller invokes `getDistinctActorCount([1], -5)`
- **THEN** the method throws `\InvalidArgumentException`

### Requirement: REQ-ORDA-004 NULL `user_id` rows are excluded from the count

The method SHALL exclude rows where `user_id IS NULL` (e.g. system-initiated audit events from CLI repair steps and background jobs). The count represents distinct human actors only.

#### Scenario: System-initiated rows do not contribute to the count

- **GIVEN** the audit_trail table contains 3 rows for schema 1 in the last 24h: one with `user_id = "alice"`, one with `user_id = "bob"`, one with `user_id = NULL`
- **WHEN** a caller invokes `getDistinctActorCount([1], 24)`
- **THEN** the method returns `2`

### Requirement: REQ-ORDA-005 Multi-schema schema-set fan-out

The method SHALL count distinct actors across the entire schema set in a single SQL statement, not by summing per-schema counts (which would double-count an actor active in multiple schemas).

#### Scenario: Actor counted once across multiple schemas

- **GIVEN** the audit_trail table contains rows for schemas `[1, 2, 3]` in the last 24h, all by `user_id = "alice"` (one row per schema)
- **WHEN** a caller invokes `getDistinctActorCount([1, 2, 3], 24)`
- **THEN** the method returns `1` (not `3`)

#### Scenario: Distinct actors aggregate across schemas

- **GIVEN** the audit_trail table contains: 2 rows for schema 1 by `user_id = "alice"`, 1 row for schema 2 by `user_id = "bob"`, 3 rows for schema 3 by `user_id = "carol"`
- **WHEN** a caller invokes `getDistinctActorCount([1, 2, 3], 24)`
- **THEN** the method returns `3`

### Requirement: REQ-ORDA-006 Time window is exclusive of older rows

The method SHALL exclude rows whose `created` timestamp is older than `$hours` hours from the time the method is invoked. Rows exactly on the boundary (created at `now() - $hours hours`) SHALL be included (`>=`).

#### Scenario: Older rows are excluded

- **GIVEN** the audit_trail table contains a row for schema 1 by `user_id = "alice"` created 48 hours ago, and a row for schema 1 by `user_id = "bob"` created 12 hours ago
- **WHEN** a caller invokes `getDistinctActorCount([1], 24)`
- **THEN** the method returns `1` (bob; alice's row is outside the window)

#### Scenario: Rows on the window boundary are included

- **GIVEN** the audit_trail table contains a single row for schema 1 by `user_id = "alice"` created exactly 24 hours ago (to the second)
- **WHEN** a caller invokes `getDistinctActorCount([1], 24)`
- **THEN** the method returns `1`

### Requirement: REQ-ORDA-007 Method is documented in PHPDoc with caller guidance

The method SHALL declare a PHPDoc block with:
- A one-line summary of intent
- `@param array<int> $schemaIds` with a note about the empty-array short-circuit
- `@param int $hours` with the non-positive rejection note
- `@return int` with the "distinct, non-NULL user_id" semantics
- `@throws \InvalidArgumentException` when `$hours <= 0`
- An explicit note that NULL-user rows are excluded
- An explicit note pointing future readers to the index recommendation in this spec's design.md

#### Scenario: PHPDoc satisfies the hydra-gate-spdx + PHPCS contract

- **WHEN** the file is checked with `composer check:strict`
- **THEN** PHPCS passes (PHPDoc complete, types match), Psalm passes (signature types resolvable), PHPStan passes (no untyped array)
