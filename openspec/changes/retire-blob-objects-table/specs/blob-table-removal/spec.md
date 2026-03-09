## ADDED Requirements

### Requirement: Drop blob objects table after migration
The system SHALL include a database migration that drops `oc_openregister_objects` only after all data has been migrated.

#### Scenario: Migration complete — table dropped
- **WHEN** the database migration runs and `appconfig` key `blob_migration_complete` is `true` AND the `oc_openregister_objects` table contains zero rows
- **THEN** the migration drops the `oc_openregister_objects` table

#### Scenario: Migration incomplete — table preserved
- **WHEN** the database migration runs and `blob_migration_complete` is not `true` OR the blob table still contains rows
- **THEN** the migration logs a WARNING ("Blob migration not complete, skipping table drop") and does NOT drop the table

#### Scenario: Table already dropped
- **WHEN** the migration runs and the `oc_openregister_objects` table does not exist
- **THEN** the migration completes successfully as a no-op

### Requirement: Remove ObjectEntityMapper
The system SHALL remove the `ObjectEntityMapper` class and all code that references it. No code path SHALL read from or write to the blob table.

#### Scenario: ObjectEntityMapper class removed
- **WHEN** the codebase is searched for `ObjectEntityMapper`
- **THEN** zero references exist outside of migration/removal code and changelog

#### Scenario: DI container no longer registers blob mapper
- **WHEN** the application boots
- **THEN** `ObjectEntityMapper` is not registered in the dependency injection container

### Requirement: Remove UnifiedObjectMapper routing layer
The system SHALL remove the `UnifiedObjectMapper` class. All object operations SHALL go directly through `MagicMapper`.

#### Scenario: Services use MagicMapper directly
- **WHEN** `ObjectService`, `SaveObject`, or `SaveObjects` perform object operations
- **THEN** they call `MagicMapper` methods directly without any blob/magic routing decision

#### Scenario: No blob fallback code paths
- **WHEN** the codebase is searched for `shouldUseMagicMapper`, `source.*blob`, or `UnifiedObjectMapper`
- **THEN** zero references exist outside of migration/removal code and changelog

### Requirement: Remove blob-specific bulk operations handler
The system SHALL remove `ObjectEntity\BulkOperationsHandler` (the blob-table bulk handler). The `OptimizedBulkOperations` class SHALL be retained as it serves magic table bulk operations.

#### Scenario: Blob bulk handler removed
- **WHEN** the codebase is searched for `ObjectEntity\BulkOperationsHandler`
- **THEN** zero references exist

#### Scenario: Magic table bulk operations unaffected
- **WHEN** a bulk save is performed on a magic table
- **THEN** `OptimizedBulkOperations::ultraFastUnifiedBulkSave()` executes successfully

### Requirement: Remove blob-table tests
The system SHALL remove test files and test methods that test blob-table functionality. Magic-table test coverage SHALL remain intact.

#### Scenario: Blob test files removed
- **WHEN** the test suite is examined
- **THEN** `ObjectEntityMapperTest` and any blob-specific test methods in `ObjectServiceTest` and `BasicCrudTest` no longer exist

#### Scenario: Test suite passes without blob tests
- **WHEN** `composer test` is executed
- **THEN** all remaining tests pass with zero failures related to missing blob infrastructure

### Requirement: ObjectEntity remains as DTO
The `ObjectEntity` class SHALL be retained as the data transfer object for all object operations. It SHALL NOT be deleted as part of this change.

#### Scenario: ObjectEntity still used by magic tables
- **WHEN** a magic table operation returns data
- **THEN** the result is wrapped in an `ObjectEntity` instance with `source` set to `"orm"`
