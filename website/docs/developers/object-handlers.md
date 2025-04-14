# Object Handlers

The OpenRegister application uses a set of specialized handler classes to manage different aspects of object operations. Each handler is responsible for a specific type of operation, following the Single Responsibility Principle.

## Handler Overview

```
ObjectHandlers/
├── DeleteObject.php
├── GetObject.php
├── RenderObject.php
├── SaveObject.php
└── ValidateObject.php
```

## GetObject Handler

Responsible for retrieving objects from the system.

### Key Features
- Finding objects by UUID
- Retrieving multiple objects
- Handling pagination
- Managing file information
- Finding related objects
- Retrieving object logs

### Main Methods

```php
public function findByUuid(string $uuid): ?ObjectEntity
public function getObject(Register $register, Schema $schema, string $uuid): ObjectEntity
public function findMultiple(array $ids, ?array $extend = [], bool $files = false): array
public function findAll(?int $limit = null, ?int $offset = null, array $filters = []): array
public function findRelated(ObjectEntity $object): array
public function findLogs(ObjectEntity $object): array
```

## SaveObject Handler

Handles object persistence operations.

### Key Features
- Creating new objects
- Updating existing objects
- Managing object metadata
- Handling file attachments
- Setting default values
- Managing object relations

### Main Methods

```php
public function saveObject(
    Register|int|string $register,
    Schema|int|string $schema,
    array $object,
    ?int $depth = null
): ObjectEntity
```

## DeleteObject Handler

Manages object deletion operations.

### Key Features
- Deleting single objects
- Cascading deletes
- File cleanup
- Maintaining referential integrity
- Tracking deletion operations

### Main Methods

```php
public function deleteObject(
    Register|int|string $register,
    Schema|int|string $schema,
    string $uuid,
    ?string $originalObjectId = null
): bool
```

## ValidateObject Handler

Handles object validation against schemas.

### Key Features
- JSON Schema validation
- Custom validation rules
- Schema resolution
- Error formatting
- Format validation

### Main Methods

```php
public function validateObject(array $object, Schema|int|string $schema): ValidationResult
public function resolveSchema(string $uri): array
```

## RenderObject Handler

Manages object presentation and transformation.

### Key Features
- Converting objects to JSON
- Handling property extensions
- Managing render depth
- Field filtering
- Property formatting

### Main Methods

```php
public function renderEntity(
    ObjectEntity $entity,
    ?array $extend = [],
    ?int $depth = null,
    ?array $filter = [],
    ?array $fields = []
): array
```

## Usage Examples

### Getting Objects

```php
// Find by UUID
$object = $getHandler->findByUuid($uuid);

// Get with context
$object = $getHandler->getObject($register, $schema, $uuid);

// Find multiple
$objects = $getHandler->findMultiple(['uuid1', 'uuid2']);

// Find all with filters
$objects = $getHandler->findAll(
    limit: 10,
    offset: 0,
    filters: ['status' => 'active']
);
```

### Saving Objects

```php
// Save new object
$object = $saveHandler->saveObject(
    register: $register,
    schema: $schema,
    object: [
        'name' => 'Test Object',
        'status' => 'active'
    ]
);
```

### Deleting Objects

```php
// Delete object
$success = $deleteHandler->deleteObject(
    register: $register,
    schema: $schema,
    uuid: $uuid
);
```

### Validating Objects

```php
// Validate object
$result = $validateHandler->validateObject(
    object: $data,
    schema: $schema
);

if (!$result->isValid()) {
    $errors = $result->getErrors();
}
```

### Rendering Objects

```php
// Render object
$rendered = $renderHandler->renderEntity(
    entity: $object,
    extend: ['relations'],
    depth: 2,
    filter: ['status' => 'active'],
    fields: ['id', 'name', 'status']
);
```

## Best Practices

1. Use type hints consistently
2. Handle errors appropriately
3. Validate input data
4. Use appropriate handlers for each operation
5. Consider performance implications
6. Maintain proper separation of concerns
7. Document handler behavior

## Error Handling

```php
try {
    $object = $getHandler->findByUuid($uuid);
} catch (DoesNotExistException $e) {
    // Handle not found
} catch (Exception $e) {
    // Handle other errors
}

try {
    $result = $validateHandler->validateObject($data, $schema);
    if (!$result->isValid()) {
        // Handle validation errors
    }
} catch (Exception $e) {
    // Handle validation exception
}
```

## Dependencies

Each handler typically requires specific dependencies:

```php
// GetObject
- ObjectEntityMapper
- FileService

// SaveObject
- ObjectEntityMapper
- FileService
- IUserSession

// DeleteObject
- ObjectEntityMapper
- FileService

// ValidateObject
- IURLGenerator
- IAppConfig

// RenderObject
- IURLGenerator
- FileService
```

## Configuration

Handlers can be configured through dependency injection in your application's service container:

```php
// Example service configuration
services:
    OCA\OpenRegister\Service\ObjectHandlers\GetObject:
        arguments:
            - '@OCA\OpenRegister\Db\ObjectEntityMapper'
            - '@OCA\OpenRegister\Service\FileService'
``` 