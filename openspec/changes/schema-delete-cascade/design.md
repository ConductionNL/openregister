# Design — Schema delete cascade

## Context

`DELETE /api/schemas/{id}` has exactly two outcomes today, and neither is the one
users want:

| Request | Outcome |
| --- | --- |
| `DELETE /api/schemas/{id}` (schema has N>0 objects) | HTTP 409 `{"error":"schema-has-objects","objectCount":N}` — correct, but the frontend corrupts the register on the way there |
| `DELETE /api/schemas/{id}?force=true` | Schema deleted, **rows orphaned forever** in the magic table, which is never dropped |

The clean third option — *delete the schema **and** its objects* — does not exist.
The endpoint that would provide the first half of it,
`POST /api/bulk/{register}/{schema}/delete-objects`, returns **HTTP 500** because
`ObjectService::deleteObjectsBySchema()` (`lib/Service/ObjectService.php:3568`) is a
throwing stub left behind when the blob objects table was retired.

**The primitives already exist.** This is mostly wiring:

| Primitive | Location | State |
| --- | --- | --- |
| `MagicMapper::deleteObjectsBySchema(Register, Schema, bool $hardDelete): int` | `lib/Db/MagicMapper.php:6098` | **fully implemented** — hard-deletes rows, or sets `_deleted`; returns a count |
| `MagicMapper::dropTable(string $tableName): void` | `lib/Db/MagicMapper.php:4697` | implemented |
| `MagicMapper::findAllInRegisterSchemaTable(...)` | `lib/Db/MagicMapper.php:5719` | implemented — needed to collect UUIDs/snapshots *before* deleting |
| `MagicTableHandler::getTableNameForRegisterSchema(Register, Schema): string` | `lib/Db/MagicMapper/MagicTableHandler.php` (exposed at `MagicMapper:669`) | implemented |
| `AuditTrailMapper::createAuditTrail(?old, ?new, ?action)` | `lib/Db/AuditTrailMapper.php:378` | implemented |

Constraints that bind this design:

- **`deletion-audit-trail` Req 5** — a full object snapshot MUST reach the audit trail
  *before* deletion, soft **or hard**.
- **`deletion-audit-trail` Req 6 / Req 9** — cascade and bulk deletions MUST produce
  **individual** audit entries carrying trigger context.
- **ADR-003** — the audit trail is append-only and SHA-256 hash-chained; entries are
  written through `AuditTrailMapper`, never bulk-inserted around it.
- **ADR-009** — per-object work must be bounded; schema-derived values computed once.
- The counting guard in `SchemasController::destroy()` uses `getStatistics()`, which
  **excludes soft-deleted rows** (verified live: 2 → 1 after one soft delete).

## Goals / Non-Goals

**Goals**

- One call the frontend can make that leaves **no orphans**: no rows, no magic table, no schema.
- Unbreak the two HTTP-500 bulk endpoints by implementing the service method they call.
- Every deleted object is audited before it disappears, with cascade trigger context.
- The dead `SchemaMapper` guard actually guards.
- `?force=true` keeps working for API clients; the UI never offers it.

**Non-Goals**

- Register-wide cascade (`deleteObjectsByRegister()` is a sibling stub at `:3594`) — separate change.
- Adding a `hardDelete`/purge parameter to `DELETE /api/objects/{r}/{s}/{id}` (still soft-only) — not needed here.
- Renaming the misleading `BulkController::deleteSchema()` (it deletes *objects*, not the schema) — back-compat.
- **No seed data.** This change introduces no registers or schemas, so there is no seed-data work.
- **ADR-031 does not apply** — no lifecycle, aggregation, notification or widget behaviour is introduced.

## Decisions

### D1 — Cascade is a query parameter on the existing endpoint: `DELETE /api/schemas/{id}?deleteObjects=true`

**Decision.** Add `deleteObjects=true` to `SchemasController::destroy()`. The server
performs the whole teardown. `force` and `deleteObjects` are **mutually exclusive**;
passing both is `HTTP 400`.

The resulting matrix:

| Request | Objects | Behaviour |
| --- | --- | --- |
| `DELETE /api/schemas/{id}` | N > 0 | **409** `schema-has-objects` (unchanged) |
| `DELETE /api/schemas/{id}` | 0 | Schema deleted; empty magic table dropped |
| `?deleteObjects=true` | N ≥ 0 | Objects audited + hard-deleted → table dropped → schema deleted |
| `?force=true` | N > 0 | Schema deleted, rows orphaned (**unchanged, back-compat, not in UI**) |
| both flags | any | **400** — refuse an ambiguous destructive intent |

**Why over frontend orchestration** (bulk-delete-then-schema-delete, two calls):

1. **Atomicity.** Two calls means the client can die between them, leaving a schema with
   zero objects and a stale register link — exactly the class of half-finished state this
   change exists to eliminate. The server can wrap phase 1 in a transaction; the client cannot.
