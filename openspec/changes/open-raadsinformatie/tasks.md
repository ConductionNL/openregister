# Tasks — Open Raadsinformatie Adapter Integration

## 1. Backend — ORI Adapter Implementation

- [ ] 1.1 Create `lib/Service/ORI/OriAdapterFactory.php` — factory for instantiating source adapters. Takes a source config object and returns an `OriSourceAdapter` instance configured for the ORI endpoint (endpoint URL, auth method, entity type).
- [ ] 1.2 Create `lib/Service/ORI/OriSourceAdapter.php` — base adapter class extending `ExternalSourceAdapter`. Implements: `authenticate(SourceConfig): AuthToken`, `discover(): EntityDefinition[]`, `pull(EntityDefinition, since?: DateTime): EntitySet`, `validate(RegisterObject[]): ValidationResult[]`.
- [ ] 1.3 Implement `OriSourceAdapter::authenticate()` — read ORI endpoint and auth credentials from OCP vault (`OCP\ISecureRandom` for token generation; `OCP\IConfig` for vault reference). Support token, OAuth2, and basic auth methods.
- [ ] 1.4 Implement `OriSourceAdapter::discover()` — call the ORI endpoint's `/api/entity-types` (or equivalent) endpoint to enumerate available entity types (e.g., stukken, besluiten). Cache the result for 1 hour.
- [ ] 1.5 Implement `OriSourceAdapter::pull()` — fetch entities from ORI using the source's entity type. Implement pagination (use server-supplied cursor or timestamp). On each request, pass the cursor parameter so ORI returns only new/modified entities since last sync. Return an `EntitySet` with: `entities: Entity[]`, `cursor: string`, `hasMore: bool`.
- [ ] 1.6 Implement `OriSourceAdapter::map()` — translate ORI entity fields to OpenRegister object properties. For each ORI entity: create a corresponding array (map to `ObjectService` format); store ORI URL in `_sourceUrl` metadata; include original ORI entity ID in `_sourceId`. Return an array of object arrays.
- [ ] 1.7 Implement `OriSourceAdapter::validate()` — call `SchemaValidator` to validate each mapped object against the target register's schema. Collect validation errors per object. Return `ValidationResult[]` with detailed error messages.
- [ ] 1.8 Create `lib/Service/ORI/OriSyncService.php` — orchestrate the sync cycle: load source config, authenticate, pull entities (with cursor resume), map to objects, validate, persist via `ObjectService::saveObject()`, store sync metadata in register. On error, retry with exponential backoff (max 60s). On persistent failure (after 3 retries), mark source as paused and log alert.
- [ ] 1.9 Create `lib/Service/ORI/OriSyncJob.php` — Nextcloud background job that calls `OriSyncService::syncSource()` for each active ORI source. Job runs at the source's configured `sync_interval` frequency.

## 2. Database — ORI Source Configuration Schema

- [ ] 2.1 Create migration file `lib/Migration/Version<timestamp>_CreateOriSourcesTable.php`. Creates `oc_openregister_ori_sources` table with columns:
  - `id` (INT PRIMARY KEY AUTO_INCREMENT)
  - `name` (VARCHAR 255)
  - `endpoint` (VARCHAR 1024)
  - `entity_type` (VARCHAR 100)
  - `register_slug` (VARCHAR 255, FK to oc_openregister_registers)
  - `auth_method` (ENUM: token, oauth2, basic)
  - `auth_credentials_vault_key` (VARCHAR 255, references OCP vault)
  - `sync_interval` (INT, seconds, default 3600)
  - `last_sync_at` (DATETIME nullable)
  - `last_sync_cursor` (TEXT nullable)
  - `sync_status` (ENUM: active, paused, error)
  - `created_at` (DATETIME)
  - `updated_at` (DATETIME)
  - Indexes: (register_slug), (sync_status)
- [ ] 2.2 Create migration file `lib/Migration/Version<timestamp>_CreateOriSyncHistoryTable.php`. Creates `oc_openregister_ori_sync_history` table:
  - `id` (INT PRIMARY KEY AUTO_INCREMENT)
  - `ori_source_id` (INT, FK to oc_openregister_ori_sources)
  - `sync_at` (DATETIME)
  - `status` (ENUM: success, failure)
  - `entities_fetched` (INT)
  - `objects_created` (INT)
  - `objects_updated` (INT)
  - `objects_skipped` (INT)
  - `validation_errors` (JSON, array of error objects)
  - `error_message` (TEXT nullable)
  - `cursor_next` (TEXT)
  - Indexes: (ori_source_id, sync_at)

## 3. Admin UI — ORI Data Sources Management

