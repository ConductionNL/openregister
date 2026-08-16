# Spec: Deprecate Published/Depublished Object Metadata

## Overview

Remove the dedicated `published`/`depublished` object metadata system from OpenRegister. The RBAC `$now` dynamic variable (already implemented) replaces this functionality for authorization-based visibility control.

## Scope

**In scope (OpenRegister only):**
- Remove `addPublishedDateToObjects()` from ImportService and auto-publish import logic
- Remove `@self.published`/`@self.depublished` references from frontend copy modals
- Remove object "published" stats from dashboard, register, and schema views
- Update MultiTenancyTrait documentation to remove object-level published bypass references
- Add deprecation log warnings for schema config keys if encountered

**Out of scope:**
- Register/Schema `published`/`depublished` fields (multi-tenancy bypass system)
- File publish/depublish (Nextcloud share management, `autoPublish` in FilePropertyHandler)
- Configuration `publishToGitHub` (GitHub export)
- OpenCatalogi and Softwarecatalogus changes (separate repos)
- `SearchTrailMapper.published_only` (historical tracking data)
- MagicMapper columns (already removed)
- Object publish/depublish API routes (already removed)
- ObjectEntity published/depublished properties (already removed)

## Requirements

### REQ-1: ImportService Published Date Removal
- GIVEN an import operation with `publish=true`
- WHEN objects are imported via JSON or CSV
- THEN the `addPublishedDateToObjects()` logic is removed
- AND the `$publish` parameter is ignored with a deprecation log warning
- AND existing import functionality continues to work without published date injection

### REQ-2: Frontend Copy Modal Cleanup
- GIVEN a user copies an object via CopyObject or MassCopyObjects modal
- WHEN the `@self` metadata is stripped from the copy
- THEN `published` and `depublished` keys are no longer deleted (they don't exist)

### REQ-3: Frontend Stats Cleanup
- GIVEN dashboard, register detail, or schema detail views
- WHEN object statistics are displayed
- THEN the "published" count row/column is removed from the stats display

### REQ-4: Import UI Cleanup
- GIVEN the ImportRegister modal
- WHEN a user configures an import
- THEN the "Auto-publish imported objects" toggle is removed

### REQ-5: MultiTenancyTrait Documentation
- GIVEN the MultiTenancyTrait docblock
- WHEN describing multi-tenancy bypass
- THEN object-level published bypass references are removed
- AND Register/Schema published bypass documentation remains

### REQ-6: Deprecation Warnings
- GIVEN a schema with `objectPublishedField`, `objectDepublishedField`, or `autoPublish` config keys
- WHEN an object is saved using that schema
- THEN a deprecation warning is logged suggesting migration to RBAC rules with `$now`
