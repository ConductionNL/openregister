# Tasks: create-over-taken-identifier-runs-update-path

- [x] 1.1 `insertObjectEntity()` holds the creating refusal (`runCreatingHooks()`), and `updateRefusedCreate()` writes an update over a taken identifier or throws the refusal.
- [x] 1.2 Tests: a refused create over a taken identifier, of a free identifier, a refused claim, and hook data written.
- [x] 1.3 The re-read before the update refuses a refused create whose row went away or was soft-deleted in between; a lost claim (no refusal) still updates a soft-deleted row as before.
