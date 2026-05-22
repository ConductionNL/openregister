# Honor `x-openregister-archival` — Vocabulary, Validation, Immutability, Retention Sweep

## Why

Schemas declaring `x-openregister-archival` (with `retention.default` + condition-based `retention.rules[]`) get the annotation **silently stripped during schema import** because `Schema::ANNOTATION_VOCABULARY` (`lib/Db/Schema.php:1853`) does not include the key. Same for `x-openregister-seed`. The strip happens at `validateConfigurationArray()` line 1697 — every other `x-openregister-*` block round-trips, but these two do not.

Real-world impact: the openconnector descriptor declares retention rules on its 4 log schemas (`call_log` default `P30D`, `PT1H` on success, `P30D` on errors; `job_log`, `synchronization_log`, `synchronization_contract_log` similarly). On `occ app:enable openconnector` the import logs:

```
[OpenRegister.SchemaMapper] Dropped 2 unknown x-openregister-* key(s) on schema "call_log":
  x-openregister-seed, x-openregister-archival. Typo? See Schema::ANNOTATION_VOCABULARY for the declared keys.
```

Result: log-style schemas that are supposed to be immutable + auto-expire are deletable + non-expiring. The openconnector dashboard (PR #838) reads call_log / job_log / synchronization_log via OR's groupBy primitive (#1611). Users see empty dashboards when integrations actually ran successfully, because nothing prevents the rows from being deleted and no retention sweep ever ran.

A sibling spec exists (`archival-destruction-workflow`) that implements the Dutch NEN 15489 destruction workflow (Archiefwet — `archiefactiedatum` / `archiefnominatie` / destruction lists). That spec uses `retention.*` on the *object instance* row (`ObjectEntity::getRetention()`). This change is the **complementary missing piece**: schema-level retention rules (ISO-8601 durations, condition expressions) that compute the effective retention for every row in an archival schema, plus the deletion-guard and sweep cron that enforce them.

The two specs are intentionally coupled at the column (`_retention`) and divergent at the policy:

- **archival-destruction-workflow** = NEN 15489 archival lifecycle (per-object Archiefwet metadata, multi-step approval, destruction lists, legal hold).
- **add-archival-annotation-support** (this change) = **schema-level retention rules** — declarative, automatic, sub-day granularity, oriented at log-style data.

## What Changes

1. **Vocabulary** — add `x-openregister-archival` and `x-openregister-seed` to `Schema::ANNOTATION_VOCABULARY` so the import path stops dropping them. Update `Schema::cleanObject()` fold-up so the annotation reliably lands in `configuration['x-openregister-archival']` whether the schema source declares it at top-level or under `configuration`.

2. **Validation** — new `ArchivalAnnotationValidator` (mirrors `LifecycleAnnotationValidator`) validates the annotation shape at schema-save time:
   - `retention.default` MUST be a string parseable as ISO-8601 duration (`P30D`, `PT1H`, `P1Y6M`, …).
   - `retention.rules[i].condition` MUST be a non-empty string (a simple `<field> <op> <literal>` expression — `statusCode < 400`, `archived == true`, …).
   - `retention.rules[i].retention` MUST be a string parseable as ISO-8601 duration.
   - `retention.rules[i].reason` is optional (free-form string for ops surfacing).
   - Unknown keys under `x-openregister-archival.retention` are rejected with a clear schema-save error (HTTP 422). Wire validation into `SchemaMapper::insert()` / `update()` alongside the existing lifecycle / aggregations validators.

3. **Immutability** — `ObjectService::deleteObject()` rejects user-driven deletes with a structured 403 when the current schema declares `x-openregister-archival`. The retention sweep job is exempted via a private `$_retentionSweep` flag on the same code path (mirrors the existing `_rbac` / `_multitenancy` private-flag pattern). The existing `appendOnly` path stays as the stricter / general-purpose immutability switch; archival adds time-bounded auto-expiry on top.

4. **Retention sweep cron** — new `OCA\OpenRegister\Cron\ArchivalRetentionTask` (`TimedJob`, 1-hour interval, `TIME_INSENSITIVE`):
   - Iterates all schemas where `getConfiguration()['x-openregister-archival']` is set.
   - For each `(register, schema)` pair, opens the magic table via `MagicTableHandler::getTableNameForRegisterSchema()` and runs a single native SQL pass.
   - Per row, evaluates `retention.rules[]` in declared order (first match wins); fallback to `retention.default`.
   - Rows where `created + effectiveRetention < NOW()` are deleted via the standard `DeleteObject` handler with the sweep flag set so the immutability gate is bypassed.
   - Condition expressions parsed by a new `RetentionConditionEvaluator` (deliberately tiny: `<lhs-field> <op> <literal>` with ops `<`, `<=`, `==`, `!=`, `>=`, `>` and literals: integer / float / quoted string / bool).
   - Emits a per-run summary log entry: `{schemaSlug, scanned, expired, deleted}`.

5. **Surface in UI** — `ObjectEntity::jsonSerialize()` (already emits `retention` from the column) is unchanged for back-compat; the new render path adds `_retention: { effectiveRetention, matchedRule, expiresAt }` only when the schema declares `x-openregister-archival`. Computed on read by a thin helper that re-runs the same evaluator the cron uses, so the UI can show "this row kept until 2026-06-21 because rule #2 (`statusCode >= 400`)".

## Scope

- New annotation: `x-openregister-archival` and `x-openregister-seed` enter the vocabulary.
- New file: `lib/Service/Archival/ArchivalAnnotationValidator.php`.
- New file: `lib/Service/Archival/RetentionConditionEvaluator.php`.
- New file: `lib/Service/Archival/RetentionEvaluator.php` (orchestrator — input: row + annotation, output: `{effectiveRetention, matchedRule, expiresAt}`).
- New file: `lib/Exception/ArchivalImmutableException.php` (HTTP 403, structured body `{error: SCHEMA_ARCHIVAL_IMMUTABLE, schema, operation, hint}`).
- New file: `lib/Cron/ArchivalRetentionTask.php`.
- Modified: `lib/Db/Schema.php` — vocabulary extension + minor jsdoc.
- Modified: `lib/Db/SchemaMapper.php` — wire `ArchivalAnnotationValidator` alongside the lifecycle / aggregations validators.
- Modified: `lib/Service/ObjectService.php` — immutability gate in `deleteObject()`.
- Modified: `lib/Db/ObjectEntity.php` — `_retention` block on jsonSerialize when the schema declares archival.
- Modified: `appinfo/info.xml` — register `ArchivalRetentionTask`.

## Out of scope

- Per-tenant retention overrides — multi-tenant settings stay out of v1.
- Cross-schema cascade ("synchronization_contract is archival because its parent synchronization is") — not needed for the openconnector fleet's current schemas.
- Complex condition expressions (`AND` / `OR` / sub-expressions / function calls) — keep the evaluator deliberately minimal; bigger needs can layer a real expression engine later.
- The `archival-destruction-workflow` spec's NEN 15489 surface stays untouched — this change is purely additive at the schema-level retention layer.