2. **The table drop is not expressible client-side.** No API drops a magic table. Frontend
   orchestration would *still* leave the orphaned table behind — it only solves the rows.
3. **One authorization gate.** Both `SchemasController::destroy()` and
   `BulkController::deleteSchemaObjects()` already gate on `checkSchemaManagePermission()`,
   but orchestration would evaluate it twice at two different moments (TOCTOU).
4. **One audit trigger context.** Req 6 wants each cascaded object to trace back to *the*
   trigger. A single server-side operation has one trigger; two client calls have none.

**Why a query parameter over a new endpoint** (e.g. `POST /api/schemas/{id}/cascade-delete`):
the operation is still "delete this schema" — the flag selects the *disposition of dependent
data*, exactly as `force` already does on the same endpoint. Introducing a second deletion
URL for the same resource invites the two to drift.

**Rejected: making cascade the default.** Silently destroying objects on a bare `DELETE`
is the foot-gun the 409 guard was built to close. The default stays "refuse".

### D2 — Phase 1 is transactional; the table drop (phase 2) is a post-commit, best-effort reclaim

The cascade **cannot** be one atomic unit, and pretending otherwise would be a lie in the spec:
`DROP TABLE` is DDL, and on MySQL/MariaDB DDL causes an **implicit commit** and cannot be
rolled back. (PostgreSQL has transactional DDL; OpenRegister supports both, so we design for
the weaker guarantee.)

Order of operations:

1. **Phase 1 (single transaction)** — collect snapshots/UUIDs via
   `findAllInRegisterSchemaTable()` → write one audit entry per object → hard-delete the rows
   → delete the schema entity → **commit**.
2. **Phase 2 (after commit, idempotent)** — `dropTable()` the now-empty magic table.

If **phase 1** fails at any point → roll back; nothing is deleted; the caller gets a 500 and
the schema is exactly as it was.

If **phase 2** fails → the user's intent is already fully satisfied (schema gone, objects gone,
audit written). All that remains is an **empty** orphan table. Failing the request here would be
worse than useless: it would report failure for an operation that succeeded, and there is nothing
to roll back to. So the drop failure is logged at WARNING and reported honestly in the response
body as `tableDropped: false`; the request still succeeds. The empty table is harmless and
reclaimable by a later repair step.

**Rows are deleted before the table is dropped, even though the drop would remove them anyway.**
This is deliberate: the row-delete is the audited step, it is the code path the bulk endpoint
needs regardless, and it keeps "what got deleted" observable and countable. Dropping first and
counting after is not reconstructible.

Response shape on cascade success:

```json
{ "success": true, "schemaId": 4434, "deletedCount": 1,
  "deletedUuids": ["…"], "tableDropped": true }
```

### D3 — Objects are hard-deleted, and Req 4 gains a narrow, documented exception

`deletion-audit-trail` **Req 4** says permanent deletion requires *prior soft delete*. A schema
cascade hard-deletes directly. This is a genuine tension and is resolved, not ignored:

