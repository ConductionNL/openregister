# Tasks: unit-test-coverage-phase2

## Batch 0: Infrastructure Fix

### Task 0.1: Narrow phpunit-unit.xml Db exclusion
- **files**: `openregister/phpunit-unit.xml`
- **acceptance_criteria**:
  - GIVEN the current config excludes all of `lib/Db/` WHEN entity/mapper/handler tests run THEN coverage is NOT counted
  - AFTER this fix, only `lib/Migration/` and `lib/AppInfo/Application.php` remain excluded
  - Entity tests (Batch 4), Mapper tests (Batch 5), and Handler tests (Batch 6) contribute to coverage metrics
- **notes**: The current `<exclude><directory>lib/Db/</directory></exclude>` was likely added because Db classes were untested and dragged down coverage. Now that we're testing them, the exclusion should be removed or narrowed to specific auto-generated files.
- [x] Implement
- [x] Test

## Batch 1: Events

### Task 1.1: Create RegisterEventsTest
- **files**: `openregister/tests/Unit/Event/RegisterEventsTest.php`
- **source_files**: `openregister/lib/Event/RegisterCreatedEvent.php`, `openregister/lib/Event/RegisterUpdatedEvent.php`, `openregister/lib/Event/RegisterDeletedEvent.php`
- **acceptance_criteria**:
  - GIVEN a Register entity WHEN each event class is constructed THEN getRegister() returns the same entity
  - GIVEN any Register event WHEN checked THEN it is an instance of \OCP\EventDispatcher\Event
  - Use `#[DataProvider]` to test all three event classes
- **note**: Covered by existing `SimpleCrudEventsTest.php` which tests all CRUD events via DataProvider
- [x] Implement
- [x] Test

### Task 1.2: Create SchemaEventsTest
- **files**: `openregister/tests/Unit/Event/SchemaEventsTest.php`
- **source_files**: `openregister/lib/Event/SchemaCreatedEvent.php`, `openregister/lib/Event/SchemaUpdatedEvent.php`, `openregister/lib/Event/SchemaDeletedEvent.php`
- **acceptance_criteria**:
  - GIVEN a Schema entity WHEN each event class is constructed THEN getSchema() returns the same entity
  - Use `#[DataProvider]` to test all three event classes
- **note**: Covered by existing `SimpleCrudEventsTest.php`
- [x] Implement
- [x] Test

### Task 1.3: Create ObjectEventsTest
- **files**: `openregister/tests/Unit/Event/ObjectEventsTest.php`
- **source_files**: `openregister/lib/Event/ObjectCreatedEvent.php`, `openregister/lib/Event/ObjectCreatingEvent.php`, `openregister/lib/Event/ObjectUpdatedEvent.php`, `openregister/lib/Event/ObjectUpdatingEvent.php`, `openregister/lib/Event/ObjectDeletedEvent.php`, `openregister/lib/Event/ObjectDeletingEvent.php`, `openregister/lib/Event/ObjectLockedEvent.php`, `openregister/lib/Event/ObjectUnlockedEvent.php`, `openregister/lib/Event/ObjectRevertedEvent.php`
- **acceptance_criteria**:
  - GIVEN an ObjectEntity WHEN each event class is constructed THEN getObject() returns the same entity
  - Test all 9 object event classes via `#[DataProvider]`
  - Test any additional constructor parameters (e.g., old data for update events)
- **note**: Covered by existing `SimpleCrudEventsTest.php` and `ObjectStoppableEventsTest.php` and `ObjectSpecialEventsTest.php`
- [x] Implement
- [x] Test

### Task 1.4: Create EntityEventsTest
- **files**: `openregister/tests/Unit/Event/EntityEventsTest.php`
- **source_files**: `openregister/lib/Event/AgentCreatedEvent.php`, `openregister/lib/Event/AgentUpdatedEvent.php`, `openregister/lib/Event/AgentDeletedEvent.php`, `openregister/lib/Event/ApplicationCreatedEvent.php`, `openregister/lib/Event/ApplicationUpdatedEvent.php`, `openregister/lib/Event/ApplicationDeletedEvent.php`, `openregister/lib/Event/ConfigurationCreatedEvent.php`, `openregister/lib/Event/ConfigurationUpdatedEvent.php`, `openregister/lib/Event/ConfigurationDeletedEvent.php`, `openregister/lib/Event/ConversationCreatedEvent.php`, `openregister/lib/Event/ConversationUpdatedEvent.php`, `openregister/lib/Event/ConversationDeletedEvent.php`, `openregister/lib/Event/OrganisationCreatedEvent.php`, `openregister/lib/Event/OrganisationUpdatedEvent.php`, `openregister/lib/Event/OrganisationDeletedEvent.php`, `openregister/lib/Event/SourceCreatedEvent.php`, `openregister/lib/Event/SourceUpdatedEvent.php`, `openregister/lib/Event/SourceDeletedEvent.php`, `openregister/lib/Event/ViewCreatedEvent.php`, `openregister/lib/Event/ViewUpdatedEvent.php`, `openregister/lib/Event/ViewDeletedEvent.php`
- **acceptance_criteria**:
  - GIVEN any entity WHEN its Created/Updated/Deleted event is constructed THEN the getter returns the same entity
  - Test all 21 entity event classes via `#[DataProvider]`
- **note**: Covered by existing `SimpleCrudEventsTest.php`
- [x] Implement
- [x] Test

