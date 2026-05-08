# Deprecate Published/Depublished Object Metadata

## Why

The dedicated `published` / `depublished` object metadata fields predate the RBAC `$now` dynamic variable. RBAC `$now` rules now express the same "is this object visible right now" semantics in a way that scales across schemas and tenants without bespoke metadata columns, magic-table indexes, or specialised search/facet code paths. Keeping the legacy fields means duplicate logic across `MagicMapper`, `SaveObject`, `SearchQueryHandler`, `IndexService`, the search backends, the Solr ObjectHandler, and a sprawling frontend (copy modals, stats, import UI). It also misleads schema authors into reaching for `objectPublishedField` / `objectDepublishedField` / `autoPublish` configuration when an RBAC rule with `$now` is the right tool. This change finishes the removal across OpenRegister core and frontend, and documents what remains in scope for downstream apps (OpenCatalogi, Softwarecatalogus).

## What Changes

- **BREAKING (already shipped in core):** Remove `_published` / `_depublished` from `MagicMapper::getBaseMetadataColumns()`, the `$metadataColumns` arrays in `ensureTableForRegisterSchema()`, the `$idxMetaFields` index definitions, `buildInsertData()`, datetime conversion checks, and `buildObjectFromRow()`.
- Remove `published` / `depublished` references from `MariaDbSearchHandler`, `MetaDataFacetHandler`, and `MagicFacetHandler`.
- Remove `objectPublishedField`, `objectDepublishedField`, and `autoPublish` processing from `SaveObject::hydrateObjectMetadata()` and `setSelfMetadata()`; `MetadataHydrationHandler` now logs a deprecation warning when these schema config keys are encountered (PR #1132).
- Remove `published` / `depublished` from `SearchQueryHandler` `@self` metadata fields and drop `$params['published']` plumbing.
- Remove the `$published` parameter from `IndexService::searchObjects()`, `ObjectHandler::searchObjects()` / `buildSolrQuery()`, and `SearchBackendInterface::searchObjects()`; drop the `published:true` Solr filter.
- Remove object publish / depublish methods from `BulkController` and clean up the `ObjectsController` / `BulkController` docblocks.
- Remove `addPublishedDateToObjects()` from `ImportService` and turn the `$publish` parameter into a deprecated no-op that logs a warning (PR #1128).
- Database migration `Version1Date20260313130000` drops the legacy columns idempotently from existing magic tables (PR #1133).
- Frontend cleanup: remove `@self.published` / `@self.depublished` reads from copy modals, dashboard / register / schema stats, ImportRegister auto-publish toggle, schema modal CSS, type definitions, and mock data (PRs #1129, #1130, #1131).
- Update the `MultiTenancyTrait` documentation to remove object-level published-bypass references while keeping the Register / Schema-level bypass documentation intact.
- Out of scope (tracked but in separate repos): OpenCatalogi backend / frontend cleanup, Softwarecatalogus frontend cleanup, the schema migration guide, and end-to-end RBAC `$now` validation against WOO publication schemas.
