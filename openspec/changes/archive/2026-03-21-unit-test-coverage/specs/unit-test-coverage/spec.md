---
status: active
---

# Unit Test Coverage

## Purpose

Achieve comprehensive unit test code coverage for all PHP source files in OpenRegister's `lib/` directory (excluding `Migration/` and `AppInfo/Application.php`), targeting 75% line and method coverage as the enforced gate with a stretch goal of 100%. This spec defines the testing standards, mocking strategies, coverage enforcement mechanisms, and per-category test requirements that ensure every code path -- happy flows, error branches, edge cases, and boundary conditions -- is exercised by automated tests. Reliable test coverage is essential for Dutch government deployments where untested features lead to regressions, broken APIs, and failed tender compliance (ref: ADR-009 Mandatory Test Coverage).

## Requirements

### Requirement: Coverage Gate Enforcement at 75% Line and Method Coverage

The project SHALL enforce a minimum 75% line and method coverage threshold via `composer coverage:check`, which runs `scripts/coverage-guard.php` against the Clover XML report. The coverage baseline is stored in `.coverage-baseline` and SHALL NOT decrease between pull requests. When coverage improves, `composer coverage:update` SHALL update the baseline. The CI pipeline SHALL fail any PR that causes coverage to drop below the baseline. The stretch goal is 100% coverage for all in-scope files (~409 source files excluding `lib/Migration/` and `lib/AppInfo/Application.php`).

#### Scenario: Coverage gate blocks regression
- **GIVEN** the current coverage baseline is stored in `.coverage-baseline`
- **WHEN** a pull request introduces code that reduces line coverage below the baseline
- **THEN** `composer coverage:check` SHALL exit with code 1 and print a "FAIL: Coverage dropped" message

#### Scenario: Coverage gate allows improvement
- **GIVEN** the current coverage baseline is 50%
- **WHEN** a pull request increases line coverage to 55%
- **THEN** `composer coverage:check` SHALL exit with code 0 and print "Coverage improved by 5%"

#### Scenario: Coverage baseline update after improvement
- **GIVEN** coverage has improved from 50% to 60%
- **WHEN** `composer coverage:update` is run with the current Clover report
- **THEN** `.coverage-baseline` SHALL be updated to 60.00

#### Scenario: Coverage reports are generated in multiple formats
- **GIVEN** the `phpunit-unit.xml` configuration
- **WHEN** `composer test:coverage` is run inside the Nextcloud container with PCOV enabled
- **THEN** coverage reports SHALL be generated as Clover XML (`coverage/clover.xml`), HTML (`coverage/html/`), and text output to stdout

#### Scenario: Excluded directories do not count against coverage
- **GIVEN** the PHPUnit source configuration excludes `lib/Migration/` and `lib/AppInfo/Application.php`
- **WHEN** coverage is calculated
- **THEN** files in those directories SHALL NOT appear in the coverage report as uncovered

### Requirement: All Unit Tests SHALL Use PHPUnit\Framework\TestCase with Comprehensive Mocking

All unit tests in `tests/Unit/` SHALL extend `PHPUnit\Framework\TestCase` and run with `phpunit-unit.xml` using the `bootstrap-unit.php` bootstrap. No unit test SHALL depend on `Test\TestCase`, Nextcloud server bootstrap, or database connections -- all external dependencies SHALL be mocked using PHPUnit's `createMock()`. Mock typing SHALL use PHPUnit 10 intersection types (`ClassName&MockObject`). Tests SHALL use positional parameters only on all PHPUnit API calls, as PHPUnit 10+ marks all methods with `@no-named-arguments`.

#### Scenario: Test class structure follows established pattern
- **GIVEN** a new test class for `ExampleService`
- **WHEN** the test class is created
- **THEN** it SHALL extend `\PHPUnit\Framework\TestCase`, declare typed mock properties using `ClassName&MockObject`, initialize all mocks in `setUp()`, and construct the service under test with all mocked dependencies matching the constructor signature exactly

#### Scenario: No Nextcloud server dependency in unit tests
- **GIVEN** any test file in `tests/Unit/`
- **WHEN** the test suite runs via `composer test:unit`
- **THEN** no test SHALL require Nextcloud's `lib/base.php`, `IDBConnection`, or any live service -- all SHALL be mocked

#### Scenario: PHPUnit API calls use positional parameters only
- **GIVEN** a test file that calls PHPUnit assertion or mock methods
- **WHEN** the test is authored
- **THEN** all calls to `expects()`, `method()`, `willReturn()`, `with()`, `assertSame()`, `assertEquals()`, etc. SHALL use positional parameters, never named arguments