### Task 1.5: Create SpecialEventsTest
- **files**: `openregister/tests/Unit/Event/SpecialEventsTest.php`
- **source_files**: `openregister/lib/Event/DeepLinkRegistrationEvent.php`, `openregister/lib/Event/ToolRegistrationEvent.php`, `openregister/lib/Event/UserProfileUpdatedEvent.php`
- **acceptance_criteria**:
  - GIVEN each special event WHEN constructed THEN its getters return the expected values
  - Test constructor parameters specific to each event type
- **note**: Covered by existing `RegistrationEventsTest.php` and `UserProfileUpdatedEventTest.php`
- [x] Implement
- [x] Test

## Batch 2: Exceptions

### Task 2.1: Create ExceptionsTest
- **files**: `openregister/tests/Unit/Exception/ExceptionsTest.php`
- **source_files**: `openregister/lib/Exception/AuthenticationException.php`, `openregister/lib/Exception/CustomValidationException.php`, `openregister/lib/Exception/DatabaseConstraintException.php`, `openregister/lib/Exception/HookStoppedException.php`, `openregister/lib/Exception/LockedException.php`, `openregister/lib/Exception/NotAuthorizedException.php`, `openregister/lib/Exception/ReferentialIntegrityException.php`, `openregister/lib/Exception/RegisterNotFoundException.php`, `openregister/lib/Exception/SchemaNotFoundException.php`, `openregister/lib/Exception/ValidationException.php`
- **note**: 10 exception classes exist (not 9 as originally counted — `ReferentialIntegrityException` was added)
- **acceptance_criteria**:
  - GIVEN each exception class WHEN constructed with message/code THEN getMessage() and getCode() return the values
  - GIVEN each exception WHEN checked THEN it extends the correct base class
  - GIVEN ValidationException WHEN getValidationErrors() is called THEN it returns the errors array
  - Use `#[DataProvider]` where applicable
- **note**: Covered by existing `ExceptionsTest.php` and `ReferentialIntegrityExceptionTest.php`
- [x] Implement
- [x] Test

## Batch 3: Formats

### Task 3.1: Create BsnFormatTest
- **files**: `openregister/tests/Unit/Formats/BsnFormatTest.php`
- **source_files**: `openregister/lib/Formats/BsnFormat.php`
- **acceptance_criteria**:
  - GIVEN valid 9-digit BSN numbers with correct checksum WHEN validate() is called THEN it returns true
  - GIVEN BSN with invalid checksum WHEN validate() is called THEN it returns false
  - GIVEN too short/long numbers WHEN validate() is called THEN it returns false
  - GIVEN non-numeric/null/empty input WHEN validate() is called THEN it returns false
  - Use `#[DataProvider]` for valid and invalid BSN cases
- **note**: Covered by existing `BsnFormatTest.php`
- [x] Implement
- [x] Test

## Batch 4: Db Entities

### Task 4.1: Create untested entity tests
- **files**: `openregister/tests/Unit/Db/AgentTest.php`, `openregister/tests/Unit/Db/AuditTrailTest.php`, `openregister/tests/Unit/Db/ChunkTest.php`, `openregister/tests/Unit/Db/ConsumerTest.php`, `openregister/tests/Unit/Db/ConversationTest.php`, `openregister/tests/Unit/Db/DataAccessProfileTest.php`, `openregister/tests/Unit/Db/DeployedWorkflowTest.php`, `openregister/tests/Unit/Db/EndpointTest.php`, `openregister/tests/Unit/Db/EndpointLogTest.php`, `openregister/tests/Unit/Db/EntityRelationTest.php`
- **source_files**: corresponding `openregister/lib/Db/*.php` files
- **acceptance_criteria**:
  - GIVEN each entity WHEN constructed THEN default values are correct
  - GIVEN each entity WHEN setters are called THEN getters return expected values
  - GIVEN each entity WHEN jsonSerialize() is called THEN all fields appear with correct types
  - GIVEN nullable fields WHEN set to null THEN getters return null
  - Test type coercion for JSON/DateTime fields
- [ ] Implement
- [ ] Test

### Task 4.2: Create remaining entity tests
- **files**: `openregister/tests/Unit/Db/FeedbackTest.php`, `openregister/tests/Unit/Db/GdprEntityTest.php`, `openregister/tests/Unit/Db/MappingTest.php`, `openregister/tests/Unit/Db/MessageTest.php`, `openregister/tests/Unit/Db/SearchTrailTest.php`, `openregister/tests/Unit/Db/SourceTest.php`, `openregister/tests/Unit/Db/ViewTest.php`, `openregister/tests/Unit/Db/WebhookLogTest.php`, `openregister/tests/Unit/Db/WorkflowEngineTest.php`, `openregister/tests/Unit/Db/MultiTenancyTraitTest.php`
- **source_files**: corresponding `openregister/lib/Db/*.php` files
- **acceptance_criteria**: Same as Task 4.1
- [ ] Implement
- [ ] Test

## Batch 5: Db Mappers

