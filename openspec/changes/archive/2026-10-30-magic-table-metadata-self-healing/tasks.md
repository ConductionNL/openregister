# Tasks: magic-table-metadata-self-healing

- [x] 1.1 `MagicMetadataColumnHealer` with `inspect()`, `heal()`, `ensure()`, `missingFor()` and `forget()` (D-2, D-3, D-4).
- [x] 1.2 `existsTableForRegisterSchema()` calls `ensure()` when a table exists (D-1).
- [x] 1.3 The search handler leaves a missing `_archived` or `_status` out of the lifecycle predicates on the QueryBuilder and the UNION paths (D-4).
- [x] 2.1 `openregister:tables:reconcile` reports and, with `--apply`, adds missing metadata columns on every physical magic table, including orphaned ones (D-5).
- [x] 3.1 `AddObjectStateColumns` walks every physical magic table through the healer (D-5).
- [x] 4.1 Unit tests against SQLite: first use heals and the archive predicate then runs; memo and distributed cache prevent a second inspection; a concurrent add is tolerated; a failure warns once and degrades; reconcile reports and applies; the repair step heals an orphaned table.
