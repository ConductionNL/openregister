# Tasks — Retrofit bw2-ctrl-1 (Controllers, chunk 1)

Reverse-spec + annotation tasks for controller bundle chunk 1 (27 files, 148
methods). Each task maps a controller method group to the capability that owns
its contract. Tasks prefixed CROSS are annotation-only references (no new REQ;
behavior already owned by a live spec or an archived retrofit change).

## generic-integrations (1 new REQ)

- [x] **task-1**: Document the shared object-scoped integration-link REST
  contract — `GET` list / `POST` link-existing / `POST` create-and-link /
  `DELETE` unlink under `/api/objects/{register}/{schema}/{id}/{provider}`, plus
  provider picker-source endpoints under `/api/integrations/{provider}/...`,
  with the `501 APP_NOT_AVAILABLE` availability gate, `{results,total}`
  envelope, `validateObject()` 404 path, and `mapException()` HTTP-code mapping.
  ONE ADDED generic-integrations REQ "Object-Scoped Integration Link REST
  Contract". Annotate the public methods of `AnalyticsLinksController`
  (`index`/`link`/`createAndLink`/`destroy`/`available`),
  `CollectiveLinksController`
  (`index`/`link`/`createAndLink`/`destroy`/`available`/`collectives`),
  `MapLinksController` (`index`/`link`/`createAndLink`/`destroy`/`available`),
  `OpenProjectLinksController`
  (`index`/`link`/`createAndLink`/`destroy`/`available`),
  `PollLinksController` (`index`/`link`/`createNew`/`destroy`/`available`),
  `TalkLinksController` (`index`/`link`/`createNew`/`destroy`/`rooms`),
  `XwikiLinksController` (`index`/`link`/`createAndLink`/`destroy`/`available`),
  and `EmailLinksController`
  (`index`/`link`/`destroy`/`accounts`/`mailboxes`/`messages`). Per-controller
  `validateObject()` / `mapException()` are private helpers → `@spec exclude`.

## search-index (1 new REQ)

- [x] **task-2**: Document the file text extraction + indexing HTTP surface —
  per-file and bulk text extraction, chunk indexing to the search backend,
  extraction/chunking statistics, and PII anonymisation. ONE ADDED search-index
  REQ "File Text Extraction and Indexing HTTP Surface". Annotate
  `FileTextController`
  (`getFileText`/`extractFileText`/`bulkExtract`/`getStats`/`deleteFileText`/
  `processAndIndexExtracted`/`processAndIndexFile`/`getChunkingStats`/
  `anonymizeFile`), `FileSearchController` (`semanticSearch`/`hybridSearch`),
  `FileSidebarController` (`getObjectsForFile`/`getExtractionStatus`), and
  `FileSettingsController` (`getFileSettings`/`updateFileSettings`/
  `getFileCollectionFields`/`createMissingFileFields`/`warmupFiles`/`indexFile`/
  `reindexFiles`/`getFileIndexStats`/`getFileExtractionStats`/
  `testDolphinConnection`/`testPresidioConnection`/`testOpenAnonymiserConnection`).
  Private helpers (`performHealthCheck`, `fetchPresidioCapabilities`) →
  `@spec exclude`.

## zoeken-filteren (1 new REQ)

- [x] **task-3**: Document the search-trail analytics + audit API —
  `SearchTrailController` list/show, aggregate statistics, popular terms,
  activity time-series, per-register/schema stats, user-agent stats, CSV/JSON
  export, and cleanup / single-delete / clear-all retention operations. ONE
  ADDED zoeken-filteren REQ "Search Trail Analytics and Audit API". Annotate
  the uncovered public methods (`index`/`show`/`statistics`/`popularTerms`/
  `activity`/`registerSchemaStats`/`userAgentStats`/`cleanup`/`export`/
  `destroy`/`clearAll`). `extractRequestParameters`/`paginate`/`arrayToCsv`
  private helpers → `@spec exclude`.

