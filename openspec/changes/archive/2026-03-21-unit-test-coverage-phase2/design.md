# Design: unit-test-coverage-phase2

## Architecture Overview

All new tests live under `tests/Unit/` mirroring the `lib/` directory structure. Each test extends `PHPUnit\Framework\TestCase` and uses `phpunit-unit.xml` with the minimal `bootstrap-unit.php` — no Nextcloud server, no database, all dependencies mocked.

## Implementation Batches

Tests are organized into 10 batches, ordered from simplest to most complex. Each batch is independently implementable and testable.

### Batch 1: Events (39 source files → ~5 test files)
Group events by entity type using `#[DataProvider]`. Each test verifies construction, entity retrieval, and `Event` inheritance.

Test files:
- `tests/Unit/Event/RegisterEventsTest.php` — RegisterCreated/Updated/DeletedEvent
- `tests/Unit/Event/SchemaEventsTest.php` — SchemaCreated/Updated/DeletedEvent
- `tests/Unit/Event/ObjectEventsTest.php` — ObjectCreated/Creating/Updated/Updating/Deleted/Deleting/Locked/Unlocked/RevertedEvent
- `tests/Unit/Event/EntityEventsTest.php` — Agent/Application/Configuration/Conversation/Organisation/Source/View events
- `tests/Unit/Event/SpecialEventsTest.php` — DeepLinkRegistrationEvent, ToolRegistrationEvent, UserProfileUpdatedEvent

### Batch 2: Exceptions (9 source files → 1 test file)
- `tests/Unit/Exception/ExceptionsTest.php` — All exception classes: construction, inheritance, getMessage/getCode, custom methods (getValidationErrors, etc.)

### Batch 3: Formats (1 source file → 1 test file)
- `tests/Unit/Formats/BsnFormatTest.php` — Valid/invalid BSN numbers with data provider, checksum algorithm, edge cases

### Batch 4: Db Entities (20 source files → ~10 test files)
Each test covers constructor defaults, getters/setters with various types, jsonSerialize(), and type coercion.

### Batch 5: Db Mappers (26 source files → ~12 test files)
Mock IDBConnection and verify query building, parameter binding, and error handling.

### Batch 6: Db Handlers (18 source files → ~8 test files)
Test MagicMapper handlers (bulk, facet, organization, RBAC, search) and ObjectEntity handlers (CRUD, locking, statistics, etc.).

### Batch 7: BackgroundJobs, Commands, Cron, Listeners (23 source files → ~13 test files)
- BackgroundJobs: Test run() with valid args, missing args, and service exceptions
- Commands: Test execute() with mocked Input/OutputInterface
- Cron: Test run() and exception handling
- Listeners: Test handle() with matching/non-matching events

### Batch 8: Controllers (54 source files → ~24 test files)
Test CRUD actions (success + error), input validation, authorization checks. Group simple settings controllers.

### Batch 9: Services — Simple Handlers (75 source files → ~25 test files)
Settings handlers, File handlers, Configuration handlers, Chat handlers, Vectorization handlers, TextExtraction, Schemas, Mcp, Webhook.

### Batch 10: Services — Core Business Logic (36 source files → ~18 test files)
OrganisationService, ObjectService handlers, IndexService + backends, AuthenticationService, AuthorizationService, etc.

## Testing Patterns

### Entity Tests
```php
public function testDefaultValues(): void {
    $entity = new Agent();
    $this->assertNull($entity->getId());
    $this->assertNull($entity->getUuid());
}
public function testJsonSerialize(): void {
    $entity = new Agent();
    $entity->setName('test');
    $result = $entity->jsonSerialize();
    $this->assertIsArray($result);
    $this->assertEquals('test', $result['name']);
}
```

### Event Tests (grouped)
```php
#[DataProvider('registerEventProvider')]
public function testRegisterEvent(string $className): void {
    $register = new Register();
    $register->setId(1);
    $event = new $className($register);
    $this->assertInstanceOf(Event::class, $event);
    $this->assertSame($register, $event->getRegister());
}
public static function registerEventProvider(): array {
    return [
        'created' => [RegisterCreatedEvent::class],
        'updated' => [RegisterUpdatedEvent::class],
        'deleted' => [RegisterDeletedEvent::class],
    ];
}
```

