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

## REMOVED Requirements

### Requirement: Object Published / Depublished Metadata System
The system MUST no longer support per-object `published` / `depublished` metadata fields. RBAC rules using the `$now` dynamic variable replace this functionality.

- `addPublishedDateToObjects()` is removed from `ImportService`; the `$publish` parameter on import methods is a deprecated no-op that logs a deprecation warning.
- `objectPublishedField`, `objectDepublishedField`, and `autoPublish` schema config keys are deprecated; `MetadataHydrationHandler` logs a deprecation warning when they are encountered.
- `@self.published` / `@self.depublished` reads are removed from the frontend `CopyObject` and `MassCopyObjects` modals.
- Object "published" stat rows and columns are removed from the dashboard, register detail, and schema detail views.
- The "Auto-publish imported objects" toggle is removed from the `ImportRegister` modal.
- Object-level published bypass references are removed from the `MultiTenancyTrait` documentation; Register / Schema-level bypass documentation remains intact.
- `MagicMapper` no longer registers `_published` / `_depublished` magic columns or indexes; `SaveObject`, `SearchQueryHandler`, `IndexService`, the search backends, and the Solr `ObjectHandler` no longer emit or consume the field.
- Object publish / depublish methods are removed from `BulkController`; the related routes are gone.
