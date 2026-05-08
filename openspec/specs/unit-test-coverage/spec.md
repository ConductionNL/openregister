---
status: active
---

# Unit Test Coverage to 100%

Achieve 100% unit test code coverage for all PHP source files in `lib/` (excluding `Migration/` and `AppInfo/Application.php`). Tests SHALL exercise every code path — not just the happy flow, but all branches, error paths, edge cases, and boundary conditions.

## Current State

- **Phase 1 COMPLETE**: All 314 errors + 2 failures fixed — **1,121 tests pass** with 0 errors, 0 failures
- **361 source files** in scope, **30 test files** exist
- Coverage threshold is set at 75% (`composer coverage:check`)
- Phase 2 (write ~136 new test files for ~330 untested source files) is planned

## Testing Standards

All unit tests SHALL follow the conventions established in the existing codebase.

### Requirement: Use PHPUnit\Framework\TestCase with comprehensive mocking

All unit tests in `tests/Unit/` SHALL extend `PHPUnit\Framework\TestCase` and run with `phpunit-unit.xml` using the minimal `bootstrap-unit.php`. No test SHALL depend on `Test\TestCase`, Nextcloud server bootstrap, or database connections — all external dependencies SHALL be mocked.

**Established mock pattern** (from `MagicMapperTest`, `SettingsControllerTest`, `FileTextExtractionJobTest`):

```php
class ExampleServiceTest extends \PHPUnit\Framework\TestCase
{
    private ExampleService $service;
    private SomeDependency&MockObject $mockDependency;
    private LoggerInterface&MockObject $mockLogger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockDependency = $this->createMock(SomeDependency::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->service = new ExampleService(
            $this->mockDependency,
            $this->mockLogger,
        );
    }
}
```

### Requirement: Test all code paths, not just the happy flow

Every public method with branching logic (if/else, switch, try/catch, early returns, null checks) SHALL have tests for each branch. Coverage means every line is executed, so each conditional path needs its own test scenario.

**What "all paths" means per method:**

- **If/else branches**: Separate test for each branch condition
- **Early returns**: Test the condition that triggers the early return AND the condition that continues
- **Try/catch blocks**: Test both the success path and the exception path (using `willThrowException()` on mocks)
- **Null coalescing / optional params**: Test with value present AND with null/missing value
- **Loops**: Test with empty collection, single item, and multiple items
- **Switch/match**: Test each case and the default

**Established pattern** (from `ConfigurationServiceTest`):
```php
// Test multiple branches of the same method
public function testHasUpdateAvailable(): void {
    $config = new Configuration();

    // Branch 1: No remote version → false
    $config->setLocalVersion('1.0.0');
    $config->setRemoteVersion(null);
    $this->assertFalse($config->hasUpdateAvailable());

    // Branch 2: Same version → false
    $config->setRemoteVersion('1.0.0');
    $this->assertFalse($config->hasUpdateAvailable());

    // Branch 3: Newer remote → true
    $config->setRemoteVersion('1.1.0');
    $this->assertTrue($config->hasUpdateAvailable());
}
```

### Requirement: Use data providers for parameterized scenarios

When a method accepts variable input and the test logic is the same but values differ, use `#[DataProvider]` attributes with named test cases. This avoids duplicated test methods and makes failures descriptive.

**Established pattern** (from `MagicMapperTest`):
```php
#[DataProvider('registerSchemaTableNameProvider')]
public function testGetTableNameForRegisterSchema(
    int $registerId, int $schemaId, string $expected
): void {
    $result = $this->magicMapper->getTableName($registerId, $schemaId);
    $this->assertEquals($expected, $result);
}

public static function registerSchemaTableNameProvider(): array {
    return [
        'basic_combination' => [1, 1, 'oc_openregister_table_1_1'],
        'different_ids' => [5, 12, 'oc_openregister_table_5_12'],
        'zero_ids' => [0, 0, 'oc_openregister_table_0_0'],
    ];
}
```