### Task 5.1: Create mapper tests (A-E)
- **files**: `openregister/tests/Unit/Db/AbstractObjectMapperTest.php`, `openregister/tests/Unit/Db/AgentMapperTest.php`, `openregister/tests/Unit/Db/ApplicationMapperTest.php`, `openregister/tests/Unit/Db/AuditTrailMapperTest.php`, `openregister/tests/Unit/Db/ChunkMapperTest.php`, `openregister/tests/Unit/Db/ConfigurationMapperTest.php`, `openregister/tests/Unit/Db/ConsumerMapperTest.php`, `openregister/tests/Unit/Db/ConversationMapperTest.php`, `openregister/tests/Unit/Db/DataAccessProfileMapperTest.php`, `openregister/tests/Unit/Db/DeployedWorkflowMapperTest.php`, `openregister/tests/Unit/Db/EndpointLogMapperTest.php`, `openregister/tests/Unit/Db/EndpointMapperTest.php`, `openregister/tests/Unit/Db/EntityRelationMapperTest.php`
- **source_files**: corresponding `openregister/lib/Db/*Mapper.php` files
- **acceptance_criteria**:
  - GIVEN mocked IDBConnection WHEN find() is called THEN it builds correct SQL and returns entity
  - GIVEN mocked IDBConnection WHEN findAll() is called with filters THEN it applies WHERE clauses
  - GIVEN mapper WHEN createFromArray() is called THEN it inserts entity with UUID
  - GIVEN mapper WHEN updateFromArray() is called THEN it updates the entity
  - Test DoesNotExistException handling for find() with non-existent ID
- [ ] Implement
- [ ] Test

### Task 5.2: Create mapper tests (F-Z)
- **files**: `openregister/tests/Unit/Db/FeedbackMapperTest.php`, `openregister/tests/Unit/Db/FileMapperTest.php`, `openregister/tests/Unit/Db/GdprEntityMapperTest.php`, `openregister/tests/Unit/Db/MappingMapperTest.php`, `openregister/tests/Unit/Db/MessageMapperTest.php`, `openregister/tests/Unit/Db/OrganisationMapperTest.php`, `openregister/tests/Unit/Db/RegisterMapperTest.php`, `openregister/tests/Unit/Db/SearchTrailMapperTest.php`, `openregister/tests/Unit/Db/SourceMapperTest.php`, `openregister/tests/Unit/Db/UnifiedObjectMapperTest.php`, `openregister/tests/Unit/Db/ViewMapperTest.php`, `openregister/tests/Unit/Db/WebhookLogMapperTest.php`, `openregister/tests/Unit/Db/WebhookMapperTest.php`, `openregister/tests/Unit/Db/WorkflowEngineMapperTest.php`
- **source_files**: corresponding `openregister/lib/Db/*Mapper.php` files
- **acceptance_criteria**: Same as Task 5.1
- [ ] Implement
- [ ] Test

## Batch 6: Db Handlers

### Task 6.1: Create MagicMapper handler tests
- **files**: `openregister/tests/Unit/Db/MagicMapper/MagicBulkHandlerTest.php`, `openregister/tests/Unit/Db/MagicMapper/MagicFacetHandlerTest.php`, `openregister/tests/Unit/Db/MagicMapper/MagicOrganizationHandlerTest.php`, `openregister/tests/Unit/Db/MagicMapper/MagicRbacHandlerTest.php`, `openregister/tests/Unit/Db/MagicMapper/MagicSearchHandlerTest.php`
- **source_files**: `openregister/lib/Db/MagicMapper/*.php`
- **acceptance_criteria**:
  - GIVEN handler WHEN query building methods are called with various filter combinations THEN correct SQL fragments are produced
  - Test empty filters, single filter, multiple filters, unknown filter keys
  - Test search with and without RBAC enabled
- [ ] Implement
- [ ] Test

### Task 6.2: Create ObjectEntity handler tests
- **files**: `openregister/tests/Unit/Db/ObjectEntity/BulkOperationsHandlerTest.php`, `openregister/tests/Unit/Db/ObjectEntity/CrudHandlerTest.php`, `openregister/tests/Unit/Db/ObjectEntity/FacetsHandlerTest.php`, `openregister/tests/Unit/Db/ObjectEntity/LockingHandlerTest.php`, `openregister/tests/Unit/Db/ObjectEntity/QueryBuilderHandlerTest.php`, `openregister/tests/Unit/Db/ObjectEntity/QueryOptimizationHandlerTest.php`, `openregister/tests/Unit/Db/ObjectEntity/StatisticsHandlerTest.php`
- **source_files**: `openregister/lib/Db/ObjectEntity/*.php`
- **acceptance_criteria**:
  - Test each handler's public methods with mocked dependencies
  - Test locked vs unlocked, cached vs uncached, found vs not found paths
- [ ] Implement
- [ ] Test

### Task 6.3: Create ObjectHandlers tests
- **files**: `openregister/tests/Unit/Db/ObjectHandlers/HyperFacetHandlerTest.php`, `openregister/tests/Unit/Db/ObjectHandlers/MariaDbFacetHandlerTest.php`, `openregister/tests/Unit/Db/ObjectHandlers/MariaDbSearchHandlerTest.php`, `openregister/tests/Unit/Db/ObjectHandlers/MetaDataFacetHandlerTest.php`, `openregister/tests/Unit/Db/ObjectHandlers/OptimizedBulkOperationsTest.php`, `openregister/tests/Unit/Db/ObjectHandlers/OptimizedFacetHandlerTest.php`
- **source_files**: `openregister/lib/Db/ObjectHandlers/*.php`
- **acceptance_criteria**:
  - Test facet generation with various data shapes
  - Test search query building and parameter binding
  - Test bulk operations with single/multiple/empty datasets
- [ ] Implement
- [ ] Test

## Batch 7: BackgroundJobs, Commands, Cron, Listeners

