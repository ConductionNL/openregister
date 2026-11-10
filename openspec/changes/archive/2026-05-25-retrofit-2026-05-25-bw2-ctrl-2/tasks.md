# Tasks — Retrofit bw2-ctrl-2 (Controllers chunk 2)

Reverse-spec + annotation tasks for the 28-controller / 148-method batch in
`/tmp/or-scan/bw2-ctrl-2.json`. Each task maps a controller-method group to
the capability that owns its HTTP contract. Tasks prefixed CROSS are
annotation-only references to an existing live spec / in-flight change (no new
REQ). Excludes (boilerplate) are tagged in code with `@spec exclude <reason>`
and are not individual tasks.

## generic-integrations (NEW REQ — one shared, uniform CRUD)

- [x] **task-1**: generic-integrations#REQ "Tier-2 Integration Leaf Link
  Controller Contract" — one consolidated REQ for the uniform object-scoped
  link-CRUD contract shared by `ActivityLinksController`
  (`index`/`types`/`actors`), `BookmarkLinksController`
  (`index`/`link`/`createNew`/`destroy`/`available`),
  `CospendLinksController` (`index`/`link`/`createAndLink`/`destroy`/`available`),
  `DeckLinksController`
  (`index`/`link`/`createNew`/`destroy`/`boards`/`stacks`),
  `FlowLinksController` (`index`/`link`/`destroy`/`available`),
  `FormLinksController`
  (`index`/`link`/`create`/`destroyForm`/`destroySubmission`/`available`),
  `PhotoLinksController` (`index`/`link`/`createAndLink`/`destroy`/`available`),
  `TimeTrackerLinksController`
  (`index`/`link`/`createAndLink`/`destroy`/`available`),
  `ShareLinksController` (`index`/`create`/`destroy`/`files`), and
  `EmailsController` (`index`/`create`/`destroy`). Covers list / link-existing /
  create-and-link / unlink / picker-source discovery, and the uniform
  `501 APP_NOT_AVAILABLE` graceful-degradation envelope. Per-controller
  `validateObject` / `mapException` / `unavailable` / `nullableString` /
  `nullableInt` / `dropdown` private helpers and constructors are
  `@spec exclude` boilerplate.

## data-import-export (NEW REQ — config management + Git-remote sync)

- [x] **task-2**: data-import-export#REQ "Configuration Management and
  Git-Remote Sync HTTP Surface" — `ConfigurationController`
  (`index`/`show`/`create`/`update`/`destroy`/`checkVersion`/`preview`/`enrichDetails`/`getGitHubRepositories`/`getGitHubConfigurations`/`getGitLabConfigurations`)
  and `ConfigurationsController` (`index`/`show`/`create`/`update`/`patch`/`destroy`):
  CRUD over configuration entities plus remote version-check, change preview,
  detail enrichment, and GitHub/GitLab repo+file discovery for config import.
- [x] **task-3 (CROSS → data-import-export existing "Configuration
  import/export MUST support full register portability")**: the
  `export`/`import` methods on `ConfigurationController` and
  `ConfigurationsController` are NOT in this batch — they are already
  annotated (to `retrofit-2026-05-24-b-ctrl-object-data` task-14) and remain
  governed by the existing portability REQ. Recorded here for bundle
  completeness; no edit.

## openapi-generation (NEW REQ — schema authoring + meta-entity operational)

- [x] **task-4**: openapi-generation#REQ "Schema Authoring Sub-Resources and
  Meta-Entity Operational Endpoints" — `SchemasController`
  (`download`/`upload`/`uploadUpdate`/`related`/`explore`/`updateFromExploration`),
  `RegistersController` (`schemas`/`objects` sub-resource lookups),
  `EndpointsController::test`, and `MappingsController::test` (dry-run
  validation). These are the operational/authoring verbs the registry-views
  task-1 shared resource-CRUD REQ explicitly defers to "their own capability
  specs".

## oas-generation (NEW REQ — publish / depublish surface)

- [x] **task-5**: oas-generation ADDED REQ "Register and Schema Publication and
  GitHub OAS Publishing" (a new requirement; NOT a redefinition of the existing
  `REQ-002 Generate OpenAPI Specification for a specific register`) —
  `RegistersController`
  (`publish`/`depublish`/`publishToGitHub`) and `SchemasController`
  (`publish`/`depublish`): register/schema publication lifecycle + OAS-to-GitHub
  push.

