# Spec Reference: Unit Test Coverage

This change implements Phase 2 of the `unit-test-coverage` spec.

See: `openregister/openspec/specs/unit-test-coverage/spec.md`

## Source File Inventory (2026-03-16)

Total source files in `lib/`: **492**
Total test files in `tests/Unit/`: **310**

### By category:

| Category | Source Files | Test Files | Status |
|---|---|---|---|
| Event | 39 | 5 | Complete (DataProvider grouping) |
| Exception | 10 | 2 | Partial |
| Formats | 2 | 1 | Partial |
| Db root (entities+mappers) | 55 | 31 | Partial (excluded from coverage) |
| Db/MagicMapper | 5 | 0 | Not started (excluded from coverage) |
| Db/ObjectHandlers | 5 | 0 | Not started (excluded from coverage) |
| Controller root | 46 | 78 | Complete (multiple test files per controller) |
| Controller/Settings | 12 | (included above) | Complete |
| Service root | 43 | 147 total | Mostly complete |
| Service/Chat | 5 | (included above) | Needs verification |
| Service/Configuration | 8 | (included above) | Needs verification |
| Service/File | 13 | (included above) | Needs verification |
| Service/GraphQL | 12 | 0 | Not in original plan |
| Service/Handler | 5 | (included above) | Needs verification |
| Service/Index | 23 | (included above) | Needs verification |
| Service/Mcp | 3 | (included above) | Needs verification |
| Service/Object | 39 | (included above) | Needs verification |
| Service/Schemas | 3 | (included above) | Needs verification |
| Service/Settings | 8 | (included above) | Needs verification |
| Service/TextExtraction | 4 | (included above) | Needs verification |
| Service/Vectorization | 8 | (included above) | Needs verification |
| Service/Webhook | 1 | (included above) | Needs verification |
| BackgroundJob | 10 | 8 | Mostly complete |
| Command | 3 | 4 | Complete |
| Cron | 4 | 4 | Complete |
| Listener | 8 | 7 | Mostly complete |
| Tools | 0 | 0 | Removed from codebase |
| Notification | 1 | (in "Other") | Likely covered |
| Repair | 1 | (in "Other") | Likely covered |
| Search | 1 | (in "Other") | Likely covered |
| Sections | 1 | (in "Other") | Likely covered |
| Settings | 1 | (in "Other") | Likely covered |
| Migration | 91 | 0 | Excluded from coverage |
| AppInfo | 1 | 0 | Excluded from coverage |

## Concrete Test Pattern Examples

### Event DataProvider Pattern (from SimpleCrudEventsTest)
```php
public static function singleEntityEventProvider(): array {
    return [
        'AgentCreatedEvent' => [AgentCreatedEvent::class, Agent::class, 'getAgent'],
        'AgentDeletedEvent' => [AgentDeletedEvent::class, Agent::class, 'getAgent'],
        // ... 18 more entries
    ];
}

#[DataProvider('singleEntityEventProvider')]
public function testSingleEntityConstructAndGet(string $eventClass, string $entityClass, string $getter): void {
    $entity = new $entityClass();
    $event = new $eventClass($entity);
    $this->assertSame($entity, $event->$getter());
}
```

### Entity Test Pattern (from RegisterTest)
```php
// Constructor defaults
public function testConstructorDefaultValues(): void {
    $this->assertNull($this->register->getUuid());
    $this->assertSame([], $this->register->getSchemas());
}

// Reflection for private $id
$reflection = new \ReflectionProperty($this->register, 'id');
$reflection->setAccessible(true);
$reflection->setValue($this->register, 42);

// JSON serialization with date formatting
$json = $this->register->jsonSerialize();
$this->assertSame($created->format('c'), $json['created']);
```

### Service Mock Pattern (from SearchTrailServiceTest)
```php
protected function setUp(): void {
    $this->searchTrailMapper = $this->createMock(SearchTrailMapper::class);
    $this->service = new SearchTrailService($this->searchTrailMapper, ...);
}

public function testCreateSearchTrailThrowsOnMapperException(): void {
    $this->searchTrailMapper->method('createSearchTrail')
        ->willThrowException(new Exception('DB error'));
    $this->expectException(Exception::class);
    $this->expectExceptionMessage('Search trail creation failed: DB error');
    $this->service->createSearchTrail([], 0, 0);
}
```

### Controller Mock Pattern (from TagsControllerTest)
```php
private TagsController $controller;
private IRequest&MockObject $request;  // PHPUnit 10 intersection type
private ObjectService&MockObject $objectService;

protected function setUp(): void {
    $this->request = $this->createMock(IRequest::class);
    $this->objectService = $this->createMock(ObjectService::class);
    $this->controller = new TagsController('openregister', $this->request, $this->objectService, ...);
}
```
