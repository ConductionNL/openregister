---
status: draft
priority: high
estimated_effort: large
---

# Method Decomposition — OpenRegister

## Goal
Eliminate 1,045 PHPMD complexity suppressions by decomposing complex methods into smaller, focused units. Each suppression represents a method or class that exceeds PHPMD's strict thresholds (CC>10, NPath>200, MethodLength>100, ClassLength>1000).

## Current State
- **CyclomaticComplexity suppressions:** 401 (methods with >10 branches)
- **NPathComplexity suppressions:** 241 (methods with >200 execution paths)
- **ExcessiveMethodLength suppressions:** 206 (methods >100 lines)
- **ExcessiveClassComplexity suppressions:** 76 (classes with too much logic)
- **ExcessiveClassLength suppressions:** 37 (classes >1000 lines)
- **CouplingBetweenObjects suppressions:** 72 (too many dependencies)
- **TooManyMethods suppressions:** 12

## Files Requiring Decomposition

### Priority 1 — Highest complexity (files with 5+ suppressions)

**lib/Db/MagicMapper.php** (45 suppressions)
The core database mapper handling dynamic column filtering, sorting, pagination, search, statistics, faceting, and RBAC. Methods handle complex SQL construction for multiple database backends.

**lib/Service/Object/SaveObject.php** (44 suppressions)
Central object persistence service handling relation resolution, cascading saves, metadata hydration, default values, sanitization, write-back logic, and file processing. Contains 20+ complex methods spanning relation detection, inverse relation handling, and cascading sub-object saves.

**lib/Service/Configuration/ImportHandler.php** (24 suppressions)
Handles import of registers, schemas, sources, and configurations from JSON/YAML with complex dependency resolution, cross-reference linking, and error handling.

**lib/Service/SchemaService.php** (23 suppressions)
Schema management including validation, property resolution, inheritance handling, and facet cache management.

**lib/Service/Object/ValidateObject.php** (19 suppressions)
Object validation against JSON Schema with type coercion, format validation, required field checks, and nested property validation.

**lib/Service/Object/SaveObjects.php** (19 suppressions)
Bulk object save orchestration with chunk processing, bulk relation handling, transformation pipelines, and preparation handlers.

**lib/Service/Object/RenderObject.php** (18 suppressions)
Object rendering with dot notation extension, inversed relationship resolution, file property handling, metadata extraction, and multiple output format support.

**lib/Service/ImportService.php** (18 suppressions)
File import service handling CSV, JSON, XML, and Excel formats with column mapping and data transformation.

**lib/Db/SchemaMapper.php** (17 suppressions)
Schema database operations with complex property resolution, inheritance traversal, and facet query building.

**lib/Service/ObjectService.php** (16 suppressions)
High-level object service coordinating save, render, validate, query, and delete operations with caching and event dispatch.

**lib/Service/Object/CacheHandler.php** (15 suppressions)
Object caching service with invalidation strategies, cache warming, and multi-level cache coordination.

**lib/Service/Vectorization/Handlers/VectorSearchHandler.php** (14 suppressions)
Vector similarity search with query embedding, distance calculation, result ranking, and hybrid search strategies.

**lib/Service/Object/ReferentialIntegrityService.php** (14 suppressions)
Enforces referential integrity across object relations with cascade delete, orphan detection, and constraint validation.

**lib/Service/OasService.php** (14 suppressions)
OpenAPI specification generation from register/schema definitions with path, parameter, and response building.

**lib/Service/Settings/ConfigurationSettingsHandler.php** (13 suppressions)
Settings management for register configurations, schema properties, and application-level settings.

**lib/Service/OrganisationService.php** (13 suppressions)
Organisation management with hierarchy resolution, multi-tenancy, and user-organisation relationship handling.

**lib/Service/WebhookService.php** (12 suppressions)
Webhook delivery with retry logic, payload formatting, filter matching, and request interception.

**lib/Service/Index/SetupHandler.php** (12 suppressions)
Search index setup for Solr/Elasticsearch with schema mapping, field configuration, and index lifecycle management.

**lib/Service/Index/DocumentBuilder.php** (12 suppressions)
Document construction for search indexing with field extraction, type conversion, and nested object flattening.

**lib/Db/Schema.php** (12 suppressions)
Schema entity with complex property accessors, validation logic, and inheritance chain resolution.

**lib/Db/ObjectHandlers/MariaDbFacetHandler.php** (12 suppressions)
MariaDB-specific faceted search query building with aggregation, filtering, and multi-value field handling.

