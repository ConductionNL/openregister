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