### Requirement: Verify side effects with mock expectations

Tests SHALL verify not just return values but also that the correct service/mapper methods are called with the correct arguments. Use `expects()`, `with()`, and `willReturn()` / `willThrowException()` chains.

**Key expectation patterns** (from `FileTextExtractionJobTest`, `SettingsControllerTest`):

- `expects($this->once())` — method must be called exactly once
- `expects($this->never())` — method must NOT be called (for skip/error paths)
- `expects($this->atLeastOnce())` — called one or more times
- `->with($this->equalTo($value))` — verify arguments
- `->with($this->stringContains('partial'))` — partial argument matching
- `->with($this->callback(fn($ctx) => $ctx['key'] === 'val'))` — complex argument assertions
- `->willThrowException(new \Exception('msg'))` — simulate failures
- `->willReturnCallback(function($arg) { ... })` — dynamic return values

### Requirement: Use real Entity instances, never mock Nextcloud entities

Nextcloud Entity classes use `__call` magic for getters/setters. PHPUnit 10+ cannot properly mock `__call`-based methods. All tests SHALL use real entity instances with setters instead of mocking entities.

**Critical rule:** NEVER use named arguments on Entity setters — `__call` passes `['name' => val]` but Entity's `setter()` uses `$args[0]`.

```php
// CORRECT — real instance with positional args
$schema = new Schema();
$schema->setTitle('Test Schema');
$schema->setProperties(json_encode([['title' => 'name', 'type' => 'string']]));

// WRONG — mock (breaks __call magic)
$schema = $this->createMock(Schema::class);
$schema->method('getTitle')->willReturn('Test Schema');

// WRONG — named arg (breaks __call)
$schema->setTitle(title: 'Test Schema');
```

**For entities that need method overrides** (e.g., to control `hasPropertyAuthorization`), use a Testable subclass:

```php
class TestableSchema extends Schema {
    private bool $hasAuth = true;
    public function setHasPropertyAuthorization(bool $v): void { $this->hasAuth = $v; }
    public function hasPropertyAuthorization(string $p): bool { return $this->hasAuth; }
}
```

### Requirement: Use real ArrayLoader instances (final class)

`Twig\Loader\ArrayLoader` is declared `final` and cannot be mocked. Tests that need a Twig loader SHALL use a real `ArrayLoader` instance.

### Requirement: No named parameters on PHPUnit API calls

PHPUnit 10+ marks all API methods with `@no-named-arguments`. Tests SHALL use positional parameters only on all PHPUnit method calls (`expects`, `method`, `willReturn`, `with`, `assertSame`, `assertEquals`, etc.).

```php
// CORRECT
$mock->expects($this->once())->method('save')->willReturn($entity);
$this->assertSame('expected', $result);

// WRONG — named parameters
$mock->expects(constraint: $this->once());
$this->assertSame(expected: 'expected', actual: $result);
```

### Requirement: Use Reflection for private methods when necessary

When a public method delegates to private helpers that contain complex logic worth testing individually, use `ReflectionClass` to access them.

**Established pattern** (from `MagicMapperTest`):
```php
$reflection = new \ReflectionClass($this->service);
$method = $reflection->getMethod('privateMethodName');
$method->setAccessible(true);
$result = $method->invoke($this->service, $arg1, $arg2);
```

### Requirement: Test naming convention

Test methods SHALL follow `test[MethodOrBehavior][Scenario]` naming:
- `testCreateOrganisationWithValidData` — happy path
- `testCreateOrganisationWithEmptyName` — validation failure
- `testCreateOrganisationWhenMapperThrows` — exception handling
- `testDeleteOrganisationAsLastMember` — edge case

## Phase 1: Fix Broken Existing Tests (COMPLETE)

All 316 test failures have been resolved. **1,121 tests now pass with 0 errors, 0 failures.** The fixes fell into 4 categories.

### Requirement: Fix OrganisationService constructor mismatch (245 errors)