### Requirement: Test All Code Paths Including Error Branches and Edge Cases

Every public method with branching logic (if/else, switch, try/catch, early returns, null checks, loops) SHALL have tests for each distinct branch. Coverage means every line is executed, so each conditional path needs its own test scenario. This includes: if/else branches (separate test per condition), early returns (test both trigger and continuation), try/catch blocks (success path and exception path via `willThrowException()`), null coalescing and optional params (with value and with null), loops (empty collection, single item, multiple items), and switch/match (each case plus default).

#### Scenario: If/else branches each get a dedicated test
- **GIVEN** a service method with an if/else branch based on input validity
- **WHEN** tests are written for this method
- **THEN** there SHALL be at least one test for the true branch and one test for the false branch, each with descriptive naming like `testMethodNameWithValidInput` and `testMethodNameWithInvalidInput`

#### Scenario: Try/catch exception paths are tested via mock throwing
- **GIVEN** a service method that catches exceptions from a mapper
- **WHEN** tests are written for the exception path
- **THEN** the mapper mock SHALL be configured with `willThrowException(new \Exception('msg'))` and the test SHALL verify the catch block behavior (logging, error return, re-throw)

#### Scenario: Null and empty input edge cases are covered
- **GIVEN** a method that accepts optional parameters
- **WHEN** tests are written
- **THEN** there SHALL be tests with null values, empty strings, empty arrays, and zero values to verify default/fallback behavior

#### Scenario: Loop boundary conditions are tested
- **GIVEN** a method that iterates over a collection
- **WHEN** tests are written
- **THEN** there SHALL be tests with an empty collection (0 items), a single item, and multiple items to cover all loop paths

### Requirement: Use Real Entity Instances, Never Mock Nextcloud Entities

Nextcloud Entity classes use `__call` magic for getters/setters, which PHPUnit 10+ cannot properly mock. All tests SHALL use real entity instances with positional setter arguments. Named arguments on Entity setters are FORBIDDEN because `__call` passes `['name' => val]` but Entity's `setter()` uses `$args[0]`, causing silent data corruption. For entities that need method overrides, use a Testable subclass pattern. The Entity `$id` property is `private` in the parent class and SHALL be set via `ReflectionProperty` in tests.

#### Scenario: Entity created as real instance with positional args
- **GIVEN** a test needs a `Schema` entity with specific field values
- **WHEN** the entity is constructed
- **THEN** it SHALL be created via `new Schema()` with setters using positional arguments (`$schema->setTitle('Test')`, not `$schema->setTitle(title: 'Test')`)

#### Scenario: Entity ID set via Reflection
- **GIVEN** a test needs an entity with a specific ID
- **WHEN** the ID is set
- **THEN** it SHALL use `ReflectionProperty` on the `'id'` field since `$id` is `private` in `\OCP\AppFramework\Db\Entity`

#### Scenario: Broken setter bypassed via ReflectionProperty
- **GIVEN** an entity setter that uses named arguments internally (e.g., `Register::setSchemas()`)
- **WHEN** the test needs to set the field value
- **THEN** it SHALL use `ReflectionProperty` to bypass the broken setter and test the getter separately

#### Scenario: Testable subclass for method overrides
- **GIVEN** a test needs to control entity behavior (e.g., `hasPropertyAuthorization`)
- **WHEN** a mock is not possible due to `__call` magic
- **THEN** the test SHALL define a `TestableClassName extends ClassName` subclass with overridable methods

### Requirement: Use Data Providers for Parameterized Scenarios

When a method accepts variable input and the test logic is the same but values differ, tests SHALL use `#[DataProvider('providerName')]` attributes (PHPUnit 10 style, not `@dataProvider` annotations) with named test cases. This avoids duplicated test methods, makes failure messages descriptive, and enables testing large input spaces efficiently. Event classes, exception classes, format validators, and entity field type tests are prime candidates for DataProvider usage.

#### Scenario: Event classes grouped by CRUD pattern via DataProvider
- **GIVEN** Register entity has Created, Updated, and Deleted events
- **WHEN** tests are written
- **THEN** a single `RegisterEventsTest` SHALL use `#[DataProvider('registerEventProvider')]` to test all three event classes with shared assertion logic (instanceof Event, getter returns same entity)

#### Scenario: Format validator tested with valid and invalid inputs
- **GIVEN** `BsnFormat` validates 9-digit BSN numbers with checksum
- **WHEN** tests are written
- **THEN** a DataProvider SHALL supply named cases: `'valid_bsn'`, `'invalid_checksum'`, `'too_short'`, `'too_long'`, `'non_numeric'`, `'empty_string'`, `'null_input'`