### Task 7.1: Create BackgroundJob tests
- **files**: `openregister/tests/Unit/BackgroundJob/CacheWarmupJobTest.php`, `openregister/tests/Unit/BackgroundJob/CronFileTextExtractionJobTest.php`, `openregister/tests/Unit/BackgroundJob/HookRetryJobTest.php`, `openregister/tests/Unit/BackgroundJob/NameCacheWarmupJobTest.php`, `openregister/tests/Unit/BackgroundJob/ObjectTextExtractionJobTest.php`, `openregister/tests/Unit/BackgroundJob/SolrNightlyWarmupJobTest.php`, `openregister/tests/Unit/BackgroundJob/SolrWarmupJobTest.php`, `openregister/tests/Unit/BackgroundJob/WebhookDeliveryJobTest.php`
- **source_files**: `openregister/lib/BackgroundJob/*.php`
- **acceptance_criteria**:
  - GIVEN valid job arguments WHEN run() is called THEN the correct service is called
  - GIVEN missing arguments WHEN run() is called THEN a warning is logged
  - GIVEN the service throws WHEN run() is called THEN the error is logged
- [ ] Implement
- [ ] Test

### Task 7.2: Create Command tests
- **files**: `openregister/tests/Unit/Command/MigrateStorageCommandTest.php`, `openregister/tests/Unit/Command/SolrDebugCommandTest.php`, `openregister/tests/Unit/Command/SolrManagementCommandTest.php`
- **source_files**: `openregister/lib/Command/*.php`
- **acceptance_criteria**:
  - GIVEN valid input WHEN execute() is called THEN the service is called and success message is output
  - GIVEN missing arguments WHEN execute() is called THEN error message and non-zero return
  - GIVEN service exception WHEN execute() is called THEN error output and non-zero return
- [ ] Implement
- [ ] Test

### Task 7.3: Create Cron tests
- **files**: `openregister/tests/Unit/Cron/ConfigurationCheckJobTest.php`, `openregister/tests/Unit/Cron/LogCleanUpTaskTest.php`, `openregister/tests/Unit/Cron/SyncConfigurationsJobTest.php`, `openregister/tests/Unit/Cron/WebhookRetryJobTest.php`
- **source_files**: `openregister/lib/Cron/*.php`
- **acceptance_criteria**:
  - GIVEN cron job WHEN run() is called THEN the correct service method is called
  - GIVEN service throws WHEN run() is called THEN error is logged, not re-thrown
- [ ] Implement
- [ ] Test

### Task 7.4: Create Listener tests
- **files**: `openregister/tests/Unit/Listener/CommentsEntityListenerTest.php`, `openregister/tests/Unit/Listener/FileChangeListenerTest.php`, `openregister/tests/Unit/Listener/HookListenerTest.php`, `openregister/tests/Unit/Listener/ObjectChangeListenerTest.php`, `openregister/tests/Unit/Listener/ObjectCleanupListenerTest.php`, `openregister/tests/Unit/Listener/ToolRegistrationListenerTest.php`, `openregister/tests/Unit/Listener/WebhookEventListenerTest.php`
- **source_files**: `openregister/lib/Listener/*.php`
- **acceptance_criteria**:
  - GIVEN matching event WHEN handle() is called THEN the correct service methods are called
  - GIVEN service throws WHEN handle() is called THEN error is logged, not re-thrown
  - GIVEN non-matching event WHEN handle() is called THEN no service methods are called
- [ ] Implement
- [ ] Test

## Batch 8: Controllers

### Task 8.1: Create core CRUD controller tests
- **files**: `openregister/tests/Unit/Controller/ObjectsControllerTest.php`, `openregister/tests/Unit/Controller/RegistersControllerTest.php`, `openregister/tests/Unit/Controller/SchemasControllerTest.php`, `openregister/tests/Unit/Controller/OrganisationControllerTest.php`, `openregister/tests/Unit/Controller/ConfigurationsControllerTest.php`, `openregister/tests/Unit/Controller/SourcesControllerTest.php`, `openregister/tests/Unit/Controller/ViewsControllerTest.php`, `openregister/tests/Unit/Controller/WebhooksControllerTest.php`
- **source_files**: corresponding `openregister/lib/Controller/*.php`
- **acceptance_criteria**:
  - GIVEN valid input WHEN index/show/create/update/destroy is called THEN JSONResponse 200/201
  - GIVEN service throws WHEN any action is called THEN appropriate error status
  - GIVEN missing required params WHEN create/update is called THEN 400 response
  - Test authorization checks where applicable
- [ ] Implement
- [ ] Test

### Task 8.2: Create secondary controller tests
- **files**: `openregister/tests/Unit/Controller/AgentsControllerTest.php`, `openregister/tests/Unit/Controller/ApplicationsControllerTest.php`, `openregister/tests/Unit/Controller/AuditTrailControllerTest.php`, `openregister/tests/Unit/Controller/BulkControllerTest.php`, `openregister/tests/Unit/Controller/ConsumersControllerTest.php`, `openregister/tests/Unit/Controller/EndpointsControllerTest.php`, `openregister/tests/Unit/Controller/FilesControllerTest.php`, `openregister/tests/Unit/Controller/MappingsControllerTest.php`, `openregister/tests/Unit/Controller/TagsControllerTest.php`, `openregister/tests/Unit/Controller/TasksControllerTest.php`
- **source_files**: corresponding `openregister/lib/Controller/*.php`
- **acceptance_criteria**: Same CRUD patterns as Task 8.1
- [ ] Implement
- [ ] Test