**lib/Service/TextExtractionService.php** (11 suppressions)
Text extraction from files (PDF, DOCX, images) with OCR, entity recognition, and structured data extraction.

**lib/Service/SettingsService.php** (11 suppressions)
Application settings management with validation, default values, and type conversion.

**lib/Service/Settings/SolrSettingsHandler.php** (11 suppressions)
Solr-specific settings with connection testing, core management, and schema configuration.

**lib/Service/Object/RelationHandler.php** (11 suppressions)
Relationship resolution with inverse relation filters, batch loading, and circuit breaker logic for performance.

**lib/Service/Configuration/GitHubHandler.php** (11 suppressions)
GitHub integration for configuration import/export with repository browsing, file fetching, and commit creation.

**lib/Service/UserService.php** (10 suppressions)
User management with profile synchronization, group membership, and multi-tenancy user resolution.

**lib/Service/Index/SchemaHandler.php** (10 suppressions)
Search index schema management with field mapping, type resolution, and dynamic field configuration.

**lib/Service/File/FileFormattingHandler.php** (10 suppressions)
File format conversion and rendering with image processing, document conversion, and metadata extraction.

**lib/Db/MagicMapper/MagicSearchHandler.php** (10 suppressions)
Search query building for the magic mapper with full-text search, filter parsing, and relevance scoring.

**lib/Service/Object/SaveObject/FilePropertyHandler.php** (9 suppressions)
File property handling during object save with format detection, security scanning, array file support, and deletion.

**lib/Service/File/FilePublishingHandler.php** (9 suppressions)
File publishing to external storage with access control, URL generation, and CDN integration.

**lib/Service/Configuration/ExportHandler.php** (9 suppressions)
Configuration export to JSON/YAML with dependency resolution and selective export.

**lib/Service/TextExtraction/EntityRecognitionHandler.php** (8 suppressions)
Named entity recognition from extracted text using LLM with entity classification and linking.

**lib/Service/SearchTrailService.php** (8 suppressions)
Search trail tracking and analytics with session management and query logging.

**lib/Service/Object/SearchQueryHandler.php** (8 suppressions)
Search query construction with parameter reconstruction, view merging, and filter normalization.

**lib/Service/Object/PermissionHandler.php** (8 suppressions)
Object-level permission checking with RBAC, organisation scoping, and field-level access control.

**lib/Service/FileService.php** (8 suppressions)
File management service coordinating upload, download, conversion, and metadata operations.

**lib/Service/ExportService.php** (8 suppressions)
Data export to CSV, JSON, XML with field mapping, pagination, and streaming support.

**lib/Db/MagicMapper/MagicRbacHandler.php** (8 suppressions)
Role-based access control query building with permission filtering and organisation scoping.

**lib/Db/AuditTrailMapper.php** (8 suppressions)
Audit trail database operations with complex filtering, aggregation, and retention policies.

**lib/Controller/ObjectsController.php** (8 suppressions)
Objects REST controller with multi-schema search, slug resolution, file extraction, and audit log endpoints.

**lib/Service/Object/SaveObject/RelationCascadeHandler.php** (8 suppressions)
Cascading relation save logic with reference format handling, inverse relation updates, and orphan cleanup.

**lib/Service/Object/SaveObject/MetadataHydrationHandler.php** (7 suppressions)
Metadata hydration from multiple sources including user context, timestamps, and computed fields.

**lib/Service/MappingService.php** (7 suppressions)
Data mapping and transformation with Twig templates, JSONPath, and conditional mapping rules.

**lib/Service/Chat/ResponseGenerationHandler.php** (7 suppressions)
LLM response generation with context injection, tool calling, and streaming support.

**lib/Db/MagicMapper/MagicStatisticsHandler.php** (7 suppressions)
Statistical query building with aggregation functions, grouping, and computed columns.

**lib/Db/MagicMapper/MagicBulkHandler.php** (7 suppressions)
Bulk database operations with batch insert/update, transaction management, and conflict resolution.

**lib/Service/Settings/CacheSettingsHandler.php** (7 suppressions)
Cache settings management with TTL configuration, invalidation rules, and cache backend selection.

### Priority 2 — Medium complexity (files with 3-4 suppressions)