#### Scenario: Entity field types tested across all entities
- **GIVEN** multiple entities have similar getter/setter patterns
- **WHEN** field type tests are parameterized
- **THEN** DataProviders SHALL supply field name, input value, expected output, and type for each field

### Requirement: Verify Side Effects with Mock Expectations

Tests SHALL verify not just return values but also that the correct service/mapper methods are called with the correct arguments. Mock expectations SHALL use `expects($this->once())` for methods that must be called exactly once, `expects($this->never())` for methods that must NOT be called (error/skip paths), `->with($this->equalTo($value))` for exact argument matching, `->with($this->callback(fn($ctx) => ...))` for complex argument assertions, and `->willThrowException()` to simulate failures. The `willReturnCallback()` pattern SHALL be used for dynamic return values.

#### Scenario: Service method calls mapper with correct arguments
- **GIVEN** `ObjectService::getObject()` delegates to `MagicMapper::find()`
- **WHEN** the test calls `getObject(42)`
- **THEN** the mapper mock SHALL have `expects($this->once())->method('find')->with($this->equalTo(42))`

#### Scenario: Error path verifies logger is called
- **GIVEN** a service method catches an exception and logs it
- **WHEN** the exception path is triggered via `willThrowException()`
- **THEN** the logger mock SHALL have `expects($this->once())->method('error')->with($this->stringContains('failed'))`

#### Scenario: Skip path verifies method is never called
- **GIVEN** a controller returns early when input validation fails
- **WHEN** invalid input triggers the early return
- **THEN** the service mock SHALL have `expects($this->never())->method('create')`

### Requirement: Test All Service Classes with Full Branch Coverage (~175 source files)

Service classes contain the bulk of business logic. Tests SHALL cover every public method in every service class and handler. The service layer is organized into: root services (`ObjectService`, `RegisterService`, `SchemaService`, `OrganisationService`, `ConfigurationService`, `WebhookService`, `FileService`, `IndexService`, `ImportService`, `ExportService`, `AuthenticationService`, `AuthorizationService`, `ChatService`, `VectorizationService`, `TextExtractionService`, `GraphQL/GraphQLService`, `Mcp/McpProtocolService`, and ~20 others), plus handler subdirectories (`Object/`, `File/`, `Configuration/`, `Settings/`, `Index/`, `Chat/`, `Schemas/`, `Vectorization/`, `TextExtraction/`, `GraphQL/`, `Mcp/`, `Handler/`). Each handler SHALL be tested for success, failure (mapper throws `DoesNotExistException`, `MultipleObjectsReturnedException`), empty/null input, malformed input, and each if/else/switch branch.

#### Scenario: ObjectService CRUD handlers tested for all operation modes
- **GIVEN** `SaveObject`, `GetObject`, `DeleteObject`, `ValidateObject` and their sub-handlers (`ComputedFieldHandler`, `FilePropertyHandler`, `MetadataHydrationHandler`, `RelationCascadeHandler`)
- **WHEN** operations are performed
- **THEN** each handler SHALL be tested for: new object creation vs update, with/without file properties, with/without relation cascading, validation success and each validation failure rule, lock check (locked vs unlocked), and permission check (authorized vs unauthorized)

#### Scenario: Index backend handlers tested for search and indexing
- **GIVEN** `SolrBackend`, `ElasticsearchBackend` and their sub-handlers in `Backends/Solr/` and `Backends/Elasticsearch/`
- **WHEN** index/search/facet operations are called
- **THEN** tests SHALL cover successful indexing, connection failure (mock HTTP client throws), empty search results, faceted search with/without facet configuration, schema creation/update, and bulk indexing with partial failures

#### Scenario: Configuration service handlers tested for fetch/import/export
- **GIVEN** `FetchHandler`, `ImportHandler`, `ExportHandler`, `GitHubHandler`, `GitLabHandler`, `CacheHandler`, `PreviewHandler`, `UploadHandler`
- **WHEN** configuration operations are performed
- **THEN** tests SHALL cover local vs remote config, config found vs not found, valid vs malformed format, version comparison (newer/older/same), cache hit vs miss, and upload validation

#### Scenario: File service handlers tested for all file operations
- **GIVEN** `CreateFileHandler`, `DeleteFileHandler`, `ReadFileHandler`, `UpdateFileHandler`, `FileCrudHandler`, `FileValidationHandler`, `FolderManagementHandler`, `TaggingHandler`, `FileOwnershipHandler`, `FileSharingHandler`, `FilePublishingHandler`, `DocumentProcessingHandler`, `FileFormattingHandler`
- **WHEN** file operations are requested
- **THEN** tests SHALL cover file found vs not found, valid vs rejected file type, folder exists vs needs creation, user-owned vs shared vs system file, and file with/without tags