### Controller Tests
```php
public function testIndexSuccess(): void {
    $this->mockService->expects($this->once())
        ->method('findAll')->willReturn([]);
    $response = $this->controller->index();
    $this->assertInstanceOf(JSONResponse::class, $response);
    $this->assertEquals(200, $response->getStatus());
}
public function testIndexServiceThrows(): void {
    $this->mockService->method('findAll')
        ->willThrowException(new \Exception('error'));
    $response = $this->controller->index();
    $this->assertEquals(500, $response->getStatus());
}
```

## Constraints
- Real entity instances (not mocks) for Nextcloud Entity subclasses
- Real `ArrayLoader` instance (final class, cannot be mocked)
- Positional parameters only for PHPUnit API calls
- No Nextcloud server dependencies — mock everything via interfaces

## Existing Test Patterns (from codebase analysis)

### Event Tests — DataProvider Grouping Pattern
The `SimpleCrudEventsTest` demonstrates the canonical pattern for testing events. It groups events into three categories via separate DataProviders:

1. **Single-entity events** (Created/Deleted): `singleEntityEventProvider()` returns `[eventClass, entityClass, getterName]`. Tests verify `Event` inheritance, getter returns same instance, and idempotent getter calls.
2. **Updated events with getters**: `updatedEventWithGettersProvider()` returns `[eventClass, entityClass, newGetter, oldGetter]`. Tests verify both old/new entity retrieval and that they are different instances.
3. **Updated events without getters** (store-only): `updatedEventNoGettersProvider()` — only verifies `Event` inheritance.
4. **Special cases handled inline**: `ObjectUpdatedEvent` has backward-compat `getObject()` alias and optional `$oldObject` parameter — tested with dedicated methods, not via DataProvider.

Source: `tests/Unit/Event/SimpleCrudEventsTest.php`

### Entity Tests — Comprehensive Field Coverage Pattern
The `RegisterTest` demonstrates the canonical entity testing pattern:

1. **Constructor defaults**: Verify all fields start as `null` or empty array
2. **Field type registration**: Assert `getFieldTypes()` returns correct type strings
3. **Getters/setters**: One test per field, including DateTime fields
4. **JSON fields**: Test with array input, JSON string input, invalid JSON (should not throw), and null
5. **`jsonSerialize()`**: Verify all keys present, date formatting (`->format('c')`), null handling, and computed fields (quota/usage)
6. **`__toString()`**: Test fallback chain: title → slug → `ClassName #id` → `ClassName #unknown`
7. **Business methods**: `isManagedByConfiguration()`, `enableMagicMappingForSchema()`, etc.
8. **Reflection for `$id`**: Since Entity's `$id` is private, tests use `ReflectionProperty` to set it

Source: `tests/Unit/Db/RegisterTest.php` (1,145 lines, very thorough)

### Controller Tests — Mock Service Pattern
The `TagsControllerTest` shows the minimal controller test pattern:

1. **setUp()**: Create mocks for `IRequest`, service dependencies. Construct controller with mocks.
2. **Mock typing**: Uses PHPUnit 10 intersection types: `IRequest&MockObject`
3. **Success path**: Configure mock to return data, call controller method, assert `JSONResponse` type and 200 status
4. **Empty results**: Verify empty array handling
5. **Error path** (in other controller tests): Configure mock to throw, assert 500 status and error message

Source: `tests/Unit/Controller/TagsControllerTest.php`

### Service Tests — Dependency Injection Mock Pattern
The `SearchTrailServiceTest` shows the canonical service test pattern:

