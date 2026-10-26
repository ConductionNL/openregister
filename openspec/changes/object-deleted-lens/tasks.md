# Tasks

- [x] 1.1 `MagicSearchHandler::resolveDeletedMode()` with `_includeDeleted` as the legacy spelling of `any` (tests/Unit/Db/MagicMapper/MagicSearchHandlerDeletedLensTest.php).
- [x] 1.2 The single-table path, the UNION path and the facet base filters apply the mode; `_deleted` is reserved.
- [ ] 1.3 The objects endpoint returns the deleted rows: live check on :8080, `GET /api/objects/dossiq/case?_deleted=true` lists the soft-deleted cases with `@self.deleted`.