#### Scenario: GraphQL service tested for schema generation and query resolution
- **GIVEN** `GraphQLService`, `GraphQLResolver`, `SchemaGenerator`, `TypeMapperHandler`, `CompositionHandler`, `QueryComplexityAnalyzer`, `GraphQLErrorFormatter`, `SubscriptionService`, and scalar types (`DateTimeType`, `EmailType`, `JsonType`, `UploadType`, `UriType`, `UuidType`)
- **WHEN** GraphQL operations are performed
- **THEN** tests SHALL cover schema generation from OpenRegister schemas, query resolution with mocked data, mutation handling, subscription lifecycle, scalar type parsing/serialization, complexity analysis thresholds, and error formatting

### Requirement: Test All Controller Classes with CRUD and Error Handling (~46 root + 12 Settings)

Controller tests SHALL verify that each CRUD action (`index`, `show`, `create`, `update`, `destroy`) returns the correct `JSONResponse` with appropriate HTTP status codes. Error handling SHALL be tested by configuring service mocks to throw `\Exception`, `ValidationException`, `NotAuthorizedException`, `NotFoundException`, and verifying the controller returns 400, 403, 404, or 500 responses with descriptive error messages. Authorization checks SHALL be tested by mocking `IUserSession` for unauthorized users and verifying 403 responses. Input validation SHALL be tested with missing required params, wrong types, and empty values.

#### Scenario: Controller index action returns paginated results
- **GIVEN** `ObjectsController::index()` is called with valid pagination parameters
- **WHEN** the underlying service returns a list of objects
- **THEN** the controller SHALL return a `JSONResponse` with HTTP 200 and the list data

#### Scenario: Controller create action returns 201 on success
- **GIVEN** `RegistersController::create()` is called with valid register data
- **WHEN** the service successfully creates the register
- **THEN** the controller SHALL return HTTP 201 with the created entity data

#### Scenario: Controller handles service exception with 500
- **GIVEN** any controller action
- **WHEN** the underlying service throws an unhandled `\Exception`
- **THEN** the controller SHALL return HTTP 500 with an error message and the error SHALL be logged

#### Scenario: Controller handles not found with 404
- **GIVEN** `SchemasController::show()` is called with a non-existent ID
- **WHEN** the service throws `DoesNotExistException`
- **THEN** the controller SHALL return HTTP 404

#### Scenario: Controller handles unauthorized access with 403
- **GIVEN** a controller action with RBAC or organisation-scoped access
- **WHEN** called by an unauthorized user (mocked `IUserSession`)
- **THEN** the controller SHALL return HTTP 403

### Requirement: Test All Db Entities and Mapper Handlers with Full Field Coverage (~65 source files)

Entity tests SHALL cover constructor defaults, getter/setter round-trips for all field types (string, int, bool, DateTime, JSON arrays), `jsonSerialize()` output with all fields populated and with null optional fields, `__toString()` fallback chains, and any business methods. Mapper handler tests (MagicMapper handlers: `MagicBulkHandler`, `MagicFacetHandler`, `MagicOrganizationHandler`, `MagicRbacHandler`, `MagicSearchHandler`; and ObjectEntity handlers: `BulkOperationsHandler`, `CrudHandler`, `FacetsHandler`, `LockingHandler`, `QueryBuilderHandler`, `QueryOptimizationHandler`, `StatisticsHandler`) SHALL test query building with different filter combinations, empty filters, invalid filters, and edge cases. NOTE: `lib/Db/` is currently excluded from coverage in `phpunit-unit.xml` -- this exclusion MUST be narrowed to only auto-generated mappers or removed entirely for Db tests to count toward coverage.

#### Scenario: Entity default values verified after construction
- **GIVEN** any Db entity (e.g., `Register`, `Schema`, `ObjectEntity`, `Organisation`, `Agent`, `Application`, `Configuration`)
- **WHEN** constructed with no arguments
- **THEN** all fields SHALL have their documented default values and `getId()` SHALL return null

#### Scenario: Entity JSON serialization includes all fields
- **GIVEN** an entity with all fields populated including DateTime and JSON array fields
- **WHEN** `jsonSerialize()` is called
- **THEN** all fields SHALL appear in the returned array with correct types, DateTime fields SHALL use ISO 8601 format (`->format('c')`), and null optional fields SHALL serialize as null

#### Scenario: MagicMapper RBAC handler applies correct query filters
- **GIVEN** `MagicRbacHandler` with a user who has restricted data access
- **WHEN** query building methods are called
- **THEN** the generated SQL SHALL include the correct WHERE clauses for RBAC filtering and parameter bindings SHALL match

