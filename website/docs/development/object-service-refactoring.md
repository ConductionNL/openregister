# ObjectService Refactoring

## Overview

The ObjectService has been refactored to improve maintainability and separation of concerns. The original monolithic service has been split into specialized handlers, each responsible for a specific aspect of object management.

## Handler Classes

The following handler classes have been created:

### RenderObject

Responsible for:
- Transforming objects into their presentational format
- Handling extensions and depth control
- Managing field filtering
- Converting entities to arrays with proper structure

### ValidateObject

Responsible for:
- Validating objects against their schemas
- Handling custom validation rules
- Managing validation error responses
- Schema resolution and validation

### SaveObject

Responsible for:
- Saving objects to the database
- Managing object relations
- Handling file properties
- Creating audit trails
- Setting default values and metadata

### DeleteObject

Responsible for:
- Deleting objects from the database
- Managing cascading deletes
- Cleaning up associated files
- Handling relation cleanup

### GetObject

Responsible for:
- Retrieving objects by ID or UUID
- Finding multiple objects
- Handling pagination and filtering
- Managing file information retrieval

## ObjectService as Facade

The ObjectService now acts as a facade, coordinating between these specialized handlers. It:
- Maintains register and schema state
- Delegates operations to appropriate handlers
- Provides a simplified interface for object operations
- Manages cross-cutting concerns

## Benefits

This refactoring provides several benefits:
- Improved maintainability through separation of concerns
- Better testability of individual components
- Reduced complexity in each component
- Clearer responsibility boundaries
- Easier to extend and modify individual aspects

## Usage Example

```php
// Example of using the refactored ObjectService
$objectService = new ObjectService(
    $deleteHandler,
    $getHandler,
    $renderHandler,
    $saveHandler,
    $validateHandler
);

// Set context
$objectService->setRegister($registerId);
$objectService->setSchema($schemaId);

// Create object
$object = $objectService->createFromArray([
    'name' => 'Test Object',
    'description' => 'A test object'
]);

// Find object with extensions
$foundObject = $objectService->find(
    $id,
    ['relatedObjects'],
    true // include files
);

// Update object
$updatedObject = $objectService->updateFromArray(
    $id,
    ['name' => 'Updated Name'],
    true, // update version
    false, // not a patch
    ['relatedObjects'] // extend
);

// Delete object
$objectService->delete($object);
```

## Migration Guide

When migrating from the old ObjectService to the new refactored version:

1. Update dependency injection to include all handlers
2. Replace direct property access with handler method calls
3. Update any custom extensions to use the appropriate handler
4. Review and update any code that relied on internal ObjectService methods

## Technical Details

### File Structure

The handlers are located in 'lib/Service/ObjectHandlers/':
- RenderObject.php
- ValidateObject.php
- SaveObject.php
- DeleteObject.php
- GetObject.php

### Dependencies

Each handler has its own specific dependencies, reducing the overall coupling of the system. Common dependencies include:
- ObjectEntityMapper
- FileService
- IURLGenerator
- Various database mappers

### State Management

The ObjectService maintains register and schema state, which is used by the handlers when performing operations. This state can be set using:
- setRegister(int $register)
- setSchema(int $schema)

## Testing

Each handler should have its own test suite. The ObjectService tests should focus on:
- Proper delegation to handlers
- State management
- Error handling
- Integration between handlers 