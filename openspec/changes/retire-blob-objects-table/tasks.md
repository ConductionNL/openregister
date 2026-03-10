## 1. Migration Background Job

- [ ] 1.1 Create `lib/BackgroundJob/BlobMigrationJob.php` as a `TimedJob` (5-minute interval) that queries up to 100 objects from `oc_openregister_objects`, groups by (register, schema), and upserts each group via `MagicMapper::ultraFastBulkSave()`
- [ ] 1.2 Handle orphaned objects: skip and log WARNING for objects with null/invalid register or schema references
- [ ] 1.3 After successful upsert of each batch, delete the migrated rows from the blob table
- [ ] 1.4 Track progress in `appconfig`: `blob_migration_processed`, `blob_migration_remaining`, `blob_migration_last_run`, and `blob_migration_complete` flag
- [ ] 1.5 Auto-create magic tables via `MagicMapper::createTableForRegisterSchema()` when they don't exist yet
- [ ] 1.6 Register the background job in `lib/AppInfo/Application.php`

## 2. Admin Progress Visibility

- [ ] 2.1 Add migration status to admin settings response (processed count, remaining count, complete flag, last run timestamp)

## 3. Remove Blob Table Code

- [ ] 3.1 Remove `ObjectEntityMapper` class and its DI registration
- [ ] 3.2 Remove `ObjectEntity\BulkOperationsHandler` (blob-specific bulk handler)
- [ ] 3.3 Remove `UnifiedObjectMapper` routing layer — update all injection sites to use `MagicMapper` directly
- [ ] 3.4 Remove blob fallback branches in `ObjectService`, `SaveObject`, `SaveObjects`, and `CrudHandler`
- [ ] 3.5 Remove blob-related code in `QueryHandler` and any remaining service files
- [ ] 3.6 Audit and update `OptimizedBulkOperations` to remove any blob-table-specific logic while preserving magic table support

## 4. Update Dependent Code

- [ ] 4.1 Audit opencatalogi for direct `ObjectEntityMapper` or `UnifiedObjectMapper` references and update to use `ObjectService`/`MagicMapper`
- [ ] 4.2 Audit softwarecatalog for direct blob-table references and update similarly
- [ ] 4.3 Update any remaining `source === 'blob'` checks throughout the codebase

## 5. Database Migration

- [ ] 5.1 Create migration `Version*Date*.php` that drops `oc_openregister_objects` — only if `blob_migration_complete` is true AND table has zero rows; skip with WARNING otherwise

## 6. Test Cleanup

- [ ] 6.1 Remove `ObjectEntityMapperTest` and blob-specific test methods from `ObjectServiceTest` and `BasicCrudTest`
- [ ] 6.2 Verify remaining magic-table tests pass with `composer test`
- [ ] 6.3 Add a test for `BlobMigrationJob` verifying batch processing, orphan skipping, and completion flag