Four test files instantiate `OrganisationService` with the wrong number/type of constructor arguments. The constructor expects 9 parameters but tests pass 3-4.

**Constructor signature** (`lib/Service/OrganisationService.php`):
```php
__construct(
    OrganisationMapper $organisationMapper,
    IUserSession $userSession,
    ISession $session,
    IConfig $config,
    IAppConfig $appConfig,
    IGroupManager $groupManager,
    IUserManager $userManager,
    LoggerInterface $logger,
    ?SettingsService $settingsService = null
)
```

**Affected files:**
- `tests/Unit/Service/OrganisationCrudTest.php` (11 errors)
- `tests/Unit/Service/PerformanceScalabilityTest.php` (6 errors)
- `tests/Unit/Service/SessionCacheManagementTest.php` (4 errors)
- `tests/Unit/Service/UserOrganisationRelationshipTest.php` (10 errors)
- Plus ~214 errors in other Organisation-related test files

#### Scenario: Tests create OrganisationService with correct mocks

- **WHEN** each affected test file's `setUp()` method creates an `OrganisationService`
- **THEN** it SHALL mock and pass all 9 constructor parameters in the correct order and with correct types
- **AND** all OrganisationService-related tests SHALL pass without TypeError

### Requirement: Fix missing class references (41 errors)

Tests mock classes that no longer exist in the codebase due to refactoring.

#### Scenario: Update GuzzleSolrService reference (32 errors)

- **GIVEN** `SettingsServiceTest.php` mocks `OCA\OpenRegister\Service\GuzzleSolrService` which does not exist
- **WHEN** the test setUp creates service mocks
- **THEN** it SHALL use the current class name (likely `IndexService` or `SolrBackend`) or remove the mock if the dependency was eliminated
- **AND** the `SettingsService` constructor signature SHALL be checked and all mocks SHALL match it exactly

#### Scenario: Update PublishObject reference (8 errors)

- **GIVEN** `ObjectServiceRefactoredMethodsTest.php` mocks `OCA\OpenRegister\Service\Object\PublishObject` which does not exist
- **WHEN** the test setUp creates service mocks
- **THEN** it SHALL use `PublishHandler` (the current class name) or whichever class replaced it
- **AND** the `ObjectService` constructor signature SHALL be verified and all mocks SHALL match

#### Scenario: Fix VectorEmbeddingServiceTest base class (1 error)

- **GIVEN** `VectorEmbeddingServiceTest.php` extends `Test\TestCase` (Nextcloud integration base)
- **WHEN** this test runs in the unit test suite (which has no Nextcloud autoloader)
- **THEN** it SHALL extend `PHPUnit\Framework\TestCase` instead, with all dependencies mocked

### Requirement: Fix SemVer format validation (2 failures)

#### Scenario: Valid semver versions are accepted

- **GIVEN** the `SemVerFormat` validator in `lib/Formats/SemVerFormat.php`
- **WHEN** validating standard versions like `"1.0.0"` and `"0.0.0"`
- **THEN** they SHALL be marked as valid
- **AND** the regex/validation logic SHALL be corrected to match the SemVer 2.0.0 specification

#### Scenario: Invalid semver versions are rejected

- **WHEN** validating strings like `"1.0"`, `"v1.0.0"`, `"1.0.0.0"`, `"abc"`, `""`
- **THEN** they SHALL be marked as invalid

## Phase 2: Test Untested Source Directories

After Phase 1, coverage will still be low because most source directories have zero test files. Tests SHALL be added for all directories below. Every test SHALL cover all code paths in the class under test.

### Requirement: Test Db entities and mappers (69 files)

Unit tests SHALL cover all entity classes and their mapper classes. Entities follow a predictable pattern (getters, setters, `jsonSerialize()`) but many contain conditional logic, type coercion, or computed properties that need branch coverage.

#### Scenario: Entity getters and setters — all types and edge cases