- [ ] 3.1 Create `src/components/OriSourcesAdmin.vue` — main admin page listing registered ORI sources. Features:
  - Table with columns: name, endpoint, entity type, target register, status (badge: active/paused/error), last sync, next sync, actions (edit, sync now, pause, delete)
  - "Add Source" button that opens a modal/form
  - Search/filter by name or register
  - Sync history sidebar (shows last 5 syncs for selected source)
- [ ] 3.2 Create `src/components/OriSourceForm.vue` — form for adding or editing an ORI source. Fields:
  - name (text input, required)
  - endpoint (URL input, required, validate reachability)
  - auth_method (dropdown: token, oauth2, basic)
  - auth_credentials (sensitive text input, stored in vault)
  - entity_type (dropdown, populated by calling adapter.discover() after auth succeeds)
  - register_slug (dropdown, list existing registers, required)
  - sync_interval (number input, seconds, default 3600)
  - Submit/Cancel buttons
  - On submit, create or update the source via API
- [ ] 3.3 Create `src/components/OriSourceDetail.vue` — detail view for a single source:
  - Display source configuration (read-only)
  - "Sync Now" button (triggers immediate sync, shows progress)
  - "Pause/Resume" toggle
  - "Edit" button (opens form)
  - "Delete" button (with confirmation)
  - Sync history table (last 10 syncs): timestamp, status (badge), entities, objects created/updated, errors
  - On error, show error details and "retry" button
- [ ] 3.4 Wire components into the Integrations / External adapters sub-page. Route: `/admin/apps/openregister/integrations/ori`.
- [ ] 3.5 Use Nextcloud design system (NcButton, NcTable, NcModal, NcBreadcrumbs) and dark-mode-aware styling.

## 4. API — ORI Source REST Endpoints

- [ ] 4.1 Create `lib/Controller/AdminApi/OriSourcesController.php` with endpoints:
  - `GET /admin/ori-sources` — list all ORI sources with metadata
  - `POST /admin/ori-sources` — create a new source (validates endpoint connectivity and auth before storing)
  - `PUT /admin/ori-sources/{id}` — update source config
  - `DELETE /admin/ori-sources/{id}` — delete source (removes from table and background job)
  - `POST /admin/ori-sources/{id}/sync` — trigger immediate sync (async, returns job ID)
  - `POST /admin/ori-sources/{id}/pause` — pause sync
  - `POST /admin/ori-sources/{id}/resume` — resume sync
  - `GET /admin/ori-sources/{id}/sync-history` — list last N sync results
- [ ] 4.2 All endpoints require `admin` capability (use `OCP\AppFramework\Controller` + `@AdminRequired` annotation).
- [ ] 4.3 On create/update, validate request body: endpoint is reachable, auth succeeds, entity_type is valid (via discover), register_slug exists.
- [ ] 4.4 Respond with 400 Bad Request if validation fails, 201/204 on success, 404 if resource not found.

## 5. Integration — Background Job & Sync Lifecycle

- [ ] 5.1 Register `OriSyncJob` in `appinfo/info.xml` as a background job class.
- [ ] 5.2 On source creation, schedule the first run immediately (so admin sees a quick sync result). Subsequent runs follow `sync_interval`.
- [ ] 5.3 Store sync metadata in register's `_syncSource`, `_syncCursor`, `_syncStatus`, `_syncEntitiesCount`, `_syncObjectsCreated`, `_syncObjectsUpdated`, `_syncValidationErrors`, `_nextSyncAt` fields.
- [ ] 5.4 On sync success, update `_syncStatus: success`, increment object counts, clear error list, store next-sync timestamp.
- [ ] 5.5 On sync failure, update `_syncStatus: error`, store error message and details, retry with backoff. After 3 failed retries, set `sync_status: paused` in the database and alert the admin (log `warn`, optional Nextcloud notification).
- [ ] 5.6 Ensure synced objects are queryable via `ObjectService::getObjects()` (they are regular OpenRegister objects; no special handling needed).

## 6. Integration — opencatalogi & softwarecatalog

- [ ] 6.1 No API changes needed; ORI-sourced objects are regular OpenRegister objects and are automatically discoverable by dependent apps.
- [ ] 6.2 Verify that opencatalogi can query ORI-sourced objects via `GET /apps/openregister/api/v1/objects?register=<ori-register>`.
- [ ] 6.3 Verify that softwarecatalog's existing cross-app linking works with ORI objects (if it references an OpenRegister register by slug).

## 7. Tests

