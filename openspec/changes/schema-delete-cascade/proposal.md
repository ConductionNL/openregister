---
kind: code
---

# Schema delete cascade

## Why

Deleting a schema that still holds objects is broken end-to-end, and the failure
mode corrupts data rather than refusing cleanly.

The frontend (`CnEditDataModal.removeSchema()`) unlinks the schema from its
register **before** issuing the `DELETE`. When the backend correctly refuses with
HTTP 409, the unlink is already committed: the schema survives but is detached
from its register and vanishes from the pages editor. This was reproduced live on
the `cowboy` app (register 2466) — the register's `schemas` array went
`[4432, 4434, 4438]` → `[4432]` after a single failed delete.

The refusal that triggers it is also unreadable (the modal surfaces axios's
`"Request failed with status code 409"` instead of the structured
`{"error":"schema-has-objects","objectCount":1}` body), and there is **no clean
way out**: the only escape hatch is `?force=true`, which deletes the schema and
permanently orphans its rows in the magic table. The bulk endpoint that would
delete the objects first returns **HTTP 500** — `ObjectService::deleteObjectsBySchema()`
is a throwing stub (`lib/Service/ObjectService.php:3568`).

Users want the obvious outcome: *delete the schema **and** its objects, leaving a
clean app.* That path does not exist today.

## What Changes

### Backend (openregister)

- **Implement `ObjectService::deleteObjectsBySchema()`** on top of the already-complete
  `MagicMapper::deleteObjectsBySchema()` (`lib/Db/MagicMapper.php:6098`), fixing the
  HTTP 500 on `POST /api/bulk/{register}/{schema}/delete-objects`. Objects are
  snapshotted to the audit trail before removal, then hard-deleted.
- **Fix `BulkController::deleteSchema()` too.** Despite its name it does *not*
  delete a schema — it calls the same stub and is therefore **also HTTP 500**. It
  is a near-duplicate of `deleteSchemaObjects()` (the only difference is slug
  resolution). It is repaired by the same service fix; its misleading name is
  documented, not changed (back-compat).
- **Add a cascade path**: `DELETE /api/schemas/{id}?deleteObjects=true` hard-deletes
  every object of the schema (audited), drops the now-empty magic table, and then
  deletes the schema — leaving **no orphans**. One call, one authorization gate,
  one audit trigger context.
- **Drop the magic table on cascade.** Today no `SchemaDeletedEvent` listener drops
  it (only `ActivityEventListener` and `WebhookEventListener` are registered), so
  even `?force=true` leaves the table behind forever.
- **Repair the dead guard** in `SchemaMapper::delete()` (`lib/Db/SchemaMapper.php:1587`).
  It counts objects in the **retired `openregister_objects` blob table**, which is
  always empty for magic-table objects — it counts 0 and waves everything through.
  Three non-controller callers (`SchemasToolProvider`, `SchemaTool`,
  `TablesSchemaSyncService`) are consequently unguarded today. **BREAKING (behavioural)**:
  once repaired, those callers refuse to delete a schema with objects.
- **`?force=true` is retained** for API back-compat (it still orphans data) but MUST NOT
  be exposed in the UI. `force` and `deleteObjects` are mutually exclusive.

### Frontend (nextcloud-vue)

- `CnEditDataModal.removeSchema()`: **DELETE first, unlink only on success** — closes
  the data-corruption bug.
- Surface the real error via `parseAxiosError()` — *"Cow still has 1 object"*, not
  *"status code 409"*. Applies to every catch block in the modal.
- On a `schema-has-objects` 409, offer a clearly destructive, **confirm-first**
  "Delete schema and its N objects" action that calls the cascade. **Force is never
  offered in the UI.**

## Capabilities

### New Capabilities

None. The behaviour extends an endpoint that an existing capability already owns.

### Modified Capabilities

- `runtime-schema-api`: owns `DELETE /api/schemas/{id}` and the existing requirement
  *"Runtime schema deletion is guarded by object count"* (the 409/force scenarios).
  `SchemasController::destroy()` cites this capability by name in-code. The requirement
  gains the `?deleteObjects=true` cascade, the magic-table drop, mutual exclusion with
  `force`, and the repaired mapper-level guard.
- `deletion-audit-trail`: Requirement 5 (full snapshot before deletion), Requirement 6
  (cascade deletions produce individual audit entries with trigger context) and
  Requirement 9 (bulk deletes produce per-object entries) already bind this work. The
  requirements gain the schema-teardown cascade as an audited trigger, and Requirement 4
  ("purge requires prior soft delete") gains an explicit, narrow exception for schema
  teardown — where the magic table itself is destroyed, so a soft-delete tombstone
  could not survive anyway.

## Impact

**Affected code**
- `lib/Service/ObjectService.php` — implement `deleteObjectsBySchema()` (stub at :3568).
- `lib/Controller/SchemasController.php` — `destroy()` gains `deleteObjects` handling (:830).
- `lib/Controller/BulkController.php` — `deleteSchemaObjects()` (:525) and `deleteSchema()` (:444) unbreak.
- `lib/Db/SchemaMapper.php` — repair the guard (:1587), add an explicit force/skip parameter.
- `lib/Db/MagicMapper.php` — reuse `deleteObjectsBySchema()` (:6098), `dropTable()` (:4697),
  `findAllInRegisterSchemaTable()` (:5719); no new mapper primitives expected.
- `nextcloud-vue`: `src/modals/CnEditDataModal.vue`, `src/utils/errors.js` (`parseAxiosError`).

**APIs**
- `DELETE /api/schemas/{id}?deleteObjects=true` — new, additive. Default behaviour
  (no query param) is unchanged: still 409 on a schema with objects.
- `?force=true` — unchanged, retained, UI-suppressed.

**Not in scope / explicitly not applicable**
- **No seed data.** This change introduces **no OpenRegister schemas or registers**, so
  there is no seed-data section and no seed task.
- **ADR-031 (declarative-vs-imperative) does not apply.** This change introduces no
  lifecycle, aggregation, calculation, notification or widget behaviour — it is an API
  and data-teardown change only.
- `deleteObjectsByRegister()` is a sibling throwing stub (`:3594`). It is **out of scope**;
  a register-wide cascade is a separate change.

**Dependents**: `opencatalogi`, `softwarecatalog` and every OpenBuild-generated app consume
`DELETE /api/schemas/{id}`. The default path is unchanged, so they are unaffected unless
they opt into `deleteObjects=true`.
