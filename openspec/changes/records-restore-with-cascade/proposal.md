---
kind: code
depends_on: [delete-window-and-recorded-destruction]
---

# Proposal: records-restore-with-cascade

## Summary

A sales manager who deleted a lead by mistake restores it, and the contact
moments, tasks and other records the delete took with it come back in the same
act. References that the delete cleared on surviving records are put back where
nobody has changed them since. Before restoring, the manager can see what will
come back and what will not, and why. The restore is one recorded act, inside
the recovery window, and it never overwrites a later edit.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| pipelinq | plat-restore-deleted | Bring back a record you deleted by mistake, together with what was deleted with it | partial |

Row `plat-restore-deleted` in pipelinq's matrix, owned here because
`built.owner` is ConductionNL/openregister: pipelinq's leads, contacts and
activities are Open Register objects and the trash is Open Register's.

Demand rows:

- changelog, https://github.com/espocrm/espocrm/issues/3603

Competitor yes cells, quoted from the packet:

- HubSpot CRM: "deleted contacts sit in \"the recycling bin within 90 days\";
  https://knowledge.hubspot.com/object-settings/restore-crm-changes rolls
  records back \"to a previous point within the last 14 days\" including
  changes by workflows and imports (Starter and up)." Evidence:
  https://knowledge.hubspot.com/privacy-and-consent/manage-data-retention-policy-settings
  and https://knowledge.hubspot.com/object-settings/restore-crm-changes
- Pipedrive: "\"Admins can restore deleted items within 30 days after
  deletion; the linked historical information will be restored as well\";
  https://support.pipedrive.com/en/article/restore-data restores items in
  bulk. All plans." Evidence:
  https://support.pipedrive.com/en/article/how-can-i-delete-items-in-pipedrive
  and https://support.pipedrive.com/en/article/restore-data
- EspoCRM: "client/src/views/record/deleted-detail.js:44 action
  'restoreDeleted' labelled Restore on a deleted record, and 10.0.0 added
  cascade removal and restore of linked records (\"Cascade removal and
  restore\")". Evidence: https://github.com/espocrm/espocrm/releases/tag/10.0.0

## Why

A delete in Open Register already cascades and already writes down what it
took. A restore does not read that down:

- The cascade soft-deletes dependants in one batch per table
  (`lib/Service/Object/ReferentialIntegrityService.php:1475-1540`) after
  clearing or defaulting references on survivors (`:222-257`), and writes an
  audit entry per dependant with action `referential_integrity.cascade_delete`
  and a `cascadeContext` naming the root as `triggerObject`
  (`:1640-1690`), as `deletion-audit-trail` requirement 6 demands. Cleared
  references are audited as `referential_integrity.set_null` and
  `referential_integrity.set_default` with the previous value (`:222-257`).
- The root's own audit entry carries only counts
  (`lib/Service/Object/DeleteObject.php:1003-1020`,
  `referential_integrity.root_delete`).
- `DeletedController::restore()` restores exactly one object
  (`lib/Controller/DeletedController.php:374-436`); `restoreMultiple()` restores
  a list the caller has to assemble (`:505-570`). pipelinq's matrix: "restoring
  what a cascade deleted with the record is a manual multi-select".
- The cascade's trigger lives inside the JSON `changed` column of
  `openregister_audit_trails`, which has no index on it, so today a restore
  could only find the dependants by scanning audit rows.
- `referential_integrity.set_null` and `set_default` entries expire after a
  fixed 30 days (`ReferentialIntegrityService::logIntegrityAction()` at
  `:1302`, `setExpires(new DateTime('+30 days'))` at `:1327`), which can be
  shorter than the recovery window a schema declares.

The open change `delete-window-and-recorded-destruction` makes a restore
"one act" recorded with its actor, and `DeletedController::recordRestore()`
(`:456-485`) already writes `object.restored`. Neither brings back the cascade.

## What changes

- The cascade's trigger becomes an indexed column on audit entries, written in
  the same insert as the entry, so the dependants of a delete are one indexed
  lookup.
- `POST /api/deleted/{id}/restore` restores the object and, by default,
  everything its delete cascaded to, in one transaction. `cascade: false`
  restores the object alone, as today.
- Cleared references are put back on the survivors when the field still holds
  what the delete wrote. A field someone changed since is left alone and named.
- `GET /api/deleted/{id}/restore-preview` lists what would come back, what would
  be re-linked, and what would not, with the reason per item.
- The restore records one `object.restored` entry for the root naming the
  counts, and one `object.restored` entry per dependant pointing at the root.
- Audit entries for cleared references live at least as long as the recovery
  window of the object that caused them.
- The Deleted page offers "Restore with related records" and shows the preview.

## Consumers

- pipelinq (plat-restore-deleted): a restore action on its own deleted-items
  view, calling the endpoint. That view is pipelinq's.
- dossiq, filinq and every app with cascading schemas get the same behaviour
  from Open Register's Deleted page.

## ADRs

- hydra ADR-005 (security): the caller needs `update` on every object that comes
  back; an object they may not restore refuses the act, it is not skipped.
- hydra ADR-022: apps call one restore, they do not rebuild the cascade.
- hydra ADR-058 (bounded queries): the lookup is indexed and capped.
- openregister ADR-003 (immutable audit trail): the new column is a projection
  of a sealed field, and no existing row is rewritten.
- openregister ADR-002 (organisation tenancy): only objects of the caller's
  organisation are restored.

## Impact

- Extends `deletion-audit-trail` (requirements 3 and 6).
- Affected code: a migration on `openregister_audit_trails`,
  `AuditTrailMapper::buildAuditTrail()` and
  `ReferentialIntegrityService::logIntegrityAction()`, a new
  `lib/Service/Deletion/CascadeRestoreService.php`, `DeletedController`
  (restore and a preview route), `src/views/deleted/DeletedIndex.vue`,
  `src/store/modules/deleted.js`.
- Backwards compatibility: `POST /api/deleted/{id}/restore` now also restores
  the cascade by default. Its response keeps `success` and `message` and adds
  the counts. Objects deleted before this change have no indexed trigger; for
  them the restore brings back the object alone and says so.
- Size: M.

## Out of scope

- Restoring after the recovery window, or after destruction. That is
  destruction, `delete-window-and-recorded-destruction`'s subject.
- Restoring files, notes and tasks that hang off an object without being Open
  Register objects in a cascade. Their deletion scope is declared by
  `delete-window-and-recorded-destruction`.
- Rolling records back to an earlier state (HubSpot's restore of changes). That
  is version revert, not undelete.
