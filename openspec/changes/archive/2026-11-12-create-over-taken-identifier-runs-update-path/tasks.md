# Tasks: create-over-taken-identifier-runs-update-path

- [x] 1.1 `insertObjectEntity()` holds the creating refusal (`runCreatingHooks()`), and `updateRefusedCreate()` writes an update over a taken identifier or throws the refusal.
- [x] 1.2 Tests: a refused create over a taken identifier, of a free identifier, a refused claim, and hook data written.
- [x] 1.3 The re-read before the update refuses a refused create whose row went away or was soft-deleted in between; a lost claim (no refusal) still updates a soft-deleted row as before.
- [x] 1.4 The update of a refused create is a conditional write: `AND _deleted IS NULL`, no insert fallback, the creating refusal on 0 rows. A lost claim without a refusal is unchanged.
- [x] 1.5 A write of the same uuid nested inside the update's events carries only its own refusal and restores the outer one when it ends, so a soft delete after it cannot be overwritten.
