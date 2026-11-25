# Tasks

- [x] 1.1 `MagicSearchHandler::resolveDeletedMode()` with `_includeDeleted` as the legacy spelling of `any` (tests/Unit/Db/MagicMapper/MagicSearchHandlerDeletedLensTest.php).
- [x] 1.2 The single-table path, the UNION path and the facet base filters apply the mode; `_deleted` is reserved.
- [x] 1.3 The objects endpoint returns the deleted rows: live check on :8080, `GET /api/objects/dossiq/case?_deleted=true` lists the soft-deleted cases with `@self.deleted`.
  > 2026-11-25, live on a sandbox Nextcloud 34 on PostgreSQL 16 (main 55f2aa3e), with a register `lens` and schema
  > `case` of its own instead of dossiq's (the lens is generic; dossiq is not installed there): three cases, one
  > soft-deleted (`DELETE` 204). `GET /api/objects/lens/case` answers 2 (the live two); `?_deleted=true` answers 1,
  > the deleted case, with `@self.deleted` carrying `deletedAt`, `deletedBy`, `purgeDate` and the retention;
  > `?_deleted=any` and the legacy `?_includeDeleted=true` answer all 3; `?_deleted=false` answers the live 2.
