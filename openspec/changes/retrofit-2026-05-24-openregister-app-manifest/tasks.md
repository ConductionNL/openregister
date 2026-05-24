# Tasks — Retrofit reverse-spec openregister-app-manifest (partial)

This is a **partial** reverse-spec pass. The cluster batch JSON listed 109 methods across 45 files (`/tmp/or-scan/rspec-cluster-openregister-app-manifest.json`). After triage, only **9 methods** are genuinely in scope for the `openregister-app-manifest` capability — the rest are false positives from class-name token overlap (`Register*` / `Schema*` / `*Manifest*` substring hits on unrelated CRUD / cache / GraphQL code). They are deferred below as `future-pass:next` so a downstream coverage-scan can recluster them.

## 1. Spec delta authored (5 new REQs)

- [x] 1.1 `openspec/changes/retrofit-2026-05-24-openregister-app-manifest/specs/openregister-app-manifest/spec.md` adds:
  - REQ-OR-MAN-012 Backend `/api/manifest/{appId}` endpoint returns enriched manifest
  - REQ-OR-MAN-013 Enrichment is no-op without `currentUserSchema`; anonymous request emits `runtime.user = null`
  - REQ-OR-MAN-014 Schema-slug from the manifest is validated before any lookup
  - REQ-OR-MAN-015 User-profile resolution narrows magic table by `ncUserId` with RBAC + multitenancy preserved
  - REQ-OR-MAN-016 `runtime.user` is populated from an explicit allowlist plus non-materialised calculations

## 2. Methods annotated (9 methods across 2 files)

- [x] 2.1 `lib/Controller/ManifestController.php`
  - `index` → `@spec openregister-app-manifest#REQ-OR-MAN-012`
  - `loadBundledManifest` → `@spec openregister-app-manifest#REQ-OR-MAN-012` (controller-private helper; triage note in batch JSON correctly DROPped it from MAN-005 which is the Vue-side loader, but it IS in scope for the backend endpoint requirement)
- [x] 2.2 `lib/Service/ManifestService.php`
  - `getEnrichedManifest` → `@spec openregister-app-manifest#REQ-OR-MAN-013, REQ-OR-MAN-014`
  - `resolveUserProfile` → `@spec openregister-app-manifest#REQ-OR-MAN-015`
  - `buildUserContext` → `@spec openregister-app-manifest#REQ-OR-MAN-016`
  - `getCalculations` → `@spec openregister-app-manifest#REQ-OR-MAN-016`
  - `resolveAllowedFieldNames` → `@spec openregister-app-manifest#REQ-OR-MAN-016`
  - `loadFieldAllowlist` → `@spec openregister-app-manifest#REQ-OR-MAN-016`
  - `serialise` → `@spec openregister-app-manifest#REQ-OR-MAN-016`

