# Proposal: the rematerialise command writes as the system

## Why

The live pass of 5 Oct (defect O7) ran `occ openregister:rematerialise-calculations learniq session`: "Touched 1385, unchanged 0, failed 1385", every row refused with "User 'Anonymous' does not have permission to 'update' objects in schema 'Session'". occ has no user session, and the command saved with the defaults (`_rbac` and `_multitenancy` on), so every schema with an authorization block (every learniq schema) refused every row. The documented back-fill for materialised calculations did nothing there, and a filter on a materialised value found nothing.

## What changes

- `RematerialiseCalculationsCommand` saves with `_rbac: false` and `_multitenancy: false`, as its reads already do. It still exits non-zero when any row fails.

## Impact

- `lib/Command/RematerialiseCalculationsCommand.php`. No API change.
