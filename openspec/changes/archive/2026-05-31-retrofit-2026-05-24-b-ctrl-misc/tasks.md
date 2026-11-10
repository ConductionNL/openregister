# Tasks — Retrofit b-ctrl-misc

Reverse-spec + annotation tasks for the miscellaneous controller bundle. Each
task maps a controller method (group) to the capability that owns its contract.
Tasks prefixed CROSS are cross-cutting annotation-only references (no new REQ;
the behavior is already owned by a live spec or in-flight change).

## ui-spa-mount → no-code-app-builder

- [x] **task-1**: Document the `UiController` SPA-mount contract — `makeSpaResponse()`
  returns the Vue `index` template with a permissive `connect-src '*'` CSP and a
  500 `error` template fallback; all 25 history-mode deep-link routes delegate to
  it so client-side Vue Router owns navigation. ONE consolidated
  no-code-app-builder ADDED REQ covering the contract and the 23 trivial route
  stubs. Annotate only `makeSpaResponse()`, `integrationsView()` (screenshot-harness
  route), and `avg()` (AVG surface) — the other 22 trivial stubs are documented
  by this REQ, not individually annotated.

## object-collaboration-attachments → object-interactions

- [x] **task-2**: Document `TasksController::allUserTasks()` — the cross-calendar
  `/api/tasks` aggregate that returns all of the session user's VTODOs across
  every VTODO-supporting calendar, with `status`/`assignee`/`_limit`/`_offset`
  filters, anchored to `IUserSession` (not request-controlled identity).
  object-interactions ADDED REQ "User-Wide Task Aggregate Endpoint".
- [x] **task-3**: Document `DeckController` (`index`/`create`/`objects`) — Nextcloud
  Deck card linkage on objects: list cards for an object, create-or-link a card,
  and reverse board-to-objects lookup; 501 `APP_NOT_AVAILABLE` when the Deck app
  is absent. object-interactions ADDED REQ "Deck Card Linkage on Objects".
- [x] **task-4 (CROSS → object-interactions existing REQs)**: Annotate
  `NotesController` (`index`/`create`/`update`/`destroy`/`validateObject`),
  `TasksController` (`index`/`create`/`update`/`destroy`/`validateObject`), and
  `TagsController` (`getAllTags`/`index`/`add`/`remove`) to the existing
  object-interactions REQs ("Notes on Objects via ICommentsManager", "Tasks on
  Objects via CalDAV VTODO", "Tags for Object Categorization", "Sub-Resource API
  Endpoint Pattern"). No new REQ — extends coverage of existing REQs. (Notes/Tasks
  already carry prior-retrofit `@spec` tags; only the un-annotated members and
  TagsController are tagged.)

## scope-rbac-api → rbac-scopes

- [x] **task-5**: Document `ScopesController::index()` — `GET /api/scopes` effective-
  scope discovery returning `{user, isAdmin, groups, scopes:[{register, schema,
  actions}]}` by probing `PermissionHandler::hasPermission()` for the five
  canonical actions per (register, schema) pair, with admin short-circuit, anon
  support, and optional `register`/`schema` filters. rbac-scopes ADDED REQ
  "Effective-Scope Discovery API". `resolveRegisters()`/`resolveSchemas()`/
  `collectActionsForUser()` are private helpers annotated under the same REQ.

## workflow-transition-tables-migration → workflow-engine-abstraction

- [x] **task-6**: Document `TransitionController` (`transition`/`availableActions`) —
  the sugar HTTP entry over `TransitionEngine` for schemas adopting
  `x-openregister-lifecycle`: action-from-body transition with
  403/422/404 error mapping, and a list of actions allowed from the current
  state. workflow-engine-abstraction ADDED REQ "Lifecycle Transition HTTP Surface".
- [x] **task-7**: Document `MigrationController` (`migrate`/`status`) — the blob ↔
  magic-table storage migration runner (`direction` validation, `batchSize`/
  `dryRun`, register/schema resolution) and the per-pair storage-status report.
  workflow-engine-abstraction ADDED REQ "Storage Migration HTTP Surface".
- [x] **task-8**: Document `TablesController` (`sync`/`syncAll`) — explicit magic-
  table synchronisation (add/de-require/drop/index columns) for one register/
  schema pair or across all pairs, returning per-pair statistics and an
  errors array. workflow-engine-abstraction ADDED REQ "Magic-Table Sync HTTP Surface".

## object-integration-dispatch → pluggable-integration-registry (annotate-only, CROSS)

- [x] **task-9 (CROSS → pluggable-integration-registry)**: `IntegrationsController`
  and `ObjectIntegrationsController` are already annotated with
  `@spec ...pluggable-integration-registry/tasks.md#task-18`/`#task-19` and owned
  by the `rspec-newcap-pluggable-integration-registry-and-providers` change. No
  new REQ and no re-annotation — recorded here for bundle completeness only. The
  bundle's `product-service-catalog` target is a redirect stub and is NOT used.