Req 4 governs the **per-object trash lifecycle** (`DeletedController::destroy()` — "purge what is
already in the bin"). Schema teardown is a different event: **the magic table itself is destroyed**.
A soft-delete tombstone written into a table that is about to be dropped survives for microseconds
and is then destroyed anyway — soft-deleting first would be pure ceremony that produces *two* audit
events per object instead of one, for no recoverability whatsoever.

The compliance guarantee Req 4 actually protects — *nothing disappears without a reconstructible
record* — is preserved by **Req 5**: the full snapshot is written to `openregister_audit_trail`, a
**separate table that is not dropped**. The data remains reconstructible from the audit trail after
the magic table is gone.

The spec delta therefore scopes Req 4 to the trash API and states the schema-teardown exception
explicitly. This is a spec change, not a silent violation.

### D4 — Cascade audit entries use a dedicated action with trigger context

Per Req 6, each object gets its **own** entry (not one summary row), carrying:

- `action`: `schema.cascade_delete`
- `objectUuid`: the object's UUID
- `changed.triggeredBy`: `schema_deletion`
- `changed.cascadeContext.triggerSchema`: the deleted schema's slug
- `user`: the user who initiated the schema deletion

Written through `AuditTrailMapper` so ADR-003's hash-chaining applies. Entries are written
**inside** phase 1's transaction, before the rows are removed — so a rollback discards the
audit entries too, and a committed audit entry always implies a genuinely deleted object.

**ADR-009 (bounded per-object work):** snapshot collection and audit writes are **chunked**
(batch-read, batch-process; the schema is resolved once, not once per object). Hash-chaining is
inherently sequential so audit inserts cannot be parallelised. The synchronous cascade is
therefore appropriate for the interactive case it is built for (an app author deleting a schema
with tens-to-thousands of objects). **A very large schema (≫10k objects) will make this request
slow** — see Risks; moving to a background job is deferred, not designed away.

### D5 — Repair the `SchemaMapper` guard rather than delete it; add an explicit bypass parameter

`SchemaMapper::delete()` (`lib/Db/SchemaMapper.php:1587`) counts objects in the retired
`openregister_objects` blob table. For magic-table objects that count is **always 0**, so the
guard passes everything. `SchemasController::destroy()`'s `getStatistics()` check is currently
the *only* thing protecting anything.

**Repair it, don't remove it.** `delete()` has **four** callers, and three of them are not the
controller:

- `lib/Controller/SchemasController.php:887` (guarded by `getStatistics()`)
- `lib/Mcp/BuiltIn/SchemasToolProvider.php:227` — **AI-facing, unguarded**
- `lib/Tool/SchemaTool.php:447` — **AI-facing, unguarded**
- `lib/Service/ObjectSource/TablesSchemaSyncService.php:303` — **unguarded**

Removing the mapper guard and documenting "the controller owns it" would leave two LLM-invokable
deletion surfaces able to destroy a schema full of objects with no check at all. Defence in depth
belongs at the mapper, which is the choke point all four share.

**Implementation constraint — the circular dependency is real.** The existing comment ("direct
database query to avoid circular dependency") is not superstition:
`MagicStatisticsHandler.__construct` injects `SchemaMapper`
(`lib/Db/MagicMapper/MagicStatisticsHandler.php:106`), so `SchemaMapper` **cannot** inject
`MagicStatisticsHandler` or `MagicMapper` back. The repaired guard therefore keeps the existing
strategy — a **direct DB query**, just pointed at the **magic tables** (deterministic name
`openregister_table_{registerId}_{schemaId}`, resolving the schema's registers first) instead of
the dead blob table, honouring `tableExists()` and excluding soft-deleted rows to match the
controller's semantics.

`delete()` gains an explicit `bool $force = false` (bypass) parameter:

- **cascade path** needs no bypass — the rows are already gone, so the guard naturally counts 0;
- **`?force=true` path** passes `force: true` to deliberately orphan;
- the three other callers keep the default and become **genuinely guarded** — a behaviour change
  (see Risks).

## Risks / Trade-offs

- **Repairing the guard hardens three previously-unguarded callers → could break them.**
  `TablesSchemaSyncService::303` deletes schemas while syncing virtual Tables registers; if such
  a schema has objects, it will now throw `ValidationException` where it previously succeeded
  silently. → Mitigation: audit each of the three call sites during implementation and decide per
  site whether it should pass `force: true` (legitimate teardown) or genuinely refuse. Do not blanket-force.
  This is the sharpest edge in the change and is called out as a deferred question.

- **Very large schemas make the cascade slow.** Per-object audit writes are sequential
  (hash-chained, ADR-003). A schema with ≫10k objects risks a PHP timeout mid-cascade. → Mitigation:
  phase 1 is transactional, so a timeout rolls back cleanly — the failure is slow, not corrupting.
  A background-job cascade above a threshold is out of scope for this change.

- **Phase 2 (`dropTable`) failure leaves an empty orphan table.** → Mitigation: accepted and reported
  (`tableDropped: false`), logged at WARNING. Harmless (empty), and strictly better than today, where
  `?force=true` leaves a **populated** orphan table unconditionally.

- **The `deleteObjects` flag is destructive and irreversible.** Objects are hard-deleted; the trash
  cannot restore them. → Mitigation: never the default; UI must **confirm-first** with the object count
  named ("Delete schema and its 1 object"); the audit trail retains full snapshots.

- **`force` remains in the API.** It still orphans rows. → Mitigation: retained only for back-compat,
  never surfaced in the UI, and continues to log at WARNING with the actor and orphan count.

- **Frontend reorder changes failure semantics.** With DELETE-before-unlink, a *successful* DELETE
  followed by a *failed* PATCH now leaves a register referencing a dead schema id — the mirror image
  of today's bug. → Mitigation: strictly the lesser evil (a dangling id is cosmetic and self-heals on
  reload; today's orphaned-schema state loses the schema from the editor entirely). The cascade path
  makes it rarer still, since the common 409 disappears.

## Migration Plan

No database migration. The API is additive: omitting `deleteObjects` preserves today's behaviour
exactly, so existing clients (`opencatalogi`, `softwarecatalog`, OpenBuild apps) are unaffected.

Rollback = revert; nothing persists a new column or table. Note that the guard repair is the one
non-additive piece — if it proves too disruptive at the three call sites, it can be shipped with
`force: true` at those sites without blocking the cascade.

## Open Questions

1. **Should the three newly-guarded callers pass `force: true`?** Provisional: no — audit each and
   let them refuse, since silent schema destruction is what we are fixing. `TablesSchemaSyncService`
   is the likeliest legitimate exception.
2. **Threshold for a background-job cascade?** Provisional: none in this change; synchronous only,
   documented as a known limit.
3. **Should `BulkController::deleteSchema()` be deprecated?** It is a misleadingly-named duplicate of
   `deleteSchemaObjects()`. Provisional: repair it, mark it deprecated in the docblock, remove in a
   later change.
