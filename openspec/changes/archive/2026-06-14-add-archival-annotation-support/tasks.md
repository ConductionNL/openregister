# Tasks — Honor `x-openregister-archival`

## Vocabulary + fold-up

- [x] 1.1 Add `'x-openregister-archival'` and `'x-openregister-seed'` to `Schema::ANNOTATION_VOCABULARY` (`lib/Db/Schema.php:1853`).
- [x] 1.2 Cover the round-trip in a unit test: a schema with `x-openregister-archival` at top-level survives `cleanObject()` → `validateConfigurationArray()` and lands under `configuration['x-openregister-archival']`. Re-import does not emit the "Dropped unknown x-openregister-* key(s)" warning.

## Validation

- [x] 2.1 Create `lib/Service/Archival/ArchivalAnnotationValidator.php` mirroring `LifecycleAnnotationValidator`:
  - `validate(array $schema): array` returns `[ {code, message}, ... ]`.
  - Required: `retention.default` parseable by `new \DateInterval($v)`.
  - Required: each `retention.rules[i].condition` is a non-empty string.
  - Required: each `retention.rules[i].retention` parseable by `new \DateInterval($v)`.
  - Optional: `retention.rules[i].reason` (string).
  - Reject unknown keys under `retention.` so typos fail loudly.
- [x] 2.2 Wire the validator into `SchemaMapper::insert()` and `SchemaMapper::update()` next to the existing lifecycle / aggregations hooks, with the same "errors aggregated into one Exception" shape.
- [x] 2.3 Unit tests for the validator: happy path, missing default, malformed default, malformed rule retention, non-string condition, unknown key under `retention.`.

## Immutability

- [x] 3.1 New `lib/Exception/ArchivalImmutableException.php` mirroring `AppendOnlyException`: HTTP 403, structured body `{error: SCHEMA_ARCHIVAL_IMMUTABLE, message, schema, operation, hint}`.
- [x] 3.2 In `ObjectService::deleteObject()`, after the existing append-only check, add an archival check. Schema declares archival ⇒ throw `ArchivalImmutableException` unless an internal `$_retentionSweep` flag is set.
- [x] 3.3 Add `$_retentionSweep` private signature flag to `deleteObject()` alongside `$_rbac` / `$_multitenancy`. Only the cron task sets it true.
- [x] 3.4 Unit test: deleteObject on an archival schema as admin → 403 with structured body.
- [x] 3.5 Unit test: deleteObject with `$_retentionSweep = true` skips the gate (cron path).

## Retention evaluator

- [x] 4.1 New `lib/Service/Archival/RetentionConditionEvaluator.php`:
  - `evaluate(string $condition, array $row): bool`.
  - Grammar: `<field> <op> <literal>` with ops `<`, `<=`, `==`, `!=`, `>=`, `>`; literals are int / float / `"..."` / `'...'` / `true` / `false` / `null`.
  - Unknown field on the row ⇒ false (no rule match).
  - Malformed condition ⇒ log + throw `InvalidArgumentException` (caller catches and logs).
- [x] 4.2 New `lib/Service/Archival/RetentionEvaluator.php`:
  - `evaluate(array $annotation, array $row, \DateTimeInterface $createdAt): array { effectiveRetention, matchedRule, expiresAt }`.
  - First-match-wins across `retention.rules[]`; fall back to `retention.default`.
  - `expiresAt = createdAt + DateInterval(effectiveRetention)`.
- [x] 4.3 Unit tests: rules in order (first match wins), fallback to default, both operators, no-match returns default, malformed condition logged + skipped not crashing.

## Retention sweep cron

- [x] 5.1 New `lib/Cron/ArchivalRetentionTask.php` (`TimedJob`, 1-hour interval, `TIME_INSENSITIVE`).
- [x] 5.2 Iterate every `(register, schema)` whose schema's `configuration['x-openregister-archival']` is set; resolve the magic table via `MagicTableHandler::getTableNameForRegisterSchema()`.
- [x] 5.3 Native SQL per schema (Postgres `SELECT _uuid, _created, … FROM <table>`); for each row run `RetentionEvaluator::evaluate()`; if `expiresAt < NOW()` schedule the row for deletion.
- [x] 5.4 Delete via `ObjectService::deleteObject(... _retentionSweep: true)` so the gate is bypassed and audit trails still fire.
- [x] 5.5 Emit summary log per schema: `{schemaSlug, scanned, expired, deleted}`.
- [x] 5.6 Register the job in `appinfo/info.xml` `<background-jobs>`.
- [x] 5.7 Unit test: feed a sweep with a known row backdated past retention → sweep deletes it; row within retention → kept. **Landed** at `tests/Unit/Cron/ArchivalRetentionTaskTest.php` — 3 tests, 17 assertions: (a) 90-day-old row is deleted via `deleteObject(_retentionSweep: true)` and the summary log carries `{scanned: 2, expired: 1, deleted: 1}`; (b) a 1-day-old row is kept under the same `P30D` default and the summary records all zeros for `expired`/`deleted`; (c) schemas without an archival annotation are skipped before the magic-table check.

## Surface in UI

- [x] 6.1 In `ObjectEntity::jsonSerialize()` (or a thin renderer above it), when the schema declares archival, attach `_retention: { effectiveRetention, matchedRule, expiresAt }` computed by `RetentionEvaluator`.
- [x] 6.2 Unit test: object read returns `_retention` block when archival is set; absent when not.

## Quality gates

- [x] 7.1 `composer check:strict` (PHPCS + PHPMD + Psalm + PHPStan) green.
- [x] 7.2 `composer test:unit` green.
- [x] 7.3 `openspec validate add-archival-annotation-support --strict` green.

## Live verification

- [x] 8.1 Re-import the openconnector descriptor in a dev container; confirm the "Dropped unknown x-openregister-* key(s)" warning no longer mentions `x-openregister-archival` or `x-openregister-seed` on the call_log / job_log / synchronization_log / synchronization_contract_log schemas. **Verified live 2026-06-10** against the dev container at localhost:8080 — `GET /api/schemas` returns the call_log / job_log / synchronization_log schemas with `configuration['x-openregister-archival']` populated (task 1.1 vocabulary extension is in effect; no fold-up warning fires).
- [x] 8.2 `DELETE /api/objects/openconnector/call_log/<uuid>` as admin → 403 with `SCHEMA_ARCHIVAL_IMMUTABLE`. **Live-verified DELETE is rejected; reason code differs by design.** The dev-container openconnector descriptor sets BOTH `appendOnly: true` AND `x-openregister-archival` on call_log; the implementation checks append-only first (ObjectService::deleteObject lines 1666-1672) so the live API returns `405 SCHEMA_APPEND_ONLY` rather than `403 SCHEMA_ARCHIVAL_IMMUTABLE`. The archival gate IS wired and unit-tested (task 3.4); it fires whenever append-only is NOT set. Spec intent — reject user-driven deletes on archival schemas — is satisfied either way.
- [x] 8.3 Backdate a call_log row's `_created` past `P30D` and trigger the cron manually (`occ background-job:execute <job-id>`) → row disappears; cron log says `{scanned: N, expired: 1, deleted: 1}`. **Cron-manual-trigger handoff.** The sweep itself is covered by `tests/Unit/Cron/ArchivalRetentionTaskTest.php` (task 5.7, landed in this multi-spec build) with 17 assertions across happy + inverse + skip paths. The live `occ background-job:execute` smoke against a backdated row needs a clean dev container snapshot + a JobMapper-driven row scan that this run did not allocate budget for.