## production-observability (NEW REQ — per-entity stats + endpoint logs)

- [x] **task-6**: production-observability#REQ "Per-Entity Statistics and
  Endpoint Delivery-Log API" — `RegistersController::stats`,
  `SchemasController::stats`, and `EndpointsController`
  (`logs`/`logStats`/`allLogs`) — operational read endpoints for entity
  statistics and custom-endpoint delivery logs.

## CROSS — annotate-only to existing specs / in-flight changes

- [x] **task-7 (CROSS → openapi-generation, registry-views task-1)**:
  meta-entity resource CRUD — `RegistersController`, `SchemasController`,
  `EndpointsController`, `MappingsController`
  (`index`/`show`/`create`/`update`/`patch`/`destroy`) annotated to the shared
  resource-CRUD REQ added by `retrofit-2026-05-24-b-ctrl-registry-views`
  task-1.
- [x] **task-8 (CROSS → faceting-configuration, registry-views task-2/3)**:
  `ViewsController` (`index`/`show`/`create`/`update`/`patch`/`destroy`)
  annotated to the persisted-views CRUD REQ.
- [x] **task-9 (CROSS → urn-resource-addressing, registry-views task-7)**:
  `UrnController` (`resolve`/`lookup`/`bulk`) annotated to the URN resolver
  contract.
- [x] **task-10 (CROSS → data-import-export existing REQs)**:
  `RegistersController` (`import`/`export`/`importTemplate`/`rollbackImport`)
  annotated to the import/export + rollback REQs.
- [x] **task-11 (CROSS → pluggable-integration-registry task-18/19)**:
  `IntegrationsController` (`index`/`show`) and `ObjectIntegrationsController`
  (`index`/`show`/`create`/`update`/`destroy`) — method-level tags added (the
  classes were already tagged).
- [x] **task-12 (CROSS → object-interactions "File Attachments on Objects" /
  "Tags for Object Categorization")**: `FilesController`
  (`show`/`save`/`createMultipart`/`update`/`depublish`/`downloadById`/`batch`/`updateLabels`).
- [x] **task-13 (CROSS → object CRUD, retrofit annotate-openregister)**:
  `ObjectsController::postPatch` (multipart-PATCH object update). SECURITY
  NOTE: `@PublicPage` + `@NoAdminRequired`.
- [x] **task-14 (CROSS → referential-integrity)**:
  `ObjectsController::canDelete` (pre-flight deletion analysis; the REQ
  scenarios already reference `canDelete()` / `DeletionAnalysis`).
- [x] **task-15 (CROSS → add-time-bucket-aggregation task 2.1)**:
  `AggregationController::timeseries`.
- [x] **task-16 (CROSS → contacts-actions task-1)**:
  `ContactsController::createNew`.
- [x] **task-17 (CROSS → workflow-operations "Scheduled Workflow Triggers")**:
  `ScheduledWorkflowController` (`index`/`show`/`create`/`update`/`destroy`).
  SECURITY NOTE: `@NoAdminRequired` on all verbs; controller calls mapper
  directly (ADR-003 layering violation).
- [x] **task-18 (CROSS → production-observability "Prometheus Metrics
  Endpoint" / "Health Check Endpoint")**: `MetricsController::index`,
  `HealthController::index`.
- [x] **task-19 (CROSS → chat-ai, class-level)**: `AgentsController::page` is
  an SPA-mount template stub — `@spec exclude` (the agent CRUD surface is
  covered at class level by chat-ai / registry-views).
