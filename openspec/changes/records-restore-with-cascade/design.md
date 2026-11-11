# Design: records-restore-with-cascade

Read at openregister development c53dd0685c.

## D-1: the trigger becomes a column

`openregister_audit_trails` (`lib/Db/AuditTrailMapper.php:110`) gains a
nullable `trigger_object` (uuid, 36) with an index `(trigger_object, action)`.
It is written in the same insert as the row:

- `AuditTrailMapper::buildAuditTrail()` (`lib/Db/AuditTrailMapper.php:802`) sets
  it from `cascadeContext['triggerObject']` when a cascade context is given,
  which covers the batch cascade rows written by
  `ReferentialIntegrityService::writeBatchCascadeAuditTrails()`
  (`lib/Service/Object/ReferentialIntegrityService.php:1640-1690`) and the
  per-object path through `DeleteObject` (`lib/Service/Object/DeleteObject.php:428-440`);
- `ReferentialIntegrityService::logIntegrityAction()` (`:1302-1340`) sets it
  from `changed['triggerObject']` for `set_null` and `set_default` rows.

The column is a projection of `changed`, which the hash already covers, so it
stays out of the sealed canonical form and no chain is re-sealed (openregister
ADR-003). The builder confirms that against the current canonicaliser in
`lib/Service/AuditHashService.php`. No existing row is backfilled: rewriting
sealed rows is what ADR-003 forbids.

## D-2: the set-null evidence lives as long as the window

`logIntegrityAction()` stops hard-coding `+30 days` (`:1327`). A
`set_null` or `set_default` row expires no earlier than the triggering object's
`destroyableFrom` (`lib/Db/ObjectEntity.php:1969`) and never earlier than the
resolver's 30-day floor, the same rule `buildAuditTrail()` applies through
`resolveAuditExpiry()` (`AuditTrailMapper.php:1015-1036`). Otherwise a
reference cleared by a delete with a 90-day window could not be put back on
day 31.

## D-3: one service, one transaction

A new `lib/Service/Deletion/CascadeRestoreService.php` with
`preview(ObjectEntity $root): CascadeRestorePlan` and
`restore(ObjectEntity $root, bool $cascade): CascadeRestoreResult`.

The plan reads `openregister_audit_trails` where `trigger_object = root` and
`action` is one of `referential_integrity.cascade_delete`, `set_null`,
`set_default`, capped at 5,000 rows; a larger cascade is refused with 409 naming
the count, so nobody restores half a tree by accident. For each row it resolves
the object with `findMultipleAcrossAllMagicTables(includeDeleted: true)` (as
`restoreMultiple()` does, `lib/Controller/DeletedController.php:529-532`) and
classifies it:

| class | meaning | action |
|---|---|---|
| `restore` | still soft-deleted, deleted by this cascade | restore |
| `relink` | survivor whose field still holds what the delete wrote | put the reference back |
| `changed` | survivor whose field changed since | leave, name it |
| `gone` | destroyed, or its window has passed | leave, name it |
| `already` | restored or recreated since | leave, name it |
| `forbidden` | caller lacks `update` on it | refuse the whole act |

A dependant counts as "deleted by this cascade" only when its `deleted.deletedAt`
equals the root's within the transaction's second; an object deleted again later
by someone else is `already`, not restored behind their back.

`restore()` runs every `restore` and `relink` in one database transaction,
restoring through `objectEntityMapper->restoreObject()` (as `restore()` does at
`DeletedController.php:414`) and re-linking through the object service's patch
path so validation, events and the audit apply. `relink` for a single-valued
field writes the previous value only when the field is null (or the default the
delete wrote); for an array it adds the uuid back only when it is absent. This
is the rule `undo-a-bulk-action` uses: a reversal never writes over a later
edit.

## D-4: the endpoints

- `POST /api/deleted/{id}/restore` (`appinfo/routes.php:1441`) reads an optional
  `cascade` (default `true`). The authorization per object is the existing
  `userMayActOnDeletedObject(action: 'update')` (`DeletedController.php:398`).
  Any `forbidden` item refuses with 403 naming the count and the schemas, never
  the uuids of objects the caller cannot see. The response keeps `success` and
  `message` and adds `restored`, `relinked` and `skipped` (per class, with
  uuids only for objects the caller may read).
- `GET /api/deleted/{id}/restore-preview` returns the plan without writing,
  registered beside `destructionPreview` (`appinfo/routes.php:1443-1448`).
- `restoreMultiple()` (`:505`) restores each listed root with its cascade.

`recordRestore()` (`:456-485`) records the root with the counts, and each
dependant gets an `object.restored` entry whose context names the root.

## D-5: the page

`src/views/deleted/DeletedIndex.vue` gains "Restore with related records" on
a deleted object that has cascade rows, opening a dialog (a separate file under
`src/dialogs/`, hydra modal isolation) that shows the preview grouped by class
and schema. `src/store/modules/deleted.js` gains the preview call.

## Declarative-vs-imperative decision

Imperative, in the deletion service. The cascade itself is declared on the
schema (`onDelete` on a relation, read by `ReferentialIntegrityService`), and
this change does not add a declaration: undoing a cascade follows from the one
already declared. A second declaration for the way back could disagree with the
way in.

## Risks

- Security (hydra ADR-005): per-object `update` on every item, fail closed on
  the whole act. The 403 names counts and schemas, not objects the caller
  cannot read.
- Integrity: one transaction; a failure rolls back every restore and relink.
  The `already` and `changed` classes stop the restore from undoing someone
  else's later work.
- Performance (hydra ADR-058): one indexed audit lookup, one batched object
  lookup, a 5,000-row cap.
- Old deletes: objects deleted before the migration have no `trigger_object`
  and restore alone, with the response saying the cascade is not known.
