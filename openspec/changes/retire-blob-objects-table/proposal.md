## Why

The `oc_openregister_objects` blob table was the original storage mechanism — a single table holding all objects as JSON blobs regardless of schema. Magic tables (`oc_openregister_table_{slug}`) have since replaced it with schema-specific, type-safe columns that deliver faster queries, proper indexing, and better data integrity. The blob table is now redundant legacy infrastructure: it creates confusion in the routing layer (`UnifiedObjectMapper`), doubles storage paths, and risks stale data if objects exist in both locations. Retiring it simplifies the codebase and eliminates an entire class of "which table has the truth?" bugs.

## What Changes

- **Add a migration background job** that moves blob-table objects to their magic tables in batches of 100, grouped by register+schema combination to leverage `ultraFastUnifiedBulkSave()` for optimal performance
- **Add an admin progress indicator** showing migration status (objects remaining, estimated completion)
- **BREAKING: Remove `ObjectEntityMapper`** — the blob-table mapper and all code paths that read from or write to `oc_openregister_objects`
- **BREAKING: Remove `UnifiedObjectMapper` routing logic** — all object operations go directly through `MagicMapper`; the blob/magic decision layer is no longer needed
- **BREAKING: Remove `ObjectEntity\BulkOperationsHandler`** blob-specific bulk operations (the `OptimizedBulkOperations` handler for magic tables remains)
- **Remove blob-table tests** (`ObjectEntityMapperTest`, blob-related assertions in `ObjectServiceTest`, `BasicCrudTest`)
- **Drop the `oc_openregister_objects` table** via a migration after the background job confirms zero remaining rows
- **Update dependent apps** (opencatalogi, softwarecatalog) — ensure they don't reference blob-table internals

## Capabilities

### New Capabilities
- `blob-migration-job`: Background job that batch-migrates blob objects to magic tables, grouped by register+schema, with progress tracking and admin visibility
- `blob-table-removal`: Database migration and code cleanup to drop the blob objects table and remove all associated mappers, services, and routing logic

### Modified Capabilities
_(no existing spec-level requirements change — this is a storage-layer removal)_

## Impact

- **Database**: `oc_openregister_objects` table dropped; all data must be migrated first
- **Code removal**: `ObjectEntityMapper`, `ObjectEntity\BulkOperationsHandler`, `UnifiedObjectMapper` routing, blob-specific branches in `ObjectService` and `SaveObject`/`SaveObjects`
- **API**: No external API changes — REST endpoints remain identical, only the storage backend changes
- **Dependent apps**: opencatalogi and softwarecatalog use `ObjectService` — must verify they don't bypass the service layer to access blob storage directly
- **Tests**: Blob-table test files removed; magic-table test coverage must be sufficient before removal
- **Risk**: Data loss if migration job doesn't complete before table drop — the drop migration must verify zero blob rows remain