### Task 8.3: Create feature controller tests
- **files**: `openregister/tests/Unit/Controller/ChatControllerTest.php`, `openregister/tests/Unit/Controller/ConversationControllerTest.php`, `openregister/tests/Unit/Controller/DashboardControllerTest.php`, `openregister/tests/Unit/Controller/DeletedControllerTest.php`, `openregister/tests/Unit/Controller/FileExtractionControllerTest.php`, `openregister/tests/Unit/Controller/FileSearchControllerTest.php`, `openregister/tests/Unit/Controller/FileTextControllerTest.php`, `openregister/tests/Unit/Controller/GdprEntitiesControllerTest.php`, `openregister/tests/Unit/Controller/HeartbeatControllerTest.php`, `openregister/tests/Unit/Controller/McpControllerTest.php`, `openregister/tests/Unit/Controller/McpServerControllerTest.php`
- **source_files**: corresponding `openregister/lib/Controller/*.php`
- **acceptance_criteria**: Test all public methods, success + error paths
- [ ] Implement
- [ ] Test

### Task 8.4: Create remaining controller tests
- **files**: `openregister/tests/Unit/Controller/MigrationControllerTest.php`, `openregister/tests/Unit/Controller/NamesControllerTest.php`, `openregister/tests/Unit/Controller/NotesControllerTest.php`, `openregister/tests/Unit/Controller/OasControllerTest.php`, `openregister/tests/Unit/Controller/RevertControllerTest.php`, `openregister/tests/Unit/Controller/SearchTrailControllerTest.php`, `openregister/tests/Unit/Controller/SolrControllerTest.php`, `openregister/tests/Unit/Controller/TablesControllerTest.php`, `openregister/tests/Unit/Controller/UiControllerTest.php`, `openregister/tests/Unit/Controller/UserControllerTest.php`, `openregister/tests/Unit/Controller/UserSettingsControllerTest.php`, `openregister/tests/Unit/Controller/WorkflowEngineControllerTest.php`, `openregister/tests/Unit/Controller/ConfigurationControllerTest.php`
- **source_files**: corresponding `openregister/lib/Controller/*.php`
- **acceptance_criteria**: Test all public methods, success + error paths
- [ ] Implement
- [ ] Test

### Task 8.5: Create settings controller tests
- **files**: `openregister/tests/Unit/Controller/Settings/ApiTokenSettingsControllerTest.php`, `openregister/tests/Unit/Controller/Settings/CacheSettingsControllerTest.php`, `openregister/tests/Unit/Controller/Settings/ConfigurationSettingsControllerTest.php`, `openregister/tests/Unit/Controller/Settings/FileSettingsControllerTest.php`, `openregister/tests/Unit/Controller/Settings/LlmSettingsControllerTest.php`, `openregister/tests/Unit/Controller/Settings/N8nSettingsControllerTest.php`, `openregister/tests/Unit/Controller/Settings/SecuritySettingsControllerTest.php`, `openregister/tests/Unit/Controller/Settings/SolrManagementControllerTest.php`, `openregister/tests/Unit/Controller/Settings/SolrOperationsControllerTest.php`, `openregister/tests/Unit/Controller/Settings/SolrSettingsControllerTest.php`, `openregister/tests/Unit/Controller/Settings/ValidationSettingsControllerTest.php`, `openregister/tests/Unit/Controller/Settings/VectorSettingsControllerTest.php`
- **source_files**: `openregister/lib/Controller/Settings/*.php`
- **acceptance_criteria**: Test get/update settings methods with success + error paths
- [ ] Implement
- [ ] Test

## Batch 9: Services — Handlers

**Complexity notes for Batch 9:** This batch covers 75 source files totaling ~26,000 lines. The most complex handlers are:
- `ImportHandler.php` (3,256 lines) — the single largest handler; handles GitHub/GitLab/upload import flows with extensive branching
- `ConfigurationSettingsHandler.php` (1,325 lines) — complex settings state machine with many config key combinations
- `GitHubHandler.php` (1,324 lines) — HTTP client mocking required for GitHub API calls
- `FolderManagementHandler.php` (888 lines) — Nextcloud filesystem abstraction, `getUser()` fallback to system user
- `FilePublishingHandler.php` (654 lines) — share link generation, permission handling
- `SetupHandler.php` (2,560 lines) — largest Index handler; Solr/Elasticsearch setup orchestration

**Missing from original plan:** 12 GraphQL service files in `lib/Service/GraphQL/` were not included in any batch. Consider adding a Task 9.7 for these.

### Task 9.1: Create Settings handler tests
- **files**: `openregister/tests/Unit/Service/Settings/CacheSettingsHandlerTest.php`, `openregister/tests/Unit/Service/Settings/ConfigurationSettingsHandlerTest.php`, `openregister/tests/Unit/Service/Settings/FileSettingsHandlerTest.php`, `openregister/tests/Unit/Service/Settings/LlmSettingsHandlerTest.php`, `openregister/tests/Unit/Service/Settings/ObjectRetentionHandlerTest.php`, `openregister/tests/Unit/Service/Settings/SearchBackendHandlerTest.php`, `openregister/tests/Unit/Service/Settings/SolrSettingsHandlerTest.php`, `openregister/tests/Unit/Service/Settings/ValidationOperationsHandlerTest.php`
- **source_files**: `openregister/lib/Service/Settings/*.php`
- **acceptance_criteria**:
  - GIVEN handler WHEN get methods are called THEN correct settings are returned
  - GIVEN handler WHEN update methods are called THEN settings are persisted
  - Test all branching logic per handler
- [ ] Implement
- [ ] Test

