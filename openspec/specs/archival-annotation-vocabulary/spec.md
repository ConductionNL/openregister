---
status: done
---

# archival-annotation-vocabulary Specification

## Purpose
Gives schemas a retention and archival vocabulary via the `x-openregister-archival` annotation, enforcing data-retention policy on object rows. Schemas declare a default retention plus condition-based rules; an hourly cron sweeps and deletes expired rows, object reads surface a computed `_retention` block, and user-driven deletes on archival schemas are blocked with HTTP 403. Validates retention declarations at save time and recognises the annotation through the import path so it round-trips without being dropped.
## Requirements
### Requirement: ANNOTATION_VOCABULARY recognises x-openregister-archival and x-openregister-seed

The `Schema::ANNOTATION_VOCABULARY` constant in `lib/Db/Schema.php` SHALL include the keys `x-openregister-archival` and `x-openregister-seed`. Schemas declaring either annotation at top-level or under `configuration` MUST round-trip through the import path without being silently dropped by `validateConfigurationArray()`, and the SchemaMapper's "Dropped unknown x-openregister-* key(s)" warning MUST NOT fire for these keys.

#### Scenario: Importing the openconnector descriptor preserves archival annotations
- **WHEN** the openconnector schema descriptor (declaring `x-openregister-archival.retention.default = "P30D"` on `call_log`) is imported via `occ app:enable openconnector`
- **THEN** `Schema::getConfiguration()['x-openregister-archival']` SHALL return the full retention block exactly as declared
- **AND** no "Dropped unknown x-openregister-* key(s)" warning SHALL be emitted for `x-openregister-archival` or `x-openregister-seed`
- **AND** subsequent `GET /api/schemas/<slug>` SHALL include the annotation in the response

#### Scenario: Re-importing an unrelated schema still drops actual typos
- **GIVEN** a schema declares `x-openregister-lifecycl` (typo, missing final 'e')
- **WHEN** the schema is imported
- **THEN** the typo SHALL still be dropped by `validateConfigurationArray()` AND surfaced via the SchemaMapper warning, exactly as before this change

### Requirement: ArchivalAnnotationValidator rejects malformed retention declarations

