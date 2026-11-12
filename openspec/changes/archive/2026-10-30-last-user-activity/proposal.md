---
kind: code
depends_on: []
---

# Proposal: last-user-activity

## Why

dossiq's "stalled cases" dashboard widget wants the cases nobody has worked on
for a while. The obvious filter is `@self.updated`, but `updated` moves on every
write. dossiq stores recomputed fields on cases (`currentStatusDwellDays`,
`statusDwellBreached`), written by background jobs and listeners. Each of those
writes moves `updated`, so no case ever looks stalled.

Verified in the code on `development`:

- `SaveObject::updateObject()` sets `updated` to now on every update, whoever
  makes it, and `handleObjectCreation()` does the same on create.
- `MagicMapper::prepareObjectDataForTable()` defaults `_updated` to now when it
  is empty.
- `MagicBulkHandler`'s upsert writes `_updated = NOW()` on every conflicting row.

So background jobs (cron, `ActorForwardedJob` subclasses that act as the
original user), listeners, repair steps, occ commands and writes with RBAC off
all move `updated`.

## What changes

- A new metadata field, `@self.lastUserActivity`, in a new column
  `_last_user_activity` (datetime, nullable, indexed on new tables). Existing
  magic tables get the column through `addMissingColumns()`, like `_frozen`.
- `UserActivityStamper` sets it to now on create and update in `SaveObject`
  when the write is a person's: a web request (not the CLI, not `cron.php`),
  a signed-in user, not inside `SystemOperationContext`, and a `WriteCause` of
  `person`. Any other write carries the stored value forward.
- It filters and sorts like `updated` (`@self[lastUserActivity][lt]=...`,
  `_order[@self.lastUserActivity]=asc`), on the QueryBuilder and UNION paths.
- Sorting on a camelCase `@self` field now resolves the snake_case column on
  the single-table path, as the UNION path already did.

## Impact on `updated`

None. `updated` keeps its meaning and its writers. The new key appears in every
object's `@self`, next to `updated`.

## Out of scope

- Bulk saves (`MagicBulkHandler`) do not stamp it; they leave the stored value.
- Back-filling existing objects. They read as empty until a person writes them;
  a caller that wants them treats empty as "never", for example with
  `orNull` from `filter-or-null`.
