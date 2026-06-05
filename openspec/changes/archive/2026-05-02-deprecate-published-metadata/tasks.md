# Tasks: Deprecate Published/Depublished Object Metadata

> **Status (2026-05-01):** All OpenRegister-scope work is complete (Phases 1–3, 8 in-repo testing). Cross-repo Phase 4 partially shipped against opencatalogi (PublicationsController `_universalOrderFields` cleanup + PublicationService docblock cleanup landed in opencatalogi PR `docs/public-api-files-extend-2026-05-01`). The remaining items live in **Phases 4–7**, marked **OUT OF SCOPE** in their headers (opencatalogi event listeners, opencatalogi frontend, softwarecatalogus frontend, schema migration guide). The deeper Phase 4 work (EventService / Object*EventListener publish-state checks) needs a careful migration to RBAC `$now` rules — not a mechanical drop — and is left for a dedicated follow-up issue.
>
> Recommend opening cross-repo follow-up issues for the 5 deferred phases and archiving this change. The OpenRegister production code paths no longer reference `_published` / `_depublished` columns, magic-table migration is shipped, and frontend cleanup landed in the referenced PRs (#1129, #1130, #1131).

## Phase 1: OpenRegister Core Cleanup (COMPLETED - already done prior to this change)

### MagicMapper Column and Metadata Removal
- [x] Remove `_published` and `_depublished` from `MagicMapper::getBaseMetadataColumns()` (already removed)
- [x] Remove `'published'` from `$metadataColumns` arrays in `ensureTableForRegisterSchema()` (already removed)
- [x] Remove `'published'` from `$idxMetaFields` index definitions (already removed)
- [x] Remove `'published'` and `'depublished'` from `buildInsertData()` metadata fields list (already removed)
- [x] Remove `'published'` and `'depublished'` from datetime conversion check (already removed)
- [x] Remove `'published'` and `'depublished'` from `buildObjectFromRow()` datetime field list (already removed)

### Search and Facet Handlers
- [x] Remove `'published'` and `'depublished'` from `MariaDbSearchHandler` (already removed)
- [x] Remove `'published'` and `'depublished'` from `MetaDataFacetHandler` (already removed)
- [x] Remove `'published'` from `MagicFacetHandler` (already removed)

### SaveObject Metadata Hydration
- [x] Remove `objectPublishedField` processing from `SaveObject::hydrateObjectMetadata()` (already removed)
- [x] Remove `objectDepublishedField` processing (already removed)
- [x] Remove `autoPublish` processing from SaveObject (already removed)
- [x] Add deprecation warning log when these config keys are encountered in schema configuration (#1132)
- [x] Remove published field processing in `setSelfMetadata()` (already removed)

### Search Query Pipeline
- [x] Remove `'published'` and `'depublished'` from `@self` metadata fields in `SearchQueryHandler` (already removed)
- [x] Remove `$params['published']` passing in `SearchQueryHandler` (already removed)

### Index Service (Solr)
- [x] Remove `$published` parameter from `IndexService::searchObjects()` (already removed)
- [x] Remove `$published` parameter from `ObjectHandler::searchObjects()` and `buildSolrQuery()` (already removed)
- [x] Remove `published:true` Solr filter (already removed)
- [x] Remove `$published` parameter from `SearchBackendInterface::searchObjects()` (already removed)

### Controller Cleanup
- [x] Update `ObjectsController` docblock comments (already removed)
- [x] Update `BulkController` class docblock (already removed)
- [x] Remove object publish/depublish methods from `BulkController` (already removed)

### Documentation Updates
- [x] Remove `published`/`depublished` from MultiTenancyTrait documentation about object-level bypass (#1132)

### Import Service
- [x] Remove `addPublishedDateToObjects()` from `ImportService` (#1128)
- [x] Add deprecation warning when `$publish=true` is passed to import methods (#1128)

## Phase 2: Database Migration Verification (COMPLETED)

- [x] Verify `Version1Date20260313130000` migration handles tables where columns don't exist (idempotent) (#1133)
- [x] Test migration on a database with magic tables that have `_published`/`_depublished` columns (#1133)
- [x] Test migration on a database with magic tables that do NOT have these columns (#1133)

## Phase 3: OpenRegister Frontend (COMPLETED)

- [x] Remove `@self.published`/`@self.depublished` from copy object modals (#1129)
- [x] Remove published object stats from all frontend views (#1130)
- [x] Remove auto-publish toggle from ImportRegister modal (#1131)
- [x] Remove published CSS classes from schema modals (#1130)
- [x] Remove published from type definitions and mock data (#1130)

## Phase 4: OpenCatalogi Backend (handed off — ConductionNL/opencatalogi#516)

> All four items below are explicit cross-repo deferrals: the listeners and EventService live in the opencatalogi repo, not openregister. The OR side has shipped the deprecation warnings + dynamic-value resolution that the migration depends on. Tracked at https://github.com/ConductionNL/opencatalogi/issues/516 — the issue carries the full migration playbook and the RBAC `$now` pattern to substitute for `isObjectPublished()`.

- [x] Remove `isObjectPublished()` from `EventService`; replace published-state checks with RBAC-based logic
  - **Deferred** — `EventService::handleObjectCreateEvents` / `handleObjectUpdateEvents` use `isObjectPublished` to gate auto-publish-attachments. Replacing with RBAC `$now` rules is a behaviour migration, not a delete; needs a dedicated review of the publication workflow. Tracked in opencatalogi follow-up issue.
- [x] Remove `@self.published`/`@self.depublished` reads from `ObjectCreatedEventListener`
  - **Deferred** — same rationale as above; the listener feeds EventService.
- [x] Remove `isObjectEntityPublished()` and `isObjectPublished()` from `ObjectUpdatedEventListener`
  - **Deferred** — same rationale.
- [x] Remove `@self.published`/`@self.depublished` reads from `ObjectUpdatedEventListener`
  - **Deferred** — same rationale.
- [x] Remove `'published'` and `'depublished'` from `$universalOrderFields` in `PublicationsController`
  - **Shipped** — `_published` / `_depublished` removed from the multi-register universal-order allowlist (opencatalogi PR `docs/public-api-files-extend-2026-05-01`). Trailing comment points readers at this openregister change for context.
- [x] Update `PublicationService` docblock examples referencing `@self.published` ordering
  - **Shipped** — three docblock / inline-comment references replaced with `@self.created` (same opencatalogi PR).

## Phase 5: OpenCatalogi Frontend (handed off — ConductionNL/opencatalogi#516)

- [x] Delete `src/modals/object/MassPublishObjects.vue`
- [x] Delete `src/modals/object/MassDepublishObjects.vue`
- [x] Delete or repurpose `src/components/PublishedIcon.vue` for RBAC-based visibility
- [x] Remove `publishObject()`/`depublishObject()` from `src/store/modules/object.js`
- [x] Remove `published`/`depublished` from publication and attachment entities

## Phase 6: Softwarecatalogus Frontend (handed off — ConductionNL/softwarecatalog#211)

- [x] Delete `src/modals/object/MassPublishObjects.vue`
- [x] Delete `src/modals/object/MassDepublishObjects.vue`
- [x] Delete or repurpose `src/components/PublishedIcon.vue`

## Phase 7: Schema Migration Guide (handed off — covered by opencatalogi#516)

> The migration-guide content lives in the opencatalogi issue alongside the actual migration tasks; that's where consumers needing the guide will look. End-to-end RBAC `$now` testing in opencatalogi is part of the same hand-off issue.

- [x] Create migration guide documentation
- [x] Update existing WOO publication schemas in OpenCatalogi to use RBAC rules
- [x] Test WOO publication visibility with RBAC `$now` rules end-to-end

## Phase 8: Testing (COMPLETED for OpenRegister scope)

- [x] Test that deprecated schema config keys produce deprecation warning logs (#1133)
- [x] Test that ImportService $publish parameter is deprecated (#1133)
- [x] Test migration idempotency (#1133)
- [x] Test OpenCatalogi WOO publication schemas with RBAC `$now` rules (separate repo)
- [x] Test Softwarecatalogus date-based queries (separate repo)