- `lib/Service/VectorizationService.php` (6)
- `lib/Service/Vectorization/Handlers/VectorStorageHandler.php` (6)
- `lib/Service/Schemas/PropertyValidatorHandler.php` (6)
- `lib/Service/Object/DeleteObject.php` (6)
- `lib/Service/GraphQL/SchemaGenerator/TypeMapperHandler.php` (6)
- `lib/Service/ConfigurationService.php` (6)
- `lib/Service/Chat/ConversationManagementHandler.php` (6)
- `lib/Service/Chat/ContextRetrievalHandler.php` (6)
- `lib/Db/ObjectHandlers/MetaDataFacetHandler.php` (6)
- `lib/Db/MagicMapper/MagicFacetHandler.php` (6)
- `lib/Service/Vectorization/VectorEmbeddings.php` (5)
- `lib/Service/TextExtraction/ObjectHandler.php` (5)
- `lib/Service/Object/ValidationHandler.php` (5)
- `lib/Service/Object/QueryHandler.php` (5)
- `lib/Service/Object/PerformanceHandler.php` (5)
- `lib/Service/Index/Backends/Elasticsearch/ElasticsearchDocumentIndexer.php` (5)
- `lib/Service/HookExecutor.php` (5)
- `lib/Service/GraphQL/SchemaGenerator.php` (5)
- `lib/Service/EndpointService.php` (5)
- `lib/Service/Configuration/PreviewHandler.php` (5)
- `lib/Search/ObjectsProvider.php` (5)
- `lib/Db/SearchTrailMapper.php` (5)
- `lib/Db/RegisterMapper.php` (5)
- `lib/Service/Vectorization/Strategies/ObjectVectorizationStrategy.php` (4)
- `lib/Service/SecurityService.php` (4)
- `lib/Service/Object/MergeHandler.php` (4)
- `lib/Service/Object/FacetHandler.php` (4)
- `lib/Service/Object/ExportHandler.php` (4)
- `lib/Service/Object/CascadingHandler.php` (4)
- `lib/Service/Index/BulkIndexer.php` (4)
- `lib/Service/GraphQL/GraphQLResolver.php` (4)
- `lib/Service/File/UpdateFileHandler.php` (4)
- `lib/Service/File/CreateFileHandler.php` (4)
- `lib/Migration/Version1Date20251116000000.php` (4)
- `lib/Listener/WebhookEventListener.php` (4)
- `lib/Db/ObjectHandlers/HyperFacetHandler.php` (4)
- `lib/Command/SolrDebugCommand.php` (4)
- `lib/BackgroundJob/BlobMigrationJob.php` (4)
- `lib/Tool/AbstractTool.php` (3)
- `lib/Service/TaskService.php` (3)
- `lib/Service/Settings/LlmSettingsHandler.php` (3)
- `lib/Service/Schemas/SchemaCacheHandler.php` (3)
- `lib/Service/Object/SaveObjects/TransformationHandler.php` (3)
- `lib/Service/Object/SaveObjects/PreparationHandler.php` (3)
- `lib/Service/Object/SaveObjects/ChunkProcessingHandler.php` (3)
- `lib/Service/Object/PerformanceOptimizationHandler.php` (3)
- `lib/Service/Object/MigrationHandler.php` (3)
- `lib/Service/McpDiscoveryService.php` (3)
- `lib/Service/Index/Backends/Solr/SolrQueryExecutor.php` (3)
- `lib/Service/File/TaggingHandler.php` (3)
- `lib/Service/File/ReadFileHandler.php` (3)
- `lib/Service/File/FileValidationHandler.php` (3)
- `lib/Service/File/DocumentProcessingHandler.php` (3)
- `lib/Service/ChatService.php` (3)
- `lib/Service/AuthorizationService.php` (3)
- `lib/Migration/Version1Date20251202000000.php` (3)
- `lib/Migration/Version1Date20251115000000.php` (3)
- `lib/Migration/Version1Date20251106120000.php` (3)
- `lib/Migration/Version1Date20251106000000.php` (3)
- `lib/Migration/Version1Date20251105150000.php` (3)
- `lib/Migration/Version1Date20251105140000.php` (3)
- `lib/Migration/Version1Date20250622212509.php` (3)
- `lib/Migration/Version1Date20250321061615.php` (3)
- `lib/Listener/FileChangeListener.php` (3)
- `lib/Db/OrganisationMapper.php` (3)
- `lib/Db/ObjectHandlers/MariaDbSearchHandler.php` (3)
- `lib/Db/ObjectEntity.php` (3)
- `lib/Db/MultiTenancyTrait.php` (3)
- `lib/Db/MagicMapper/MagicTableHandler.php` (3)
- `lib/Db/MagicMapper/MagicOrganizationHandler.php` (3)
- `lib/Controller/FileExtractionController.php` (3)
- `lib/Command/MigrateStorageCommand.php` (3)
- `lib/BackgroundJob/HookRetryJob.php` (3)
- `lib/AppInfo/Application.php` (3)

