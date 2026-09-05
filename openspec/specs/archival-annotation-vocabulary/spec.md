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

### Requirement: Purging an archival record is refused on the same terms as deleting one

`DELETE /api/deleted/{uuid}` and `DELETE /api/deleted` permanently destroy rows rather than tombstoning them, so they SHALL apply the archival gate that `ObjectService::deleteObject()` applies. When the object's schema declares `x-openregister-archival`, the single-object route SHALL respond `403` with body `{ error: "SCHEMA_ARCHIVAL_IMMUTABLE", message, schema, operation: "purge", hint }`, and the bulk route SHALL count the object as `failed` rather than destroying it. Both routes SHALL fail closed: an object whose schema cannot be resolved SHALL be refused, because an unreadable annotation is indistinguishable from a retained record.

The gate SHALL read `Schema::hasArchivalAnnotation()`, which is the single definition of the rule for both delete routes.

#### Scenario: Purging a live archival record is refused
- **GIVEN** the `dossiq/case` schema declares `x-openregister-archival`
- **AND** an admin authenticates and a live `case` row exists with UUID `<uuid>`
- **WHEN** `DELETE /api/deleted/<uuid>` is called
- **THEN** the response SHALL be HTTP 403 with `error: "SCHEMA_ARCHIVAL_IMMUTABLE"`
- **AND** the underlying row SHALL still exist in the magic table

#### Scenario: Purging a soft-deleted archival record is refused
- **GIVEN** a soft-deleted `case` row on the same archival schema
- **WHEN** `DELETE /api/deleted/<uuid>` is called
- **THEN** the response SHALL be HTTP 403 with `operation: "purge"`
- **AND** the row SHALL still exist

#### Scenario: Emptying the trash still works for an ordinary record
- **GIVEN** a soft-deleted row on a schema that does NOT declare `x-openregister-archival`
- **WHEN** `DELETE /api/deleted/<uuid>` is called
- **THEN** the response SHALL be HTTP 200 and the row SHALL be destroyed

### Requirement: A live object cannot be purged through the trash endpoints

`DELETE /api/deleted/{uuid}` empties the TRASH. It SHALL refuse any object that is not soft-deleted, with HTTP 400 and `{ error: "Object is not deleted" }`. The soft-delete test SHALL be `ObjectEntity::isSoftDeleted()`; comparing `getDeleted()` with `null` SHALL NOT be used, because `ObjectEntity::$deleted` defaults to `[]` and the row hydrator skips NULL columns, so a live object answers `[]` and such a comparison never refuses anything.

#### Scenario: Purging a live object is refused
- **GIVEN** a live row on any schema
- **WHEN** `DELETE /api/deleted/<uuid>` is called
- **THEN** the response SHALL be HTTP 400 with `error: "Object is not deleted"`
- **AND** the row SHALL still exist

#### Scenario: Bulk purge skips live rows
- **GIVEN** a request naming one live row and one soft-deleted non-archival row
- **WHEN** `DELETE /api/deleted` is called
- **THEN** exactly one row SHALL be destroyed and the live row SHALL be counted as `failed`

### Requirement: Schema-wide object deletion is refused on an archival schema

`SchemaDeletionService` is the single implementation of "delete every object of a schema", reached by `POST /api/bulk/{register}/{schema}/delete-objects`, its legacy twin `/delete-schema`, `DELETE /api/schemas/{id}?deleteObjects=true` and `occ openregister:schemas:prune-retired`. It SHALL apply the archival gate that `ObjectService::deleteObject()` applies, reading `Schema::hasArchivalAnnotation()` rather than the annotation itself.

`deleteObjectsBySchema()` SHALL refuse an archival schema unconditionally and SHALL expose no override parameter: both of its callers are HTTP routes, and an override they could pass through would put archival destruction back on the network. `cascadeDeleteSchema()` SHALL refuse unless the caller passes an explicit `archivalOverride`, which only `occ openregister:schemas:prune-retired --force-archival` passes — the same bargain `occ openregister:objects:purge --force` strikes, on the same reasoning that shell access is an authorization boundary an HTTP caller cannot cross.

`--force-archival` SHALL be a distinct flag from `--force`. `--force` means "this schema still owns objects"; an operator pruning a retired test schema passes it without having said anything about retained records, so it MUST NOT authorise their destruction.