#### Scenario: ObjectEntity handlers tested for locked and unlocked states
- **GIVEN** `LockingHandler` with a locked object
- **WHEN** an update operation is attempted
- **THEN** the handler SHALL throw `LockedException` and the lock metadata SHALL be preserved

### Requirement: Test All Event Classes via DataProvider Grouping (~39 source files)

Event classes follow a predictable CRUD pattern per entity type. Tests SHALL group events using DataProviders: single-entity events (Created/Deleted) verify Event inheritance and entity getter; Updated events verify both old and new entity retrieval; special events (`DeepLinkRegistrationEvent`, `ToolRegistrationEvent`, `UserProfileUpdatedEvent`) are tested with dedicated methods. The following entity event families SHALL be covered: Register, Schema, Object (including Creating, Updating, Deleting, Locked, Unlocked, Reverted), Agent, Application, Configuration, Conversation, Organisation, Source, View.

#### Scenario: CRUD events for each entity type pass DataProvider test
- **GIVEN** `RegisterCreatedEvent`, `RegisterUpdatedEvent`, `RegisterDeletedEvent`
- **WHEN** each is constructed with a real Register entity
- **THEN** each SHALL be an instance of `\OCP\EventDispatcher\Event` and the getter SHALL return the exact same entity instance

#### Scenario: Updated events expose both old and new entities
- **GIVEN** `SchemaUpdatedEvent` constructed with a new Schema and an old Schema
- **WHEN** getters are called
- **THEN** `getSchema()` SHALL return the new entity and `getOldSchema()` SHALL return the old entity, and they SHALL be different instances

#### Scenario: Object events cover all lifecycle stages
- **GIVEN** Object has 9 event classes: Created, Creating, Updated, Updating, Deleted, Deleting, Locked, Unlocked, Reverted
- **WHEN** each is constructed and tested
- **THEN** all 9 SHALL pass construction and getter assertions

### Requirement: Test All BackgroundJob, Command, Cron, and Listener Classes

BackgroundJob classes (`BlobMigrationJob`, `CacheWarmupJob`, `CronFileTextExtractionJob`, `FileTextExtractionJob`, `HookRetryJob`, `NameCacheWarmupJob`, `ObjectTextExtractionJob`, `SolrNightlyWarmupJob`, `SolrWarmupJob`, `WebhookDeliveryJob`) SHALL have `run()` tested with valid arguments, missing arguments (log warning, return gracefully), and service exceptions (catch, log error). Command classes (`MigrateStorageCommand`, `SolrDebugCommand`, `SolrManagementCommand`) SHALL have `execute()` tested with mocked `InputInterface`/`OutputInterface` for valid arguments, missing arguments, and service exceptions. Cron classes (`ConfigurationCheckJob`, `LogCleanUpTask`, `SyncConfigurationsJob`, `WebhookRetryJob`) SHALL have `run()` tested for success and exception handling. Listener classes (`CommentsEntityListener`, `FileChangeListener`, `GraphQLSubscriptionListener`, `HookListener`, `ObjectChangeListener`, `ObjectCleanupListener`, `ToolRegistrationListener`, `WebhookEventListener`) SHALL have `handle()` tested with matching events, non-matching events, and service exceptions (graceful handling, no re-throw).

#### Scenario: BackgroundJob handles missing arguments gracefully
- **GIVEN** `WebhookDeliveryJob::run()` is called with an empty argument array
- **WHEN** the job executes
- **THEN** it SHALL log a warning via the logger mock and return without throwing

#### Scenario: BackgroundJob handles service exception
- **GIVEN** `CacheWarmupJob::run()` is called and the underlying service throws
- **WHEN** the exception propagates to the job
- **THEN** the job SHALL catch it and log the error via `$this->mockLogger->expects($this->once())->method('error')`

#### Scenario: Command returns non-zero exit code on error
- **GIVEN** `SolrManagementCommand::execute()` is called with valid arguments
- **WHEN** the underlying service throws an exception
- **THEN** the command SHALL write an error message to the output mock and return a non-zero exit code

#### Scenario: Listener handles matching event by calling service
- **GIVEN** `WebhookEventListener::handle()` receives an `ObjectCreatedEvent`
- **WHEN** the event matches the listener's registered type
- **THEN** the webhook service mock SHALL be called with the event data

#### Scenario: Listener handles service exception gracefully
- **GIVEN** `FileChangeListener::handle()` receives a matching event but the service throws
- **WHEN** the exception occurs during handling
- **THEN** the listener SHALL catch it and log the error, NOT re-throw it

