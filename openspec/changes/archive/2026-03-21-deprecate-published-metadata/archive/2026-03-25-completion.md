# Archive: deprecate-published-metadata

## Completed: 2026-03-25

## Summary

Removed the dedicated `published`/`depublished` object metadata system from OpenRegister.
The RBAC `$now` dynamic variable (already implemented) replaces this functionality for
authorization-based visibility control.

## What Was Done (OpenRegister scope)

### Backend
- Removed `addPublishedDateToObjects()` from ImportService
- Deprecated `$publish` parameter in import methods (logs warning, no-op)
- Added deprecation warnings for `objectPublishedField`, `objectDepublishedField`, `autoPublish` schema config keys in MetadataHydrationHandler
- Updated MultiTenancyTrait docs to clarify published bypass is Register/Schema only

### Frontend
- Removed `@self.published`/`@self.depublished` references from CopyObject and MassCopyObjects modals
- Removed published count from stats in dashboard, register detail, schema detail views
- Removed published CSS from SchemaStatsBlock and schema modals
- Removed auto-publish toggle from ImportRegister modal
- Removed `published` from register/schema type definitions and mock data

### Tests (14 total)
- MetadataHydrationHandlerDeprecationTest: 6 tests for deprecation warnings
- ImportServicePublishDeprecationTest: 4 tests for method removal and param compat
- Version1Date20260313130000Test: 4 tests for migration idempotency

### Migration
- Version1Date20260313130000 already exists and correctly drops columns/indexes

## What Was Already Done (prior to this change)
- MagicMapper column definitions, metadata lists, index definitions
- MariaDbSearchHandler, MetaDataFacetHandler, MagicFacetHandler
- SearchQueryHandler, IndexService, ObjectHandler, SearchBackendInterface
- ObjectsController, BulkController
- ObjectEntity published/depublished properties
- Object publish/depublish API routes

## Out of Scope (separate repos)
- OpenCatalogi backend (EventService, listeners, PublicationsController)
- OpenCatalogi frontend (MassPublish/Depublish, PublishedIcon, store actions)
- Softwarecatalogus frontend
- Schema migration guide / WOO publication schema updates

## GitHub Issues
- Tracking: #1127
- Parent: #910
- Tasks: #1128, #1129, #1130, #1131, #1132, #1133 (all closed)