The refusal SHALL surface as HTTP 403 with the `{ error: "SCHEMA_ARCHIVAL_IMMUTABLE", message, schema, operation, hint }` body, on both the bulk routes and the schema cascade — not as a 500, which would read as an endpoint bug rather than as the platform protecting a retained record.

The audit trail SHALL NOT be treated as a substitute for the record. `SchemaDeletionService` writes a hash-chained audit entry per object before removing it, but an entry recording that a record was destroyed does not discharge an obligation to retain it.

#### Scenario: Bulk delete-objects on an archival schema destroys nothing
- **GIVEN** the `dossiq/retained-case` schema declares `x-openregister-archival` and its magic table holds 3 rows
- **WHEN** `POST /api/bulk/{register}/retained-case/delete-objects` is called with `{"hardDelete": true}`
- **THEN** the response SHALL be HTTP 403 with `error: "SCHEMA_ARCHIVAL_IMMUTABLE"` and `operation: "delete"`
- **AND** all 3 rows SHALL still exist in the magic table
- **AND** no `schema.cascade_delete` audit entry SHALL be written

#### Scenario: A soft schema-wide delete is refused too
- **WHEN** the same call is made with `{"hardDelete": false}`
- **THEN** the response SHALL be HTTP 403 and no row SHALL be tombstoned

#### Scenario: The HTTP schema cascade on an archival schema is refused
- **WHEN** `DELETE /api/schemas/{id}?deleteObjects=true` names the archival schema
- **THEN** the response SHALL be HTTP 403 with `error: "SCHEMA_ARCHIVAL_IMMUTABLE"`
- **AND** no transaction SHALL be opened, no row removed, no magic table dropped, and the schema entity SHALL still exist

#### Scenario: The prune CLI refuses without the archival flag
- **WHEN** `occ openregister:schemas:prune-retired --app dossiq --slug retained-case --apply --force` names the archival schema
- **THEN** the command SHALL skip it, SHALL name `x-openregister-archival` and `--force-archival`, and SHALL destroy nothing

#### Scenario: The prune CLI proceeds with the archival flag and says so
- **WHEN** the same command is re-run with `--force-archival`
- **THEN** the cascade SHALL run and the output SHALL mark the schema as holding ARCHIVAL RECORDS

#### Scenario: An ordinary schema is unaffected
- **GIVEN** a schema that does NOT declare `x-openregister-archival`
- **WHEN** any of these paths deletes its objects
- **THEN** the deletion SHALL succeed exactly as before, reporting the count and the UUIDs

### Requirement: A failed bulk upsert never resolves itself by dropping the table

`MagicMapper::bulkUpsert()` recovers from a missing magic table by creating it and retrying. The recovery SHALL create only a table that is actually absent, verified against the live connection. It MUST NOT reach `ensureTableForRegisterSchema(force: true)` while the table exists, because that drops the table before recreating it and destroys every row with no audit entry.

The message test that selects this recovery is wider than a missing table: PostgreSQL phrases a missing COLUMN (42703) as `column "x" of relation "y" does not exist`, which matches the same substring as a missing relation (42P01). A failure that is not a missing table SHALL be re-thrown.

#### Scenario: An error naming an existing table is surfaced, not resolved destructively
- **GIVEN** a populated magic table for a register/schema pair
- **WHEN** `bulkUpsert()` fails with an error whose message contains `does not exist` while that table exists
- **THEN** the original exception SHALL propagate
- **AND** the table SHALL NOT be dropped and its rows SHALL remain

### Requirement: An administrative CLI purge is the only path that can destroy an archival record

The platform SHALL ship `occ openregister:objects:purge <uuid>...`. It SHALL be dry-run by default and write only with `--apply`. Without `--force` it SHALL refuse an archival record and SHALL refuse a live (not soft-deleted) object. With `--force` it SHALL destroy either, and SHALL name the record as archival in its output.

This is deliberately a CLI surface: `occ` requires shell access to the server, which is an authorization boundary an HTTP caller cannot cross, and it is the sanctioned path for removing a record created in error or a test fixture on an archival schema.

#### Scenario: Archival record refused without force
- **WHEN** `occ openregister:objects:purge <uuid> --apply` names a row on an archival schema
- **THEN** the command SHALL exit non-zero, SHALL name `x-openregister-archival`, and SHALL destroy nothing

#### Scenario: Force purges an archival record
- **WHEN** `occ openregister:objects:purge <uuid> --apply --force` names the same row
- **THEN** the command SHALL exit zero, SHALL report the row as an ARCHIVAL RECORD, and the row SHALL be destroyed

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