The platform SHALL ship an `OCA\OpenRegister\Service\Archival\ArchivalAnnotationValidator` class that validates `x-openregister-archival` at schema-save time, returning a list of structured errors. `SchemaMapper::insert()` and `SchemaMapper::update()` SHALL invoke this validator alongside the existing lifecycle and aggregations validators; any non-empty error list MUST cause the save to fail with a single aggregated `\Exception` whose message starts `x-openregister-archival: ` (mirrors the lifecycle validator's behaviour).

#### Scenario: Missing retention.default is rejected
- **GIVEN** a schema declares `x-openregister-archival = { retention: {} }`
- **WHEN** the schema is saved
- **THEN** `SchemaMapper::insert()` SHALL throw an `\Exception` whose message contains `archival-retention-default-missing`
- **AND** the schema row SHALL NOT be persisted

#### Scenario: Non-ISO-8601 retention.default is rejected
- **GIVEN** a schema declares `x-openregister-archival.retention.default = "30 days"`
- **WHEN** the schema is saved
- **THEN** `SchemaMapper::insert()` SHALL throw an `\Exception` whose message contains `archival-retention-default-malformed`

#### Scenario: Rule with non-string condition is rejected
- **GIVEN** a schema declares `x-openregister-archival.retention.rules = [{ condition: 42, retention: "P7D" }]`
- **WHEN** the schema is saved
- **THEN** `SchemaMapper::insert()` SHALL throw an `\Exception` whose message contains `archival-rule-condition-not-string`

#### Scenario: Rule with malformed retention is rejected
- **GIVEN** a schema declares `x-openregister-archival.retention.rules = [{ condition: "statusCode < 400", retention: "1h" }]`
- **WHEN** the schema is saved
- **THEN** `SchemaMapper::insert()` SHALL throw an `\Exception` whose message contains `archival-rule-retention-malformed`

#### Scenario: Unknown key under retention is rejected
- **GIVEN** a schema declares `x-openregister-archival.retention = { default: "P30D", strategy: "oldest-first" }`
- **WHEN** the schema is saved
- **THEN** `SchemaMapper::insert()` SHALL throw an `\Exception` whose message contains `archival-retention-unknown-key` and mentions `strategy`

#### Scenario: Well-formed annotation passes
- **GIVEN** a schema declares `x-openregister-archival.retention = { default: "P30D", rules: [{ condition: "statusCode < 400", retention: "PT1H", reason: "successful integrations" }] }`
- **WHEN** the schema is saved
- **THEN** the save SHALL succeed
- **AND** the annotation SHALL round-trip exactly in subsequent reads

### Requirement: DELETE on an archival schema returns 403 SCHEMA_ARCHIVAL_IMMUTABLE

When a schema declares `x-openregister-archival`, `ObjectService::deleteObject()` SHALL reject user-driven delete attempts with `OCA\OpenRegister\Exception\ArchivalImmutableException`. The exception's HTTP code SHALL be `403` and its structured body SHALL be `{ error: "SCHEMA_ARCHIVAL_IMMUTABLE", message, schema, operation: "delete", hint }`. The only exempt caller SHALL be the `ArchivalRetentionTask` cron, which passes a private `_retentionSweep: true` flag.

#### Scenario: Admin DELETE on an archival schema is rejected
- **GIVEN** the `openconnector/call_log` schema declares `x-openregister-archival`
- **AND** an admin authenticates and a `call_log` row exists with UUID `<uuid>`
- **WHEN** `DELETE /api/objects/openconnector/call_log/<uuid>` is called
- **THEN** the response SHALL be HTTP 403
- **AND** the body SHALL be `{ error: "SCHEMA_ARCHIVAL_IMMUTABLE", schema: "call_log", operation: "delete", ... }`
- **AND** the underlying row SHALL still exist in the magic table

#### Scenario: Cron deletes via the sweep flag pass through
- **GIVEN** a `call_log` row whose `_created` is older than the matched rule's retention
- **WHEN** `ArchivalRetentionTask` calls `ObjectService::deleteObject($uuid, _retentionSweep: true)`
- **THEN** the immutability gate SHALL be bypassed
- **AND** the row SHALL be deleted via the standard `DeleteObject` handler with an audit-trail entry

### Requirement: RetentionConditionEvaluator parses the minimal condition DSL

The platform SHALL ship `OCA\OpenRegister\Service\Archival\RetentionConditionEvaluator` exposing `evaluate(string $condition, array $row): bool`. The grammar SHALL be exactly `<field> <op> <literal>` with operators `<`, `<=`, `==`, `!=`, `>=`, `>` and literals: integer, float, single- or double-quoted string, `true`, `false`, `null`. A field absent from `$row` SHALL evaluate to `false` (no rule match). A malformed condition SHALL throw `\InvalidArgumentException` and SHALL NOT crash the cron — callers are responsible for catching and logging.

#### Scenario: Numeric comparison
- **WHEN** `evaluate("statusCode < 400", ["statusCode" => 200])`
- **THEN** result SHALL be `true`

- **WHEN** `evaluate("statusCode >= 400", ["statusCode" => 200])`
- **THEN** result SHALL be `false`

#### Scenario: String equality
- **WHEN** `evaluate("status == 'success'", ["status" => "success"])`
- **THEN** result SHALL be `true`

#### Scenario: Missing field
- **WHEN** `evaluate("statusCode < 400", ["foo" => "bar"])`
- **THEN** result SHALL be `false` (no exception)

#### Scenario: Malformed condition
- **WHEN** `evaluate("statusCode 400", ["statusCode" => 200])`
- **THEN** the call SHALL throw `\InvalidArgumentException`

### Requirement: RetentionEvaluator computes effectiveRetention, matchedRule, expiresAt

`OCA\OpenRegister\Service\Archival\RetentionEvaluator::evaluate(array $annotation, array $row, \DateTimeInterface $createdAt): array` SHALL return `{ effectiveRetention: string, matchedRule: int|null, expiresAt: string }`. Rules are evaluated in declared order; first match wins. If no rule matches, `effectiveRetention` falls back to `retention.default` and `matchedRule` is `null`. `expiresAt` SHALL be `createdAt + DateInterval(effectiveRetention)` formatted as ISO-8601 (`\DateTimeInterface::ATOM`).

#### Scenario: First matching rule wins
- **GIVEN** annotation `retention.rules = [{ condition: "statusCode < 400", retention: "PT1H" }, { condition: "statusCode >= 400", retention: "P30D" }]`
- **AND** row `{ statusCode: 200 }` created at 2026-01-01T00:00:00+00:00
- **WHEN** `evaluate(...)` is called
- **THEN** result SHALL be `{ effectiveRetention: "PT1H", matchedRule: 0, expiresAt: "2026-01-01T01:00:00+00:00" }`

#### Scenario: Falls back to default when no rule matches
- **GIVEN** annotation `retention = { default: "P30D", rules: [{ condition: "statusCode < 400", retention: "PT1H" }] }`
- **AND** row `{ statusCode: 500 }` created at 2026-01-01T00:00:00+00:00
- **WHEN** `evaluate(...)` is called
- **THEN** result SHALL be `{ effectiveRetention: "P30D", matchedRule: null, expiresAt: "2026-01-31T00:00:00+00:00" }`

### Requirement: ArchivalRetentionTask sweeps expired rows hourly

The platform SHALL register `OCA\OpenRegister\Cron\ArchivalRetentionTask` in `appinfo/info.xml` `<background-jobs>`. The task SHALL run on a 1-hour interval, marked `TIME_INSENSITIVE`, single-instance. Per execution it SHALL: (a) enumerate every schema whose `configuration['x-openregister-archival']` is set; (b) for each, resolve the magic table via `MagicTableHandler::getTableNameForRegisterSchema()`; (c) iterate rows with native SQL, run `RetentionEvaluator::evaluate()` per row, delete rows whose `expiresAt < now()` via `ObjectService::deleteObject(..., _retentionSweep: true)`; (d) emit one structured log entry per schema: `{schemaSlug, scanned, expired, deleted}`.

#### Scenario: Backdated row past retention is deleted
- **GIVEN** the `openconnector/call_log` schema declares `x-openregister-archival.retention.default = "P30D"`
- **AND** a row exists with `_created = now() - 35 days` and no rule that matches it
- **WHEN** `ArchivalRetentionTask::run()` executes
- **THEN** the row SHALL be deleted from the magic table
- **AND** the cron log SHALL contain `{ schemaSlug: "call_log", scanned: N, expired: 1, deleted: 1 }`

#### Scenario: Row within retention is kept
- **GIVEN** a `call_log` row with `_created = now() - 10 minutes` matched by a rule with `retention: "PT1H"`
- **WHEN** `ArchivalRetentionTask::run()` executes
- **THEN** the row SHALL still exist
- **AND** the cron log's `deleted` count SHALL NOT include this row

### Requirement: GET on an archival schema row surfaces _retention block

When a schema declares `x-openregister-archival`, `ObjectEntity::jsonSerialize()` (or the renderer above it) SHALL attach a `_retention` block to the JSON response with `{ effectiveRetention, matchedRule, expiresAt }` computed by `RetentionEvaluator` from the row's columns + the schema annotation + the row's `_created` timestamp. The block SHALL be absent when the schema does NOT declare archival.

#### Scenario: Archival row read shows _retention
- **GIVEN** a `call_log` row with `statusCode: 200` and `_created` 30 minutes ago
- **AND** the schema declares `retention.rules = [{ condition: "statusCode < 400", retention: "PT1H" }]` and `retention.default = "P30D"`
- **WHEN** `GET /api/objects/openconnector/call_log/<uuid>` returns the row
- **THEN** the JSON SHALL include `"_retention": { "effectiveRetention": "PT1H", "matchedRule": 0, "expiresAt": "<created+1h, ATOM>" }`

#### Scenario: Non-archival schema read does not show _retention
- **GIVEN** a `register/widget` schema with no `x-openregister-archival`
- **WHEN** a widget row is read
- **THEN** the JSON response SHALL NOT include a `_retention` key

