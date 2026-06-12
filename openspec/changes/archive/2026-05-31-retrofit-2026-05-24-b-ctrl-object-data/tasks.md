# Tasks — Retrofit b-ctrl-object-data

Reverse-spec + annotation tasks for the object/data controller bundle. Each task
maps a controller method group to the capability that owns its contract. Tasks
prefixed CROSS are cross-cutting annotation-only (no new REQ; point at the
existing owning capability).

## object-rest-api → object-lifecycle

- [x] **task-1**: Document `ObjectsController::objects()` collection-listing REST
  contract (magic-mapper router, cross-table search, `_empty` stripping,
  paginated envelope). object-lifecycle ADDED REQ "object REST list endpoint".
- [x] **task-2**: Document `ObjectsController::show()` single-object read contract
  (slug resolution, 404 envelope, extend/filter params). object-lifecycle ADDED
  REQ "object REST read endpoint".
- [x] **task-3**: Document `ObjectsController::patch()` partial-update contract
  (merge with existing data via `findSilent`, admin RBAC/multitenancy toggle,
  append-only 405, validation 422, post-save unlock). object-lifecycle ADDED REQ
  "object REST patch endpoint".
- [x] **task-4**: Document `ObjectsController::lock()` / `unlock()` optimistic-lock
  contract (process/duration params, `locked` flag, 404/500 envelopes).
  object-lifecycle ADDED REQ "object lock/unlock endpoints".
- [x] **task-5**: Document `ObjectsController::merge()` object-merge contract
  (target + object payload validation, 400/404/500 envelopes, `set_time_limit(0)`).
  object-lifecycle ADDED REQ "object merge endpoint".
- [x] **task-6**: Document `ObjectsController::contracts()` / `uses()` / `used()`
  relation sub-resource contracts (paginated relation traversal, A→B / B→A
  semantics). object-lifecycle ADDED REQ "object relation sub-resource endpoints".
- [x] **task-7**: Document `ObjectsController::logs()` audit-log sub-resource
  contract (register/schema ownership match, paginated logs, 404 on mismatch).
  object-lifecycle ADDED REQ "object audit-log sub-resource endpoint".
- [x] **task-8**: Document `ObjectsController::validate()` bulk-validation trigger
  and `clearBlob()` retired endpoint contracts. object-lifecycle ADDED REQ
  "object bulk-validation and retired-blob endpoints".

## object-cross-cutting → owning capability (annotate-only, CROSS)

- [x] **task-9 (CROSS → chat-ai)**: Annotate `ObjectsController::vectorizeBatch()`,
  `getObjectVectorizationStats()`, `getObjectVectorizationCount()`. Vector/RAG
  pipeline; covered by chat-ai (REQ-001 RAG context). No new REQ.
- [x] **task-10 (CROSS → files-render-extension)**: Annotate
  `ObjectsController::downloadFiles()`. Object file-output (bulk ZIP) surface;
  governed by the files-render-extension attached-files model. No new REQ.
- [x] **task-11 (CROSS → data-import-export)**: Annotate
  `ObjectsController::import()` and `export()`. These are the exact controller
  endpoints data-import-export already names (`ObjectsController::import()` /
  `ObjectsController::export()`). No new REQ.
- [x] **task-12 (CROSS → workflow-engine-abstraction)**: Annotate
  `ObjectsController::migrate()`. Object register/schema migration with property
  mapping; the object-mutation operation the workflow/import machinery drives.
  No new REQ.

## configuration-api → data-import-export

- [x] **task-13**: Document the configuration remote-portability surface:
  `ConfigurationController::discover()`, `getGitHubBranches()`,
  `getGitLabBranches()`, `importFromGitHub()`, `importFromGitLab()`,
  `importFromUrl()`, `publishToGitHub()` (+ private publish helpers). One new
  data-import-export ADDED REQ "configuration GitHub/GitLab/URL publishing and
  discovery".
- [x] **task-14**: Annotate `ConfigurationController::import()` / `export()` and
  `ConfigurationsController::export()` / `import()` to the existing
  data-import-export configuration-portability REQ ("Configuration import/export
  MUST support full register portability"). No new REQ — extends coverage of an
  existing REQ.

## bulk-data-ops → data-import-export

- [x] **task-15**: Document `BulkController::deleteSchema()`,
  `deleteSchemaObjects()`, `deleteRegister()` mass-delete-by-scope contract
  (numeric-ID / slug resolution, `hardDelete` flag, deleted_count/uuids
  envelope). One new data-import-export ADDED REQ "bulk delete objects by
  register/schema". `resolveRegisterSchemaIds` is a private helper — annotate
  only.

## soft-delete-recovery-api → deletion-audit-trail (annotate-only)

- [x] **task-16**: Annotate `DeletedController::statistics()` and `topDeleters()`
  to deletion-audit-trail REQ-11 (the trash API "statistics and top deleter
  analytics" requirement which names these endpoints). No new REQ —
  `index`/`restore`/`restoreMultiple`/`destroy`/`destroyMultiple` are already
  annotated by prior retrofit tasks.