- **GIVEN** any Db entity class (e.g., `Register`, `Schema`, `ObjectEntity`)
- **WHEN** setters are called with valid data, null values, empty strings, and boundary values
- **THEN** the corresponding getters SHALL return the expected values for each case
- **AND** type coercion behavior SHALL be tested (e.g., string to DateTime, JSON string to array)

#### Scenario: Entity JSON serialization — complete and partial data

- **GIVEN** an entity with all fields populated
- **WHEN** `jsonSerialize()` is called
- **THEN** all fields SHALL appear in the returned array with correct types
- **AND** when optional fields are null, they SHALL serialize as null or be omitted per the entity's logic

#### Scenario: Entity default values and construction

- **GIVEN** an entity class
- **WHEN** constructed with no arguments
- **THEN** all default values SHALL be set correctly
- **AND** `getId()` SHALL return null for new (unsaved) entities

#### Scenario: MagicMapper handlers — query building branches

- **GIVEN** `MagicMapper` handlers (`MagicBulkHandler`, `MagicFacetHandler`, `MagicOrganizationHandler`, `MagicRbacHandler`, `MagicSearchHandler`)
- **WHEN** query building methods are called with different filter combinations, empty filters, invalid filters, and combinations of search + facet + RBAC
- **THEN** they SHALL produce correct SQL fragments and parameter bindings for each combination
- **AND** edge cases (no filters, all filters, unknown filter keys) SHALL be handled

#### Scenario: ObjectEntity handlers — all operation modes

- **GIVEN** `ObjectEntity` handler classes (`BulkOperationsHandler`, `CrudHandler`, `FacetsHandler`, `LockingHandler`, `QueryBuilderHandler`, `QueryOptimizationHandler`, `StatisticsHandler`)
- **WHEN** their public methods are called with mocked dependencies
- **THEN** each branching path (e.g., locked vs unlocked, cached vs uncached, found vs not found) SHALL be tested

### Requirement: Test Event classes (39 files)

#### Scenario: Event construction and data access

- **GIVEN** any event class (e.g., `ObjectCreatedEvent`, `SchemaUpdatedEvent`, `RegisterDeletedEvent`)
- **WHEN** constructed with an entity
- **THEN** the entity SHALL be retrievable via getter methods
- **AND** the event SHALL be an instance of `\OCP\EventDispatcher\Event`

#### Scenario: Event classes grouped by CRUD pattern

- **GIVEN** most events follow a Created/Updated/Deleted pattern per entity type
- **WHEN** testing these events
- **THEN** use a `#[DataProvider]` to test all variants of the same entity's events in a single test class (e.g., `RegisterEventsTest` covers `RegisterCreatedEvent`, `RegisterUpdatedEvent`, `RegisterDeletedEvent`)

### Requirement: Test Controller classes (51 files, 48 untested)

Tests exist for `ConfigurationController`, `FilesController`, and `SettingsController`. The remaining 48 controllers need tests.

#### Scenario: Controller CRUD actions — success path

- **GIVEN** any API controller (e.g., `ObjectsController`, `RegistersController`, `SchemasController`)
- **WHEN** `index()`, `show()`, `create()`, `update()`, or `destroy()` is called with valid input
- **THEN** it SHALL return a `JSONResponse` with HTTP 200/201 and the expected data structure
- **AND** the service layer SHALL be called with the correct arguments (verified via `expects($this->once())`)

#### Scenario: Controller error handling — service throws exception

- **GIVEN** a controller action
- **WHEN** the underlying service throws `\Exception`, `ValidationException`, `NotAuthorizedException`, or `NotFoundException`
- **THEN** the controller SHALL return a `JSONResponse` with the appropriate error status (400, 403, 404, 500)
- **AND** the error response SHALL contain a descriptive message
- **AND** the error SHALL be logged (verified via logger mock)

#### Scenario: Controller input validation — missing or invalid parameters

- **GIVEN** a controller action that expects specific request parameters
- **WHEN** called with missing required params, wrong types, or empty values
- **THEN** it SHALL return a validation error response (400)