### Requirement: Test All Exception and Format Classes

Custom exception classes (`ValidationException`, `LockedException`, `NotAuthorizedException`, `DatabaseConstraintException`, `RegisterNotFoundException`, `SchemaNotFoundException`, `CustomValidationException`, `ReferentialIntegrityException`, `AuthenticationException`, `HookStoppedException`) SHALL be tested for construction with message, code, and optional previous exception, correct inheritance hierarchy, and any custom methods (e.g., `getValidationErrors()` on `ValidationException`). Format validators (`BsnFormat`, `SemVerFormat`) SHALL be tested with DataProviders covering all valid and invalid input categories.

#### Scenario: ValidationException carries structured validation errors
- **GIVEN** a `ValidationException` constructed with a message and validation error array
- **WHEN** `getValidationErrors()` is called
- **THEN** it SHALL return the exact error array passed to the constructor

#### Scenario: BSN format validates checksum algorithm correctly
- **GIVEN** a DataProvider with BSN test cases
- **WHEN** `BsnFormat::validate()` is called with each case
- **THEN** valid 9-digit BSNs with correct 11-proof checksum SHALL pass, and invalid checksums, wrong lengths, non-numeric input, and empty input SHALL fail

#### Scenario: SemVer format validates version strings per SemVer 2.0.0
- **GIVEN** a DataProvider with version strings
- **WHEN** `SemVerFormat::validate()` is called
- **THEN** `"1.0.0"`, `"0.0.0"`, `"1.2.3-alpha"`, `"1.2.3+build"` SHALL be valid, and `"1.0"`, `"v1.0.0"`, `"1.0.0.0"`, `""` SHALL be invalid

### Requirement: Test Organisation Service Multi-Tenancy Paths

`OrganisationService` with its membership, caching, and settings logic SHALL be tested for all multi-tenancy scenarios. This is critical for Dutch government deployments where organisation isolation is a security requirement. Tests SHALL cover user joining/leaving organisations, active organisation switching, cache behavior, and default organisation fallback.

#### Scenario: User joins organisation successfully
- **GIVEN** a user who is not a member of organisation X
- **WHEN** `joinOrganisation()` is called
- **THEN** the mapper SHALL be called to create the membership and the cache SHALL be invalidated

#### Scenario: User attempts to join already-joined organisation
- **GIVEN** a user who is already a member of organisation X
- **WHEN** `joinOrganisation()` is called again
- **THEN** the service SHALL return without creating a duplicate membership

#### Scenario: Last member leaves organisation
- **GIVEN** an organisation with only one member
- **WHEN** that member calls `leaveOrganisation()`
- **THEN** the service SHALL handle this edge case according to policy (prevent or allow with warning)

#### Scenario: Active organisation cache expires
- **GIVEN** a user with a cached active organisation
- **WHEN** the cache TTL expires
- **THEN** the next access SHALL re-fetch from the session/database and update the cache

#### Scenario: Default organisation fallback when none set
- **GIVEN** a user with no active organisation set
- **WHEN** `getActiveOrganisation()` is called
- **THEN** the service SHALL fall back to the default organisation or return null if none exists

### Requirement: Test Webhook Service Delivery and Retry Logic

`WebhookService` and `CloudEventFormatter` SHALL be tested for delivery success and failure paths, retry logic, and CloudEvents format compliance. This ensures reliable event notification delivery to external systems.

#### Scenario: Webhook delivery succeeds on first attempt
- **GIVEN** a webhook subscription and an event to deliver
- **WHEN** the HTTP client returns 200
- **THEN** the delivery SHALL be marked as successful and no retry SHALL be scheduled

#### Scenario: Webhook delivery fails with HTTP 500
- **GIVEN** a webhook delivery attempt
- **WHEN** the HTTP client returns 500
- **THEN** a retry SHALL be scheduled via `WebhookDeliveryJob` and the failure SHALL be logged

#### Scenario: Webhook delivery retries exhausted
- **GIVEN** a webhook that has been retried the maximum number of times
- **WHEN** the next retry also fails
- **THEN** the delivery SHALL be marked as permanently failed and no further retries SHALL be scheduled

#### Scenario: CloudEvents format is correct
- **GIVEN** an `ObjectCreatedEvent` to format
- **WHEN** `CloudEventFormatter::format()` is called
- **THEN** the output SHALL contain `specversion`, `type`, `source`, `id`, `time`, and `data` fields per the CloudEvents 1.0 spec

### Requirement: Test Import and Export Service Handlers