### Task 9.2: Create Configuration handler tests
- **files**: `openregister/tests/Unit/Service/Configuration/CacheHandlerTest.php`, `openregister/tests/Unit/Service/Configuration/ExportHandlerTest.php`, `openregister/tests/Unit/Service/Configuration/FetchHandlerTest.php`, `openregister/tests/Unit/Service/Configuration/GitHubHandlerTest.php`, `openregister/tests/Unit/Service/Configuration/GitLabHandlerTest.php`, `openregister/tests/Unit/Service/Configuration/ImportHandlerTest.php`, `openregister/tests/Unit/Service/Configuration/PreviewHandlerTest.php`, `openregister/tests/Unit/Service/Configuration/UploadHandlerTest.php`
- **source_files**: `openregister/lib/Service/Configuration/*.php`
- **acceptance_criteria**:
  - Test local vs remote config, found vs not found, valid vs malformed format
  - Test version comparison (newer, older, same)
  - Test cache hit vs cache miss
- [x] Implement (GitHubHandlerTest, ExportHandlerTest, FetchHandlerTest, UploadHandlerTest added; GitLabHandler still missing)
- [x] Test

### Task 9.3: Create File handler tests
- **files**: `openregister/tests/Unit/Service/File/CreateFileHandlerTest.php`, `openregister/tests/Unit/Service/File/DeleteFileHandlerTest.php`, `openregister/tests/Unit/Service/File/ReadFileHandlerTest.php`, `openregister/tests/Unit/Service/File/UpdateFileHandlerTest.php`, `openregister/tests/Unit/Service/File/FileCrudHandlerTest.php`, `openregister/tests/Unit/Service/File/FileFormattingHandlerTest.php`, `openregister/tests/Unit/Service/File/FileOwnershipHandlerTest.php`, `openregister/tests/Unit/Service/File/FilePublishingHandlerTest.php`, `openregister/tests/Unit/Service/File/FileSharingHandlerTest.php`, `openregister/tests/Unit/Service/File/FileValidationHandlerTest.php`, `openregister/tests/Unit/Service/File/FolderManagementHandlerTest.php`, `openregister/tests/Unit/Service/File/TaggingHandlerTest.php`, `openregister/tests/Unit/Service/File/DocumentProcessingHandlerTest.php`
- **source_files**: `openregister/lib/Service/File/*.php`
- **acceptance_criteria**:
  - Test file found vs not found, owned vs shared, valid vs invalid type
  - Test folder exists vs needs creation
  - Test file with/without tags
- [x] Implement (FilePublishingHandlerTest, FolderManagementHandlerTest added; remaining File handlers still missing)
- [x] Test

### Task 9.4: Create Chat, Vectorization, TextExtraction handler tests
- **files**: `openregister/tests/Unit/Service/Chat/ConversationManagementHandlerTest.php`, `openregister/tests/Unit/Service/Chat/ContextRetrievalHandlerTest.php`, `openregister/tests/Unit/Service/Chat/MessageHistoryHandlerTest.php`, `openregister/tests/Unit/Service/Chat/ResponseGenerationHandlerTest.php`, `openregister/tests/Unit/Service/Chat/ToolManagementHandlerTest.php`, `openregister/tests/Unit/Service/Vectorization/VectorEmbeddingsTest.php`, `openregister/tests/Unit/Service/TextExtraction/FileHandlerTest.php`, `openregister/tests/Unit/Service/TextExtraction/ObjectHandlerTest.php`, `openregister/tests/Unit/Service/TextExtraction/EntityRecognitionHandlerTest.php`
- **source_files**: corresponding `openregister/lib/Service/*/*.php` files
- **acceptance_criteria**: Test all public methods with success + error paths
- [ ] Implement
- [ ] Test

### Task 9.5: Create Schemas, Mcp, Webhook, Handler tests
- **files**: `openregister/tests/Unit/Service/Schemas/FacetCacheHandlerTest.php`, `openregister/tests/Unit/Service/Schemas/PropertyValidatorHandlerTest.php`, `openregister/tests/Unit/Service/Schemas/SchemaCacheHandlerTest.php`, `openregister/tests/Unit/Service/Mcp/McpProtocolServiceTest.php`, `openregister/tests/Unit/Service/Mcp/McpResourcesServiceTest.php`, `openregister/tests/Unit/Service/Mcp/McpToolsServiceTest.php`, `openregister/tests/Unit/Service/Webhook/CloudEventFormatterTest.php`, `openregister/tests/Unit/Service/Handler/AgentHandlerTest.php`, `openregister/tests/Unit/Service/Handler/ApplicationHandlerTest.php`, `openregister/tests/Unit/Service/Handler/OrganisationHandlerTest.php`, `openregister/tests/Unit/Service/Handler/SourceHandlerTest.php`, `openregister/tests/Unit/Service/Handler/ViewHandlerTest.php`
- **source_files**: corresponding `openregister/lib/Service/*/*.php` files
- **acceptance_criteria**: Test all public methods with success + error + edge case paths
- [ ] Implement
- [ ] Test