#### Scenario: Controller authorization checks

- **GIVEN** a controller with RBAC or organisation-scoped access
- **WHEN** called by an unauthorized user (mocked via `IUserSession`)
- **THEN** it SHALL return 403 Forbidden

### Requirement: Test Service classes (~130 untested files)

Service classes contain the bulk of the business logic and branching. Each service handler SHALL have tests for every public method and every branch within those methods.

#### Scenario: Service handlers — success, failure, and edge cases

- **GIVEN** any service handler (e.g., `CrudHandler`, `CacheHandler`, `AuditHandler`)
- **WHEN** public methods are called with mocked mappers and dependencies
- **THEN** tests SHALL cover:
  - The happy path with valid input
  - What happens when a mapper throws `DoesNotExistException` (not found)
  - What happens when a mapper throws `MultipleObjectsReturnedException`
  - What happens with empty/null input
  - What happens with malformed input
  - Each if/else and switch branch in the method

#### Scenario: Object service — save, get, delete, validate paths

- **GIVEN** `SaveObject`, `GetObject`, `DeleteObject`, `ValidateObject` and their sub-handlers
- **WHEN** operations are performed
- **THEN** each handler SHALL be tested for:
  - New object creation vs update of existing object
  - With and without file properties
  - With and without relations/cascading
  - Validation success and validation failure (each validation rule)
  - Lock check (locked vs unlocked object)
  - Permission check (authorized vs unauthorized)

#### Scenario: Index backends — Solr and Elasticsearch branches

- **GIVEN** search backend classes (`SolrBackend`, `ElasticsearchBackend` and their sub-handlers)
- **WHEN** index/search/facet operations are called
- **THEN** tests SHALL cover:
  - Successful indexing and search
  - Connection failure / timeout (mock HTTP client to throw)
  - Empty search results
  - Faceted search with and without facet configuration
  - Schema creation and update paths
  - Bulk indexing with partial failures

#### Scenario: File service handlers — all file operation branches

- **GIVEN** file service handlers (`CreateFileHandler`, `DeleteFileHandler`, `ReadFileHandler`, `UpdateFileHandler`, etc.)
- **WHEN** file operations are requested
- **THEN** tests SHALL cover:
  - File found vs file not found
  - File owned by user vs shared file vs system file
  - Valid file type vs rejected file type
  - Folder exists vs folder needs creation
  - File with tags vs without tags

#### Scenario: Configuration service — fetch, import, export branches

- **GIVEN** `ConfigurationService` and its handlers (`FetchHandler`, `ImportHandler`, `ExportHandler`, `GitHubHandler`, `GitLabHandler`)
- **WHEN** configuration operations are performed
- **THEN** tests SHALL cover:
  - Local config vs remote config (GitHub/GitLab)
  - Config found vs not found
  - Valid config format vs malformed config
  - Version comparison (newer, older, same)
  - Cache hit vs cache miss

#### Scenario: Webhook service — delivery and retry paths

- **GIVEN** `WebhookService` and `CloudEventFormatter`
- **WHEN** webhook delivery is triggered
- **THEN** tests SHALL cover:
  - Successful delivery (HTTP 2xx)
  - Failed delivery (HTTP 4xx/5xx)
  - Connection timeout
  - Retry logic (max retries reached vs retries remaining)
  - CloudEvents format validation

#### Scenario: Organisation service — multi-tenancy paths

- **GIVEN** `OrganisationService` with its membership, caching, and settings logic
- **WHEN** organisation operations are performed
- **THEN** tests SHALL cover:
  - User joins organisation, is already member, joins non-existent org
  - User leaves organisation, is last member, is not member
  - Active organisation set, cleared, cached, cache expired
  - Default organisation exists vs doesn't exist
  - Multi-tenancy filtering enabled vs disabled

### Requirement: Test Tool classes (7 files)

#### Scenario: Tool interface compliance and all process() branches