`ImportService` and `ExportService` handle bulk data operations critical for government data migration workflows. Tests SHALL cover CSV, JSON, and XLSX import/export paths including validation, transformation, error handling, and partial failure recovery.

#### Scenario: CSV import with valid data
- **GIVEN** a CSV file with headers matching a schema's properties
- **WHEN** `ImportService::import()` is called
- **THEN** objects SHALL be created for each valid row and the import summary SHALL report success count

#### Scenario: Import with validation errors on some rows
- **GIVEN** a CSV file where 3 of 10 rows fail schema validation
- **WHEN** the import is processed
- **THEN** valid rows SHALL be imported, invalid rows SHALL be collected as errors, and the summary SHALL report both counts

#### Scenario: Export to JSON produces valid output
- **GIVEN** a register with 100 objects
- **WHEN** `ExportService::export()` is called with format 'json'
- **THEN** the output SHALL be valid JSON containing all objects serialized per their schema

### Requirement: CI Integration with composer check:strict

All unit tests SHALL pass as part of `composer check:strict`, which runs `lint`, `phpcs`, `phpmd`, `psalm`, `phpstan`, and `test:all` in sequence. The `test:unit` script runs `phpunit --testsuite="Unit Tests"` against the `tests/Unit/` directory. Tests SHALL also be executable inside the Docker container via `docker exec -w /var/www/html/custom_apps/openregister nextcloud php vendor/bin/phpunit -c phpunit-unit.xml`. Coverage measurement requires `php-pcov` installed in the container.

#### Scenario: All unit tests pass in check:strict pipeline
- **GIVEN** the full `composer check:strict` pipeline
- **WHEN** it reaches the `test:all` step
- **THEN** all unit tests SHALL pass with 0 errors and 0 failures

#### Scenario: Unit tests run in Docker container
- **GIVEN** the Nextcloud Docker container with OpenRegister mounted
- **WHEN** `docker exec -w /var/www/html/custom_apps/openregister nextcloud php vendor/bin/phpunit -c phpunit-unit.xml` is run
- **THEN** all unit tests SHALL pass

#### Scenario: Coverage measurement with PCOV
- **GIVEN** `php-pcov` is installed in the container
- **WHEN** `php -d pcov.enabled=1 -d pcov.directory=/var/www/html/custom_apps/openregister/lib vendor/bin/phpunit -c phpunit-unit.xml --coverage-clover=coverage/clover.xml` is run
- **THEN** a valid Clover XML report SHALL be generated with line-level coverage data

#### Scenario: Specific tests can be filtered
- **GIVEN** a developer working on `ObjectService`
- **WHEN** `phpunit -c phpunit-unit.xml --filter ObjectServiceTest` is run
- **THEN** only `ObjectServiceTest` tests SHALL execute, enabling fast feedback loops

### Requirement: Test Naming Convention and File Organization

Test methods SHALL follow `test[MethodOrBehavior][Scenario]` naming (e.g., `testCreateObjectWithValidData`, `testDeleteObjectWhenLocked`, `testGetObjectNotFound`). Test files SHALL mirror the `lib/` directory structure under `tests/Unit/` (e.g., `lib/Service/Object/SaveObject.php` maps to `tests/Unit/Service/Object/SaveObjectTest.php`). Test classes SHALL be named `[ClassName]Test`.

#### Scenario: Test naming is descriptive and follows convention
- **GIVEN** a test for `OrganisationService::joinOrganisation()` error handling
- **WHEN** the test method is named
- **THEN** it SHALL be named `testJoinOrganisationWhenAlreadyMember` or similar pattern that describes the method, scenario, and expected behavior

#### Scenario: Test file mirrors source file path
- **GIVEN** source file `lib/Service/Configuration/GitHubHandler.php`
- **WHEN** the test file is created
- **THEN** it SHALL be located at `tests/Unit/Service/Configuration/GitHubHandlerTest.php`

#### Scenario: DataProvider methods are named descriptively
- **GIVEN** a DataProvider for BSN validation test cases
- **WHEN** the provider method is named
- **THEN** it SHALL be named `bsnValidationProvider` or `validAndInvalidBsnProvider` and each case SHALL have a descriptive string key

### Requirement: Use Reflection for Private Methods and Final Classes

When a public method delegates to private helpers that contain complex logic worth testing individually, `ReflectionClass` SHALL be used to access them. When a class is declared `final` (e.g., `Twig\Loader\ArrayLoader`), tests SHALL use real instances rather than mocks. This applies to all `final` Nextcloud or vendor classes encountered during testing.