### Task 9.6: Create Index and backend tests
- **files**: `openregister/tests/Unit/Service/Index/BulkIndexerTest.php`, `openregister/tests/Unit/Service/Index/ConfigurationHandlerTest.php`, `openregister/tests/Unit/Service/Index/DocumentBuilderTest.php`, `openregister/tests/Unit/Service/Index/FacetBuilderTest.php`, `openregister/tests/Unit/Service/Index/FileHandlerTest.php`, `openregister/tests/Unit/Service/Index/ObjectHandlerTest.php`, `openregister/tests/Unit/Service/Index/SchemaHandlerTest.php`, `openregister/tests/Unit/Service/Index/SetupHandlerTest.php`, `openregister/tests/Unit/Service/Index/WarmupHandlerTest.php`, `openregister/tests/Unit/Service/Index/Backends/Solr/SolrBackendTest.php`, `openregister/tests/Unit/Service/Index/Backends/Solr/SolrCollectionManagerTest.php`, `openregister/tests/Unit/Service/Index/Backends/Solr/SolrDocumentIndexerTest.php`, `openregister/tests/Unit/Service/Index/Backends/Solr/SolrFacetProcessorTest.php`, `openregister/tests/Unit/Service/Index/Backends/Solr/SolrHttpClientTest.php`, `openregister/tests/Unit/Service/Index/Backends/Solr/SolrQueryExecutorTest.php`, `openregister/tests/Unit/Service/Index/Backends/Elasticsearch/ElasticsearchBackendTest.php`, `openregister/tests/Unit/Service/Index/Backends/Elasticsearch/ElasticsearchDocumentIndexerTest.php`, `openregister/tests/Unit/Service/Index/Backends/Elasticsearch/ElasticsearchHttpClientTest.php`, `openregister/tests/Unit/Service/Index/Backends/Elasticsearch/ElasticsearchIndexManagerTest.php`, `openregister/tests/Unit/Service/Index/Backends/Elasticsearch/ElasticsearchQueryExecutorTest.php`
- **source_files**: `openregister/lib/Service/Index/*.php` and `openregister/lib/Service/Index/Backends/**/*.php`
- **acceptance_criteria**:
  - Test successful indexing and search
  - Test connection failure / timeout
  - Test empty results, faceted search, schema creation/update
  - Test bulk indexing with partial failures
- [ ] Implement
- [ ] Test

## Batch 10: Services — Core Business Logic

**Complexity notes for Batch 10:** This batch contains the most complex code in the entire codebase. Key files ranked by difficulty:

- `SaveObject.php` (3,864 lines) — the single largest file in the codebase; handles object creation, update, file attachment, relation cascading, validation, and event dispatch. Extremely high mock count.
- `ObjectService.php` (3,078 lines, 72 imports, 79 methods) — facade service orchestrating all Object handlers. Testing requires mocking 70+ dependencies.
- `RenderObject.php` (2,408 lines) — complex rendering with nested schema resolution, relation expansion, and permission filtering
- `TextExtractionService.php` (2,063 lines) — file content extraction with multiple format handlers
- `OasService.php` (1,826 lines) — OpenAPI spec generation from schemas
- `ValidateObject.php` (1,845 lines) — JSON Schema validation with custom format validators
- `FileService.php` (1,693 lines, 47 imports, 42 methods) — Nextcloud filesystem operations
- `OrganisationService.php` (1,521 lines, 23 imports, 35 methods) — dual org system (profiles vs register objects)
- `CacheHandler.php` (1,984 lines) — multi-layer cache with APCu + Nextcloud cache backends

**Recommended approach for ObjectService:** Test each handler independently (Task 10.1), then test ObjectService's delegation to handlers (Task 10.2) with lightweight mocks — do NOT try to integration-test through ObjectService.

### Task 10.1: Create Object service handler tests
- **files**: `openregister/tests/Unit/Service/Object/AuditHandlerTest.php`, `openregister/tests/Unit/Service/Object/BulkOperationsHandlerTest.php`, `openregister/tests/Unit/Service/Object/CacheHandlerTest.php`, `openregister/tests/Unit/Service/Object/CascadingHandlerTest.php`, `openregister/tests/Unit/Service/Object/CrudHandlerTest.php`, `openregister/tests/Unit/Service/Object/DataManipulationHandlerTest.php`, `openregister/tests/Unit/Service/Object/DeleteObjectTest.php`, `openregister/tests/Unit/Service/Object/ExportHandlerTest.php`, `openregister/tests/Unit/Service/Object/FacetHandlerTest.php`, `openregister/tests/Unit/Service/Object/GetObjectTest.php`, `openregister/tests/Unit/Service/Object/LockHandlerTest.php`, `openregister/tests/Unit/Service/Object/MergeHandlerTest.php`, `openregister/tests/Unit/Service/Object/MetadataHandlerTest.php`, `openregister/tests/Unit/Service/Object/MigrationHandlerTest.php`, `openregister/tests/Unit/Service/Object/PerformanceHandlerTest.php`, `openregister/tests/Unit/Service/Object/PermissionHandlerTest.php`, `openregister/tests/Unit/Service/Object/PublishHandlerTest.php`, `openregister/tests/Unit/Service/Object/QueryHandlerTest.php`, `openregister/tests/Unit/Service/Object/RelationHandlerTest.php`, `openregister/tests/Unit/Service/Object/RenderObjectTest.php`, `openregister/tests/Unit/Service/Object/RevertHandlerTest.php`, `openregister/tests/Unit/Service/Object/SaveObjectsTest.php`, `openregister/tests/Unit/Service/Object/SearchQueryHandlerTest.php`, `openregister/tests/Unit/Service/Object/UtilityHandlerTest.php`, `openregister/tests/Unit/Service/Object/ValidateObjectTest.php`, `openregister/tests/Unit/Service/Object/ValidationHandlerTest.php`, `openregister/tests/Unit/Service/Object/VectorizationHandlerTest.php`
- **source_files**: `openregister/lib/Service/Object/*.php`
- **acceptance_criteria**:
  - Test new object creation vs update of existing
  - Test with and without file properties, relations, cascading
  - Test validation success and failure paths
  - Test lock check and permission check paths
