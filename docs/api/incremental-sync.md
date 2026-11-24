# Incremental sync

An API consumer that keeps its own copy of a register (a search index, a
data warehouse, another app) does not need to read every object on every
run. It reads what changed since its previous run, what was deleted, and
what was destroyed, and starts the next run from a moment the server gives
it.

## The recipe

Keep one value between runs: the `syncWatermark` of the previous run (W).
On the very first run, do a full read and keep its watermark.

1. **Changed and deleted objects.** Read the object list with
   `@self[updated][gte]=W` and `_includeDeleted=true`:

   ```
   GET /api/objects/{register}/{schema}?@self[updated][gte]=2026-10-05T00:00:00+00:00&_includeDeleted=true
   ```

   A soft delete and a restore both move an object's `updated`, so this
   returns every object created, changed, deleted or restored since W. An
   object whose `@self.deleted` is set was deleted; one whose
   `@self.deleted` is null is live (again).

2. **Deletions since W, on their own.** When you only want the deletions,
   read the deleted list with the same scope words:

   ```
   GET /api/deleted?register=school&schema=lesson&deletedSince=2026-10-05T00:00:00+00:00
   ```

   `register` and `schema` take an id or a slug. The filters apply before
   pagination and to `total`. An unknown register or schema is a 404; a
   `deletedSince` that is not an ISO 8601 date-time is a 400 naming
   `deletedSince`. A filter is never silently ignored.

3. **Destroyed objects.** Once an object's bin window has passed it is
   destroyed and no longer on the deleted list. Ask for its tombstone:

   ```
   GET /api/deleted?deletedSince=2026-10-05T00:00:00+00:00&_includeDestroyed=true
   ```

   After the bin rows, the list carries one tombstone per destruction
   recorded at or after `deletedSince`, within the `register` and `schema`
   filters:

   ```json
   { "id": "<uuid>", "register": 4, "schema": 12,
     "destroyedAt": "2026-10-07T09:12:00+00:00", "tombstone": true }
   ```

   A tombstone carries no object data: the data is destroyed. You see a
   tombstone only where you may read the destruction record (an
   administrator, or the person who destroyed the object). `total` counts
   the bin rows and the tombstones; a page is filled from the tombstones
   once the bin rows are used up. `_includeDestroyed` without
   `deletedSince` is a 400: an unbounded scan of every destruction is not a
   sync read.

   The response also carries `destroyedCoverageFrom`, the oldest
   destruction entry the audit trail still holds. The audit trail has its
   own retention: when your W is older than `destroyedCoverageFrom`, a
   destruction may no longer be on record, so do a full read instead.

4. **Keep the new watermark.** Both lists return `syncWatermark`: the
   server's time, in UTC ISO 8601, taken before the query ran. A write
   committed while your read was running has an `updated` at or after it,
   so starting the next run from it never skips a change. Use the
   watermark of the first request of the run, not your own clock.

## Example

```
GET /api/deleted?schema=lesson&deletedSince=2026-10-02T00:00:00+00:00&_includeDestroyed=true
```

```json
{
  "results": [
    { "id": "…", "@self": { "deleted": { "deletedAt": "2026-10-06T10:00:00+00:00" } }, "deletionWindow": { } },
    { "id": "…", "register": 4, "schema": 12, "destroyedAt": "2026-10-04T09:12:00+00:00", "tombstone": true }
  ],
  "total": 2,
  "page": 1,
  "pages": 1,
  "limit": 20,
  "offset": null,
  "destroyedCoverageFrom": "2026-07-01T00:00:00+00:00",
  "syncWatermark": "2026-10-08T06:00:00+00:00"
}
```