#### Scenario: Private method tested via Reflection
- **GIVEN** a service with a private helper method containing complex validation logic
- **WHEN** the test needs to verify the private method directly
- **THEN** it SHALL use `$reflection = new \ReflectionClass($service); $method = $reflection->getMethod('methodName'); $method->setAccessible(true); $result = $method->invoke($service, $args);`

#### Scenario: Final class used as real instance
- **GIVEN** a service depends on `Twig\Loader\ArrayLoader` which is `final`
- **WHEN** the test initializes the Twig environment
- **THEN** it SHALL use `new ArrayLoader(['template' => 'content'])` instead of `$this->createMock(ArrayLoader::class)`

#### Scenario: Private property accessed for assertion
- **GIVEN** a test needs to verify internal state after an operation
- **WHEN** the state is stored in a private property
- **THEN** `ReflectionProperty` SHALL be used with `setAccessible(true)` to read the value

### Requirement: Resolve phpunit-unit.xml Db Exclusion for Accurate Coverage

The current `phpunit-unit.xml` excludes `lib/Db/` from coverage measurement, which means Entity, Mapper, and Handler tests (65+ source files) do not count toward coverage metrics. This exclusion SHALL be narrowed to only exclude auto-generated or trivial files, or removed entirely. The `lib/Db/MagicMapper/` handlers and `lib/Db/ObjectHandlers/` contain significant business logic that MUST be included in coverage measurement.

#### Scenario: Db handler tests contribute to coverage
- **GIVEN** the `phpunit-unit.xml` source exclusion is updated
- **WHEN** `MagicRbacHandlerTest` runs with coverage enabled
- **THEN** `lib/Db/MagicMapper/MagicRbacHandler.php` lines SHALL appear in the coverage report

#### Scenario: Simple entity files are included in coverage
- **GIVEN** entity files like `Register.php`, `Schema.php`, `ObjectEntity.php`
- **WHEN** their corresponding tests run with coverage enabled
- **THEN** entity getter/setter/jsonSerialize lines SHALL be counted in the coverage report

#### Scenario: Only Migration directory remains excluded
- **GIVEN** the updated `phpunit-unit.xml`
- **WHEN** the source exclusion list is reviewed
- **THEN** only `lib/Migration/` and `lib/AppInfo/Application.php` SHALL be excluded, matching the original spec intent

## Estimated Scope

| Category | Source Files | Test Files (existing) | Test Files (needed) | Status |
|---|---|---|---|---|
| Event | 39 | 5 | 0 | Complete (DataProvider grouping) |
| Exception | 10 | 2 | ~1 | 8 uncovered exceptions |
| Formats | 2 | 1 | 0 | SemVer fix needed |
| Db entities + mappers + handlers | 65 | 31 | ~15 | 34 uncovered |
| Controller (root + Settings) | 58 | 78 | ~5 | Nearly complete |
| Service (root + subdirectories) | 175 | 147 | ~28 | Core handlers pending |
| BackgroundJob | 10 | 8 | ~2 | 2 uncovered |
| Command | 3 | 4 | 0 | Complete |
| Cron | 4 | 4 | 0 | Complete |
| Listener | 8 | 7 | ~1 | 1 uncovered |
| GraphQL | 12 | 0 | ~6 | Not yet started |
| Notification/Repair/Search/Settings | 5 | ~5 | 0 | Covered |
| **Total in scope** | **~409** | **~317** | **~58** | |
| Migration (excluded) | 91 | 0 | 0 | Out of scope |
| AppInfo/Application.php (excluded) | 1 | 0 | 0 | Out of scope |

## Standards and References

- **PHPUnit 10.5+** testing framework with `#[DataProvider]` attributes and intersection mock types
- **PHP PCOV** extension for code coverage (faster than Xdebug)
- **ADR-009: Mandatory Test Coverage** -- every new or changed backend feature MUST have corresponding unit tests; 75% coverage target for new code
- **Related spec: `api-test-coverage`** -- covers Newman/Postman API-level testing (complementary to this spec)
- **PSR-4 autoloading** for test namespaces matching `lib/` structure
- **Nextcloud app testing guidelines** -- tests run inside Docker container with full Nextcloud environment for integration, PHPUnit\Framework\TestCase only for unit

## Specificity Assessment

- **Specific enough to implement?** Yes -- explicit patterns, naming conventions, file-by-file scope, and categorized batches
- **Open questions:**
  - Should `lib/Db/` exclusion be fully removed or narrowed? (Recommendation: narrow to exclude only auto-generated mapper boilerplate)
  - Timeline for reaching 100% from current baseline? (Depends on ~58 remaining test files)
  - Should integration tests (requiring database/container) count toward the 75% gate? (Recommendation: no, keep unit and integration metrics separate)