1. **setUp()**: Create mocks for all mapper/service dependencies. Construct service with mocks.
2. **Success path**: Configure mapper mock with `willReturn()`, call service method, assert result
3. **Exception wrapping**: Verify service wraps mapper exceptions with contextual message
4. **Constructor variants**: Test service with different constructor args (e.g., self-clearing enabled)
5. **Pagination**: Verify offset/limit calculations from page/limit params

Source: `tests/Unit/Service/SearchTrailServiceTest.php`

## Critical Constraints Found

### 1. Entity `__call` Named Arguments Bug
Nextcloud Entity uses `__call` magic for setters. When a setter calls `parent::setFoo(foo: $value)` with named arguments, `__call` receives `['foo' => $value]` instead of `[$value]`. The setter then reads `$args[0]` which gets the key name, not the value.

**Impact on tests:** Some entity setters (like `Register::setSchemas()`) have this bug. Tests must use `ReflectionProperty` to bypass broken setters and test getter behavior separately.

**Example from RegisterTest:**
```php
public function testSetSchemasViaReflectionAndGet(): void {
    $reflection = new \ReflectionProperty($this->register, 'schemas');
    $reflection->setAccessible(true);
    $reflection->setValue($this->register, [1, 2, 3]);
    $this->assertSame([1, 2, 3], $this->register->getSchemas());
}
```

### 2. Private `$id` in Entity Parent
The `$id` property is declared `private` in `\OCP\AppFramework\Db\Entity`, not `protected`. Entity subclasses cannot set it via setter. Tests must use `ReflectionProperty`.

**Example from RegisterTest:**
```php
$reflection = new \ReflectionProperty($this->register, 'id');
$reflection->setAccessible(true);
$reflection->setValue($this->register, 42);
```

### 3. `lib/Db/` Excluded from Coverage
The `phpunit-unit.xml` excludes the entire `lib/Db/` directory from coverage measurement. Entity, Mapper, and Handler tests (Batches 4-6) will not contribute to coverage metrics. This exclusion should be narrowed to only exclude auto-generated migration files or removed entirely.

### 4. Tools Directory Empty
`lib/Tools/` contains zero PHP files — the Tool classes were removed from the codebase. Batch 11 Task 11.1 is entirely obsolete and should be removed.

### 5. GraphQL Services Not Covered
12 GraphQL service files exist in `lib/Service/GraphQL/` but were not included in any batch. These need to be added (likely as a new task in Batch 9 or as Batch 11 replacement).

## Batch Priority Assessment

Based on actual source code complexity (file sizes and dependency counts):

| Batch | Effort | ROI | Notes |
|---|---|---|---|
| 1 (Events) | Low | High | 5 test files cover 39 source files via DataProvider; mostly done |
| 2 (Exceptions) | Low | Medium | Simple classes, 1-2 test files; `ReferentialIntegrityException` was added (10 total) |
| 3 (Formats) | Low | Medium | 2 source files, 1 test exists |
| 4 (Entities) | Medium | Low* | *Won't count toward coverage until phpunit-unit.xml exclusion is fixed |
| 5 (Mappers) | Medium-High | Low* | *Same exclusion issue; complex query mocking |
| 6 (Handlers) | High | Low* | *Same exclusion issue; MagicMapper/ObjectHandlers have complex SQL |
| 7 (Jobs/Commands/Cron/Listeners) | Medium | High | Mostly done (8/10 BgJobs, 4/3 Commands, 4/4 Cron, 7/8 Listeners) |
| 8 (Controllers) | Medium | High | 78 test files already exist for 58 controllers — nearly complete |
| 9 (Service Handlers) | High | Highest | 175 source files, many large; `ImportHandler` (3,256 lines), `ConfigurationSettingsHandler` (1,325 lines) |
| 10 (Core Services) | Very High | Highest | `ObjectService` (3,078 lines, 72 imports, 79 methods), `SaveObject` (3,864 lines) are the most complex files in the entire codebase |

**Recommended priority adjustment:** Fix the `phpunit-unit.xml` exclusion first (add it as Task 0), then prioritize Batches 9-10 for maximum coverage impact since Batches 1-3 and 7-8 are largely complete.