The pre-existing class-level `@spec openspec/changes/manifest-user-context/tasks.md` PHPDoc tag (orphan — that change doesn't exist) is left untouched at the class docblock layer to preserve historical signal. Per-method `@spec` tags are the canonical reference going forward.

## 3. Deferred — future-pass:next (100 methods)

All entries below are **FPs / out-of-capability** from the cluster batch JSON. They were swept in because their file/method names share tokens with the manifest capability (`Register`, `Schema`, …) — not because they implement anything related to `app-manifest`. A downstream coverage-scan should recluster them into the correct capability buckets (likely `openregister-registers`, `openregister-schemas`, `openregister-search`, `openregister-graphql`, `openregister-vue-shell`, …).

### 3.1 Registers CRUD controller — `future-pass:next` (likely `openregister-registers`)

- `lib/Controller/RegistersController.php`: `index`, `show`, `create`, `update`, `patch`, `destroy`, `schemas`, `objects`, `export`, `import`, `stats`, `publish`, `depublish` (13)

### 3.2 Schemas CRUD controller — `future-pass:next` (likely `openregister-schemas`)

- `lib/Controller/SchemasController.php`: `index`, `show`, `create`, `update`, `patch`, `destroy`, `related`, `stats`, `explore`, `updateFromExploration`, `publish`, `depublish` (12)

### 3.3 Register/Schema domain events — `future-pass:next`

- `lib/Event/RegisterCreatedEvent.php::__construct`
- `lib/Event/RegisterDeletedEvent.php::__construct`
- `lib/Event/RegisterUpdatedEvent.php::__construct`
- `lib/Event/SchemaCreatedEvent.php::__construct`
- `lib/Event/SchemaDeletedEvent.php::__construct`
- `lib/Event/SchemaUpdatedEvent.php::__construct`

### 3.4 GraphQL schema generation — `future-pass:next` (likely `openregister-graphql`)

- `lib/Service/GraphQL/SchemaGenerator.php::getObjectType`
- `lib/Service/GraphQL/SchemaGenerator/TypeMapperHandler.php`: `getCreateInputType`, `getUpdateInputType`, `getConnectionType`

### 3.5 Solr / search-index schema mirroring — `future-pass:next` (likely `openregister-search-index`)

- `lib/Service/Index/Backends/Solr/SolrSchemaManager.php`: `__construct`, `getFieldTypes`, `getFields`, `getSchema`
- `lib/Service/Index/SchemaHandler.php`: `__construct`, `mirrorSchemas`, `createMissingFields`, `fixMismatchedFields`
- `lib/Service/Index/SchemaMapper.php::__construct`

### 3.6 RegisterService / SchemaService — `future-pass:next` (likely `openregister-registers` / `openregister-schemas`)

- `lib/Service/RegisterService.php`: `__construct`, `find`, `findAll`, `createFromArray`, `updateFromArray`, `delete`
- `lib/Service/SchemaService.php`: `__construct`, `exploreSchemaProperties`

### 3.7 Cache handlers (register, schema, facet) — `future-pass:next` (likely `openregister-caching`)

- `lib/Service/Registers/RegisterCacheHandler.php::invalidate`
- `lib/Service/Schemas/FacetCacheHandler.php`: `clearAllCaches`, `cleanExpiredEntries`, `getCacheStatistics`
- `lib/Service/Schemas/SchemaCacheHandler.php`: `getSchema`, `clearSchemaCache`, `cacheSchema`, `cacheSchemaConfiguration`, `cacheSchemaProperties`, `invalidateForSchemaChange`, `clearAllCaches`, `cleanExpiredEntries`, `getCacheStatistics`

### 3.8 AI tool wrappers — `future-pass:next` (likely `openregister-ai-tools`)

- `lib/Tool/RegisterTool.php::__construct`
- `lib/Tool/SchemaTool.php::__construct`

### 3.9 Vue components (registers/schemas UI) — `future-pass:next` (likely `openregister-vue-shell` or per-feature)

- `src/components/cards/RegisterSchemaCard.vue`: `openEdit`, `if`
- `src/components/files-sidebar/RegisterObjectsTab.vue::fetchObjects`
- `src/components/i18n/RegisterLanguagesEditor.vue::validateDraft`
- `src/entities/register/register.mock.ts`: `mockRegisterData`, `mockRegister`
- `src/entities/schema/schema.mock.ts`: `mockSchemaData`, `mockSchema`
- `src/modals/register/DeleteRegister.vue::closeDialog`
- `src/modals/register/PublishRegister.vue`: `closeModal`, `if`
- `src/modals/schema/DeleteSchema.vue`: `initDialog`, `if`
- `src/modals/schema/DeleteSchemaObjects.vue`: `loadObjectCount`, `if`
- `src/modals/schema/DeleteSchemaProperty.vue`: `initDialog`, `if`
- `src/modals/schema/EditSchema.vue`: `loadRegistersAndSchemas`, `if`
- `src/modals/schema/EditSchemaProperty.vue::addOneOfEntry`
- `src/modals/schema/ExploreSchema.vue::handleDialogClose`
- `src/modals/schema/UploadSchema.vue::closeModal`
- `src/modals/schema/ValidateSchema.vue`: `loadObjectCount`, `if`
- `src/sidebars/register/RegisterSideBar.vue::sizeBreakdown`
- `src/sidebars/register/RegistersSideBar.vue::handleRegisterChange`
- `src/store/modules/register.js::useRegisterStore`
- `src/store/modules/schema.js::useSchemaStore`
- `src/views/register/RegisterDetail.vue`: `loadRegisterStats`, `if`
- `src/views/register/RegistersIndex.vue::handleRefresh`
- `src/views/schema/SchemaDetails.vue`: `loadSchemaStats`, `if`
- `src/views/schema/SchemasIndex.vue::handleRefresh`

## 4. Notes / observations

- **High FP rate, as expected.** Of 109 batched methods, only ~8% belong to `openregister-app-manifest`. The cluster was assembled by token overlap on file/class names; the underlying capability is small and tightly scoped (one controller, one service).
- **Orphan `@spec` reference.** Both ManifestController.php and ManifestService.php class docblocks point at `openspec/changes/manifest-user-context/tasks.md`, which does not exist on disk. That orphan change was the original home of REQ-OR-MAN-012..016; this retrofit folds them into the canonical `openregister-app-manifest` capability spec. The class-level orphan tags are left in place pending a separate cleanup pass.
- **MAN-009 drift.** The pre-existing REQ-OR-MAN-009 says the backend `/api/manifest` endpoint is "deferred". That deferral was historically accurate but the endpoint subsequently shipped. MAN-012 supersedes that deferral. A follow-up edit to MAN-009 ("deferred → delivered in MAN-012") is recommended but out of scope here — this retrofit is purely additive.
- **No behavioural drift detected.** All observed behaviour matches the docblock summaries in ManifestService.php (which were unusually thorough). No drifts to flag.