### Priority 3 — Single or double suppressions (2 or fewer)

- `lib/Service/Vectorization/Handlers/VectorStatsHandler.php` (2)
- `lib/Service/Vectorization/Handlers/EmbeddingGeneratorHandler.php` (2)
- `lib/Service/ToolRegistry.php` (2)
- `lib/Service/Settings/ValidationOperationsHandler.php` (2)
- `lib/Service/Object/TranslationHandler.php` (2)
- `lib/Service/Object/SaveObject/ComputedFieldHandler.php` (2)
- `lib/Service/LanguageService.php` (2)
- `lib/Service/Index/WarmupHandler.php` (2)
- `lib/Service/Index/ObjectHandler.php` (2)
- `lib/Service/GraphQL/SubscriptionService.php` (2)
- `lib/Service/GraphQL/GraphQLService.php` (2)
- `lib/Service/File/FolderManagementHandler.php` (2)
- `lib/Service/Chat/ToolManagementHandler.php` (2)
- `lib/Db/Webhook.php` (2)
- `lib/Db/Register.php` (2)
- `lib/Db/Mapping.php` (2)
- `lib/Db/AgentMapper.php` (2)
- `lib/Controller/UserController.php` (2)
- `lib/Controller/Settings/FileSettingsController.php` (2)
- `lib/Controller/GraphQLSubscriptionController.php` (2)
- `lib/Controller/FilesController.php` (2)
- `lib/Command/SolrManagementCommand.php` (2)
- Plus 40+ files with single suppressions (migrations, controllers, mappers, entities)

## Decomposition Strategy

### For CyclomaticComplexity (>10 branches)
Extract conditional branches into private helper methods:
- Guard clauses: Extract early-return validation into `validate{Thing}()` methods
- Switch-like logic: Extract case handlers into `handle{Case}()` methods
- Nested conditions: Flatten by extracting inner blocks into descriptive methods

### For NPathComplexity (>200 paths)
Reduce execution paths by:
- Breaking method into pipeline stages (each stage = private method)
- Extracting independent conditional blocks into separate methods
- Using early returns to eliminate nested paths

### For ExcessiveMethodLength (>100 lines)
Split long methods into logical phases:
- Validation phase -> `validate{Input}()`
- Preparation phase -> `prepare{Data}()`
- Processing phase -> `process{Thing}()`
- Response phase -> `build{Response}()`

### For ExcessiveClassComplexity / ExcessiveClassLength
Extract method groups into Handler classes (existing pattern in codebase):
- Create `{ClassName}/{HandlerName}Handler.php`
- Move related methods to the handler
- Inject handler via constructor
- Delegate from original methods (keep public API stable)

### For CouplingBetweenObjects (>13 dependencies)
Reduce constructor parameters by:
- Grouping related dependencies into a single service
- Using lazy loading for rarely-used dependencies
- Moving methods that use specific deps to handler classes

## Testing Strategy

### Before decomposition
1. Run existing unit tests: `docker exec -w /var/www/html/custom_apps/openregister nextcloud php vendor/bin/phpunit -c phpunit-unit.xml`
2. Note any pre-existing failures
3. Run PHPMD to record current suppression count: `./vendor/bin/phpmd lib/ text phpmd.xml 2>&1 | wc -l`

### During decomposition (per method)
1. Verify `php -l` passes on all changed files
2. Run unit tests for the specific class: `--filter ClassName`
3. Run PHPMD on the specific file to confirm suppression can be removed

### After decomposition
1. Full unit test suite passes
2. PHPMD reports 0 violations (no new warnings)
3. Total suppression count reduced by expected amount
4. `composer check:strict` passes
5. Manual smoke test in browser (http://localhost:3000)

## Acceptance Criteria
- [ ] All CyclomaticComplexity suppressions eliminated or reduced to <=5
- [ ] All NPathComplexity suppressions eliminated or reduced to <=5
- [ ] All ExcessiveMethodLength suppressions eliminated or reduced to <=5
- [ ] ExcessiveClassComplexity reduced by extracting handler classes
- [ ] No new PHPMD violations introduced
- [ ] All existing tests continue to pass
- [ ] No behavioral changes (pure refactoring)