## CROSS — annotation-only against existing / archived REQs (no new REQ)

- [x] **task-4 (CROSS → no-code-app-builder)**: `UiController`'s 21 history-mode
  SPA-mount route stubs (`registers`/`registersDetails`/`schemas`/
  `schemasDetails`/`sources`/`organisation`/`objects`/`tables`/`chat`/
  `configurations`/`deleted`/`auditTrail`/`searchTrail`/`webhooks`/
  `webhooksLogs`/`entities`/`entitiesDetails`/`reports`/`reportView`/
  `endpoints`/`endpointLogs`) are trivial `return $this->makeSpaResponse();`
  passthroughs. Contract owned by `no-code-app-builder` (consolidated SPA-mount
  REQ, `retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-1`). Per ADR-003
  thin-passthrough rule → `@spec exclude` (reason cites the owning REQ).
- [x] **task-5 (CROSS → registry-resource-crud)**: `ApplicationsController`,
  `ConsumersController`, `SourcesController`
  `index`/`show`/`create`/`update`/`patch`/`destroy` → existing shared
  registry-resource-CRUD REQ (`retrofit-2026-05-24-b-ctrl-registry-views/tasks.md#task-1`;
  the classes already point there). `ApplicationsController::page` is an
  SPA-mount stub → exclude. Pagination/param private helpers
  (`extractLimit`/`extractOffset`/`extractPage`/`getIntParam`) → exclude.
- [x] **task-6 (CROSS → chat-ai orchestrator)**: `ChatHealthController::health`
  → `ai-chat-companion-orchestrator/specs/chat-ai/spec.md#health-probe-endpoint-get-apichathealth`.
  `ChatStreamController::stream` →
  `ai-chat-companion-orchestrator/specs/chat-ai/spec.md#sse-streaming-endpoint-post-apichatstream`.
  ChatStream SSE-framing helpers (`emitSseEvent`/`emitSseHeaders`/`emitAndExit`/
  `forwardWithHeartbeat`/`clearOutputBuffers`/`now`) → `@spec exclude`.
- [x] **task-7 (CROSS → linked-entity-types)**: `LinkedEntityController`
  `addRegisterLink`/`addSchemaLink` → existing generic ad-hoc linking REQ
  (`retrofit-2026-05-24-b-ctrl-registry-views/tasks.md#task-6`).
- [x] **task-8 (CROSS → manifest-user-context)**: `ManifestController::index` →
  existing `manifest-user-context/tasks.md`; `loadBundledManifest` private →
  `@spec exclude`.
- [x] **task-9 (CROSS → notificatie-engine)**:
  `NotificationSubscriptionsController` `index`/`create`/`destroy` → existing
  `notificatie-engine/tasks.md`; `resolveUserId`/`coerceNullableInt` private →
  `@spec exclude`.
- [x] **task-10 (CROSS → webhook-payload-mapping)**: `WebhooksController::show`
  → registry-views `#task-4` (CRUD); `WebhooksController::logStats` →
  registry-views `#task-5` (delivery-log listing).
- [x] **task-11 (CROSS → workflow-engine-abstraction)**:
  `WorkflowEngineController` `show`/`update`/`destroy`/`testHook` → existing
  engine-registration/CRUD REQ
  (`retrofit-2026-04-30-annotate-openregister/tasks.md#task-91`).
- [x] **task-12 (CROSS → exclude, framework override)**:
  `GraphQLController::render` is the inline `Response` subclass's framework
  `render()` override, not a route handler → `@spec exclude`.
- [x] **task-13 (CROSS → exclude, accessors + debug)**: `SettingsController`
  `getObjectService`/`getConfigurationService` are DI service accessors (not
  routed) → `@spec exclude`; `testSchemaMapping`/`debugTypeFiltering` are
  debug/test scaffolding endpoints → `@spec exclude` (see proposal Notes).