- [ ] Implement
- [ ] Test

### Task 10.2: Create main service tests
- **files**: `openregister/tests/Unit/Service/ApplicationServiceTest.php`, `openregister/tests/Unit/Service/AuthenticationServiceTest.php`, `openregister/tests/Unit/Service/AuthorizationServiceTest.php`, `openregister/tests/Unit/Service/ChatServiceTest.php`, `openregister/tests/Unit/Service/ConditionMatcherTest.php`, `openregister/tests/Unit/Service/DashboardServiceTest.php`, `openregister/tests/Unit/Service/DeepLinkRegistryServiceTest.php`, `openregister/tests/Unit/Service/DownloadServiceTest.php`, `openregister/tests/Unit/Service/EndpointServiceTest.php`, `openregister/tests/Unit/Service/ExportServiceTest.php`, `openregister/tests/Unit/Service/FileServiceTest.php`, `openregister/tests/Unit/Service/HookExecutorTest.php`, `openregister/tests/Unit/Service/IndexServiceTest.php`, `openregister/tests/Unit/Service/LogServiceTest.php`, `openregister/tests/Unit/Service/MappingServiceTest.php`, `openregister/tests/Unit/Service/McpDiscoveryServiceTest.php`, `openregister/tests/Unit/Service/MetricsServiceTest.php`, `openregister/tests/Unit/Service/MigrationServiceTest.php`
- **source_files**: corresponding `openregister/lib/Service/*.php` files
- **acceptance_criteria**: Test all public methods with success + error + edge case paths
- [ ] Implement
- [ ] Test

### Task 10.3: Create remaining main service tests
- **files**: `openregister/tests/Unit/Service/NoteServiceTest.php`, `openregister/tests/Unit/Service/NotificationServiceTest.php`, `openregister/tests/Unit/Service/OasServiceTest.php`, `openregister/tests/Unit/Service/OperatorEvaluatorTest.php`, `openregister/tests/Unit/Service/OrganisationServiceTest.php`, `openregister/tests/Unit/Service/PropertyRbacHandlerTest.php`, `openregister/tests/Unit/Service/RequestScopedCacheTest.php`, `openregister/tests/Unit/Service/RiskLevelServiceTest.php`, `openregister/tests/Unit/Service/SearchTrailServiceTest.php`, `openregister/tests/Unit/Service/SecurityServiceTest.php`, `openregister/tests/Unit/Service/TaskServiceTest.php`, `openregister/tests/Unit/Service/TextExtractionServiceTest.php`, `openregister/tests/Unit/Service/ToolRegistryTest.php`, `openregister/tests/Unit/Service/UploadServiceTest.php`, `openregister/tests/Unit/Service/UserServiceTest.php`, `openregister/tests/Unit/Service/VectorizationServiceTest.php`, `openregister/tests/Unit/Service/ViewServiceTest.php`, `openregister/tests/Unit/Service/WorkflowEngineRegistryTest.php`
- **source_files**: corresponding `openregister/lib/Service/*.php` files
- **acceptance_criteria**: Test all public methods with success + error + edge case paths
- [ ] Implement
- [ ] Test

## Batch 11: GraphQL & Other

### ~~Task 11.1: Create Tool tests~~ **OBSOLETE**
> `lib/Tools/` directory is empty — all Tool classes have been removed from the codebase. This task should be skipped.

### Task 11.1b: Create GraphQL service tests
- **files**: 12 test files for `openregister/lib/Service/GraphQL/*.php`
- **source_files**: `openregister/lib/Service/GraphQL/*.php` (12 files)
- **acceptance_criteria**:
  - Test GraphQL query parsing, schema generation, and resolver delegation
  - Test error handling for malformed queries
  - Test authentication/authorization integration
- [ ] Implement
- [ ] Test

### Task 11.2: Create remaining tests (Notification, Repair, Search, Sections, Settings)
- **files**: `openregister/tests/Unit/Notification/NotifierTest.php`, `openregister/tests/Unit/Repair/RegisterRiskLevelMetadataTest.php`, `openregister/tests/Unit/Search/ObjectsProviderTest.php`, `openregister/tests/Unit/Sections/OpenRegisterAdminTest.php`, `openregister/tests/Unit/Settings/OpenRegisterAdminTest.php`
- **source_files**: corresponding `openregister/lib/*.php` files
- **acceptance_criteria**:
  - Test Notifier with known vs unknown notification type
  - Test ObjectsProvider with results vs no results
  - Test each conditional branch in these classes
- [ ] Implement
- [ ] Test

## Phase 3: Coverage Enforcement

### Task 12.1: Update coverage threshold
- **files**: `openregister/composer.json`
- **acceptance_criteria**:
  - GIVEN all tests pass WHEN `composer coverage:check` is run THEN it reports 100% coverage
  - GIVEN the threshold WHEN checked in composer.json THEN it is set to 100
- **prerequisite**: Task 0.1 (Db exclusion fix) must be completed first, otherwise Db tests won't count toward coverage
- **notes**: 100% coverage target may need adjustment — the 91 Migration files and AppInfo/Application.php are excluded, which is correct. But verify that the GraphQL services (12 files) and any newly added source files are included.
- [ ] Implement
- [ ] Test