- **GIVEN** tool classes (`AgentTool`, `ApplicationTool`, `ObjectsTool`, `RegisterTool`, `SchemaTool`)
- **WHEN** `getName()`, `getDescription()`, `getInputSchema()`, and `process()` are called
- **THEN** they SHALL return valid tool definitions
- **AND** `process()` SHALL be tested with:
  - Valid input → delegates to correct service method
  - Missing required input → returns error
  - Service throws exception → returns error message
  - Each action variant (list, get, create, update, delete) if the tool supports multiple actions

### Requirement: Test remaining directories

#### Scenario: Exception classes — construction and inheritance (7 files)

- **GIVEN** custom exception classes (`ValidationException`, `LockedException`, `NotAuthorizedException`, `DatabaseConstraintException`, `RegisterNotFoundException`, `SchemaNotFoundException`, `CustomValidationException`)
- **WHEN** constructed with a message, code, and optional previous exception
- **THEN** they SHALL extend the correct base exception class
- **AND** `getMessage()`, `getCode()`, and `getPrevious()` SHALL return the correct values
- **AND** any custom methods (e.g., `getValidationErrors()` on `ValidationException`) SHALL be tested with data providers for multiple error scenarios

#### Scenario: Listener classes — handle() with matching and non-matching events (6 files)

- **GIVEN** event listener classes (`FileChangeListener`, `ObjectChangeListener`, `ObjectCleanupListener`, `CommentsEntityListener`, `ToolRegistrationListener`, `WebhookEventListener`)
- **WHEN** `handle()` is called with a matching event
- **THEN** the correct service methods SHALL be called (verified via mock expectations)
- **AND WHEN** `handle()` is called and the service throws an exception
- **THEN** the listener SHALL handle it gracefully (log error, not re-throw)

#### Scenario: BackgroundJob classes — run() success and failure paths (7 untested of 8)

- **GIVEN** background job classes (`CacheWarmupJob`, `NameCacheWarmupJob`, `CronFileTextExtractionJob`, `ObjectTextExtractionJob`, `SolrNightlyWarmupJob`, `SolrWarmupJob`, `WebhookDeliveryJob`)
- **WHEN** `run()` is called with valid job arguments
- **THEN** the correct service SHALL be called
- **AND WHEN** `run()` is called with missing arguments
- **THEN** the job SHALL log a warning and return without error
- **AND WHEN** the underlying service throws an exception
- **THEN** the job SHALL catch it and log the error (verified via `$this->mockLogger->expects($this->once())->method('error')`)

#### Scenario: Command classes — execute() with valid and invalid input (3 files)

- **GIVEN** CLI command classes (`MigrateStorageCommand`, `SolrDebugCommand`, `SolrManagementCommand`)
- **WHEN** `execute()` is called with mocked `InputInterface` and `OutputInterface`
- **THEN** tests SHALL cover:
  - Valid arguments → service called, success message output
  - Missing arguments → error message output, non-zero return code
  - Service exception → error output, non-zero return code

#### Scenario: Cron job classes — run() and error handling (4 files)

- **GIVEN** cron classes (`ConfigurationCheckJob`, `LogCleanUpTask`, `SyncConfigurationsJob`, `WebhookRetryJob`)
- **WHEN** `run()` is called
- **THEN** the correct service method SHALL be called
- **AND** exception handling SHALL be tested (service failure → logged, not re-thrown)

#### Scenario: Notification, Repair, Search, Settings, Sections (5 files)

- **GIVEN** `Notifier`, `RegisterRiskLevelMetadata`, `ObjectsProvider`, `OpenRegisterAdmin` (settings + sections)
- **WHEN** their public interface methods are called
- **THEN** they SHALL return correctly typed results
- **AND** each conditional branch within these classes SHALL be covered (e.g., `Notifier` with known vs unknown notification type, `ObjectsProvider` with results vs no results)

### Requirement: Test Formats classes (2 files)

#### Scenario: BsnFormat validation — all branches