- [ ] 7.1 `tests/Unit/Service/ORI/OriAdapterFactoryTest.php` — instantiate factory, call `createAdapter()` with config, assert adapter type and endpoint are set.
- [ ] 7.2 `tests/Unit/Service/ORI/OriSourceAdapterTest.php` — mock ORI API endpoints:
  - `authenticate()` succeeds and returns a token
  - `discover()` returns entity types
  - `pull()` returns entities and cursor; subsequent call with cursor returns only new entities
  - `map()` translates ORI fields to object properties, stores `_sourceUrl` and `_sourceId`
  - `validate()` reports validation errors for invalid objects
- [ ] 7.3 `tests/Unit/Service/ORI/OriSyncServiceTest.php` — mock adapter and `ObjectService`:
  - `syncSource()` calls adapter methods in sequence (authenticate, pull, map, validate, save)
  - On object save, increment created/updated counts
  - On validation error, skip object and record error
  - Store sync result in register metadata
  - On adapter error, retry with backoff
  - On persistent failure, mark source as paused
- [ ] 7.4 `tests/Integration/ORI/OriAdapterIntegrationTest.php` — integration test with mock ORI endpoint (use `\PHPUnit\Framework\TestCase` + `OCP\TestUtil`):
  - Full sync cycle: create source, run sync, verify objects are persisted, verify metadata is updated, verify cursor is stored
  - Incremental sync: run sync twice, verify second sync only fetches new entities
  - Validation failure: sync invalid entity, verify error is recorded and object is skipped
- [ ] 7.5 `tests/Functional/AdminApi/OriSourcesControllerTest.php` — test API endpoints:
  - POST create source, assert 201 and source ID returned
  - GET list sources, assert 200 and source data
  - PUT update source, assert 204
  - POST trigger sync, assert 200 and job ID
  - DELETE source, assert 204
  - Verify admin-only checks (non-admin requests get 403)
- [ ] 7.6 `tests/Functional/Integration/OriOpenCatalogiIntegrationTest.php` — verify opencatalogi can query ORI-sourced objects:
  - Sync ORI data into a register
  - Call opencatalogi's `/api/v1/objects` endpoint with `register=<ori-register>`
  - Assert objects are returned and `_sourceUrl` is present
- [ ] 7.7 Run full test suite and ensure no regressions in existing tests (especially ObjectService and register-related tests).

## 8. Quality Gates

- [ ] 8.1 PHPCS strict — clean on all new files under `lib/Service/ORI/` and `lib/Controller/AdminApi/`.
- [ ] 8.2 PHPMD strict — target `@SuppressWarnings` on `TooManyMethods` (OriSyncService may be method-heavy) and `ElseExpression` (retry logic).
- [ ] 8.3 Psalm strict — no errors on new files.
- [ ] 8.4 PHPStan level 8 — no errors on new files.
- [ ] 8.5 Vue/TypeScript linter — clean on new components (`src/components/OriSourcesAdmin.vue`, etc.).
- [ ] 8.6 Unit + Functional test suite — all green, >80% code coverage for ORI service layer.

## 9. Documentation

- [ ] 9.1 Create `docs/integrations/ori-adapter.md` — admin guide:
  - Overview of ORI integration
  - Step-by-step: add ORI source, configure entity type, watch first sync
  - Troubleshooting: auth failures, validation errors, sync history
  - Performance notes: recommended sync intervals for large data sources
  - Limitations: read-only sync, per-source register requirement
- [ ] 9.2 Create `docs/technical/ori-adapter-architecture.md` — developer reference:
  - Architecture diagram: ORI API → adapter → OpenRegister objects → dependent apps
  - Adapter lifecycle: authenticate, discover, pull, map, validate, persist
  - Entity mapping: how ORI fields translate to register properties and metadata
  - Sync metadata schema
  - Error handling and retry policy
- [ ] 9.3 Update `openspec/platform-capabilities.md` — external integrations section:
  - Add "ori-adapter" capability: pull ORI entities, schedule sync, validate against schema, transparent to opencatalogi/softwarecatalog
- [ ] 9.4 Update `CHANGELOG.md` — note the new ORI adapter capability and sub-page placement.

## 10. Handoff & Cross-Project Coordination

- [ ] 10.1 Coordinate with Procest team on the canonical ORI spec (`procest/openspec/specs/open-raadsinformatie/spec.md`). Ensure that any entity field mappings or naming conventions in this adapter align with Procest's schema contracts.
- [ ] 10.2 Test with opencatalogi and softwarecatalog to verify no regressions (they should be able to query ORI-sourced objects without code changes).
- [ ] 10.3 Document the cross-project federation approach in `docs/integrations/ori-adapter.md` for future integrators.
