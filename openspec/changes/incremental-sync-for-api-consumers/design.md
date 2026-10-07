# Design: incremental-sync-for-api-consumers

## D-1. A deletion is a change, so it moves `updated`

A sync consumer asks one question: what is different since time T? A soft
delete is a difference. Setting `updated` on delete and on restore lets
one read answer both halves:

```
GET /api/objects/{register}/{schema}?@self[updated][gte]=<T>&_includeDeleted=true
```

Each returned object carries `@self.deleted` (null when live), so the
consumer splits the page into upserts and removals itself.

Rejected: a separate `deletedAt` filter on the object list. It makes the
consumer run two queries and merge them, and the merge is where sync bugs
live.

`updated` moves only on the soft delete and on the restore. Cascade
SET_NULL writes already go through the save path and already set it.

## D-2. The deleted list takes the same scope words as the object list

`GET /api/deleted` gets three filters:

| Parameter | Meaning |
|---|---|
| `register` | id or slug; limits the scan to that register's magic tables |
| `schema` | id or slug; limits it to that schema's table |
| `deletedSince` | ISO 8601; rows whose `deleted.deletedAt` is at or after it |

They narrow the table scan in `findDeletedAcrossAllMagicTables` and the
count in `countDeletedAcrossAllMagicTables`, so `total` matches the
filtered rows. An unknown register or schema is refused with 404, the same
rule zoeken-filteren applies on the read path. An unparseable
`deletedSince` is refused with 400 naming the parameter. It is never
ignored, because an ignored filter returns the whole bin and reads as
"everything was deleted".

## D-3. Destroyed objects come back as tombstones from the audit trail

After the bin window the row is gone, but the destruction is recorded in
the audit trail. With `deletedSince` and `_includeDestroyed=true`, the
deleted list adds one tombstone per destruction entry created at or after
`deletedSince`, filtered by register and schema:

```json
{ "id": "<uuid>", "register": 4, "schema": 12,
  "destroyedAt": "2026-10-07T09:12:00+00:00", "tombstone": true }
```

A tombstone carries no object data: the data is destroyed, and repeating
it here would undo the destruction. The rows pass through
`readableDestructionRecords`, the same scope the per-object destruction
record endpoint uses, so a consumer sees a tombstone only where it could
read the destruction record.

`_includeDestroyed` without `deletedSince` is refused with 400: an
unbounded scan of every destruction in the audit trail is not a sync read.

Risk: the audit trail has its own retention. A consumer that has not run
for longer than the audit retention can miss a destruction. The response
states the oldest destruction entry it could read (`destroyedCoverageFrom`),
so the consumer sees when its `since` is older and does a full resync.

## D-4. The watermark is the server's clock, read before the query

The object list and the deleted list return `syncWatermark`: the server's
time taken before the query runs, in ISO 8601 with offset. A write that
commits during the read has an `updated` after the watermark, so the next
run picks it up. Taking the time after the query would lose it.

The watermark is part of the paginated envelope, next to `total`, `page`
and `pages`. It is not an `@self` field, because it describes the read and
not an object.

## D-5. No new authorisation path

Every read keeps its current scope: the object list its RBAC and
multitenancy, the deleted list the read-scoped bin of openregister#4078,
the tombstones the destruction-record scope. Sync is a way of reading,
not a permission.

## Files

- `lib/Service/Object/DeleteObject.php`: set `updated` beside `setDeleted`.
- The restore path behind `DeletedController::restore` and `restoreMultiple`:
  set `updated` when `deleted` is cleared.
- `lib/Controller/DeletedController.php`: read and validate `register`,
  `schema`, `deletedSince`, `_includeDestroyed`; add `syncWatermark` and
  `destroyedCoverageFrom` to the envelope.
- `lib/Db/ObjectEntityMapper.php` (`findDeletedAcrossAllMagicTables`,
  `countDeletedAcrossAllMagicTables`): accept the filters and narrow the
  table list before scanning.
- `lib/Db/AuditTrailMapper.php`: a read of destruction entries by
  register, schema and created-since.
- `lib/Controller/ObjectsController.php`: add `syncWatermark` to the list
  envelope.
- `openspec/specs/deletion-audit-trail/spec.md` gains a cross-reference on
  archive.