- **GIVEN** the `BsnFormat` validator
- **WHEN** validating BSN numbers
- **THEN** tests SHALL cover (via data provider):
  - Valid 9-digit BSN with correct checksum
  - Invalid checksum
  - Wrong length (too short, too long)
  - Non-numeric input
  - Null/empty input

#### Scenario: SemVerFormat validation — all branches

- **GIVEN** the `SemVerFormat` validator
- **WHEN** validating version strings
- **THEN** tests SHALL cover (via data provider):
  - Valid versions: `"1.0.0"`, `"0.0.0"`, `"1.2.3-alpha"`, `"1.2.3+build"`
  - Invalid versions: `"1.0"`, `"v1.0.0"`, `"1.0.0.0"`, `""`, `null`

## Phase 3: Coverage Enforcement

### Requirement: Raise coverage threshold to 100%

#### Scenario: CI enforces 100% line coverage

- **GIVEN** all tests pass and cover all source files
- **WHEN** `composer test:coverage` is run
- **THEN** the clover report SHALL show 100% line coverage
- **AND** `composer coverage:check` threshold SHALL be updated from 75% to 100%

## Estimated Scope

| Category | Files to Test | Est. Test Files Needed |
|----------|--------------|----------------------|
| Fix broken tests | — | 0 (fix existing 6 files) |
| Db entities + mappers | 69 | ~25 |
| Events | 39 | ~5 (grouped by CRUD pattern) |
| Controllers | 48 | ~20 |
| Services | ~130 | ~50 |
| Tools | 7 | ~3 |
| Exceptions | 7 | 1 |
| Listeners | 6 | ~3 |
| BackgroundJobs | 7 | ~4 |
| Commands | 3 | ~2 |
| Cron | 4 | ~2 |
| Formats | 2 | 1 (exists, needs fixes) |
| Other (Notif, Repair, etc.) | 5 | ~3 |
| **Total** | **~330 new** | **~118 new test files** |

### Current Implementation Status

**Phase 1 is COMPLETE. Phase 2 is in progress.**

- **1,121 tests pass** with 0 errors, 0 failures
- **~30 test files** exist across `tests/Unit/` covering: BackgroundJob, Command, Controller, Cron, Db, Dto, Event, EventListener, Exception, Formats, Listener, Notification, Repair, Search, Sections, Service, Settings, Tool, Twig
- Coverage threshold is currently set at 75% (`composer coverage:check`)
- Phase 2 (writing ~118 new test files for ~330 untested source files) has not been completed

**Existing test directories:**
- `tests/Unit/BackgroundJob/` -- FileTextExtractionJobTest
- `tests/Unit/Controller/` -- ConfigurationController, FilesController, SettingsController tests
- `tests/Unit/Db/` -- MagicMapperTest and entity tests
- `tests/Unit/Service/` -- Various service tests (OrganisationService, ConfigurationService, etc.)
- `tests/Unit/Formats/` -- BSN and SemVer format tests
- `tests/Unit/Event/` -- Event class tests
- `tests/Unit/Exception/` -- Exception class tests
- `tests/Unit/Tool/` -- Tool class tests

**What is NOT yet implemented:**
- ~118 new test files for Phase 2 (bulk of untested service handlers, controllers, mappers)
- 100% line coverage target (Phase 3)
- CI enforcement at 100% threshold

### Standards & References
- PHPUnit 10+ testing framework (https://phpunit.de/)
- PHP PCOV extension for code coverage
- Nextcloud app testing guidelines
- PSR-4 autoloading for test namespaces

### Specificity Assessment
- **Specific enough to implement?** Yes -- this is one of the most detailed specs, with explicit patterns, naming conventions, and file-by-file scope.
- **Missing/ambiguous:** Nothing significant -- the spec is comprehensive.
- **Open questions:**
  - Should integration tests (requiring database/Nextcloud container) be counted toward the 100% target?
  - What is the timeline for Phase 2 completion?
