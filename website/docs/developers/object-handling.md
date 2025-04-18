# Object Handling

The OpenRegister application uses a sophisticated object handling system that provides a fluent interface for working with objects. This system is built around the ObjectService class and various response classes that enable method chaining, pagination, and data export.

## Basic Usage

The ObjectService provides a fluent interface for working with objects. Here are some basic examples:

```php
// Get a single object
$object = $objectService
    ->setRegister($register)
    ->setSchema($schema)
    ->getObject($uuid);

// Get multiple objects
$objects = $objectService
    ->setRegister($register)
    ->setSchema($schema)
    ->getObjects(['status' => 'active']);
```

## Method Chaining

The system supports method chaining for various operations:

```php
// Get an object with its relations
$objectService->getObject($uuid)->getRelations()->paginate();

// Get an object's logs
$objectService->getObject($uuid)->getLogs()->paginate();

// Get multiple objects and paginate
$objectService->getObjects()->paginate(1, 10);
```

## Pagination

All responses support pagination through the paginate() method:

```php
$response = $objectService->getObjects()
    ->paginate(
        page: 1,      // The page number
        limit: 10,    // Items per page
        total: 100    // Total number of items
    );

// The paginated response includes metadata
$result = $response->getData();
// {
//     'data': [...],
//     'pagination': {
//         'page': 1,
//         'limit': 10,
//         'total': 100,
//         'pages': 10
//     }
// }
```

## Data Export

Objects can be exported in various formats using the download() method:

```php
// Download as JSON
$jsonData = $objectService->getObjects()->download('json');

// Download as XML
$xmlData = $objectService->getObjects()->download('xml');

// Download as CSV
$csvData = $objectService->getObjects()->download('csv');

// Download as Excel
$excelData = $objectService->getObjects()->download('excel');
```

## Response Types

The system includes three types of responses:

### ObjectResponse

Base response class that provides:
- Pagination functionality
- Download capabilities
- Data formatting

### SingleObjectResponse

Response for single object operations:
- Extends ObjectResponse
- Provides access to relations
- Includes log retrieval
- Maintains object state

### MultipleObjectResponse

Response for multiple object operations:
- Extends ObjectResponse
- Handles bulk relations
- Supports bulk log retrieval
- Manages collections of objects

## Working with Relations

Relations can be retrieved and paginated:

```php
// Get relations for a single object
$relations = $objectService
    ->getObject($uuid)
    ->getRelations()
    ->paginate();

// Get relations for multiple objects
$relations = $objectService
    ->getObjects()
    ->getRelations()
    ->paginate();
```

## Working with Logs

Object logs can be retrieved and paginated:

```php
// Get logs for a single object
$logs = $objectService
    ->getObject($uuid)
    ->getLogs()
    ->paginate();

// Get logs directly
$logs = $objectService
    ->getLogs($uuid)
    ->paginate();

// Get logs for multiple objects
$logs = $objectService
    ->getObjects()
    ->getLogs()
    ->paginate();
```

## Context Management

The ObjectService maintains register and schema context:

```php
$objectService
    ->setRegister($register)
    ->setSchema($schema)
    ->getObject($uuid);
```

## Error Handling

The system includes proper error handling:

```php
try {
    $object = $objectService->getObject($uuid);
} catch (DoesNotExistException $e) {
    // Handle not found error
} catch (Exception $e) {
    // Handle other errors
}
```

## Best Practices

1. Always set register and schema context before performing operations
2. Use pagination for large datasets
3. Chain methods appropriately for the desired outcome
4. Handle errors appropriately
5. Use the most specific response type for your needs
6. Consider memory usage when working with large datasets
7. Use appropriate download formats for different use cases

## Technical Details

### Supported Download Formats
- JSON: Using Symfony Serializer with JsonEncoder
- XML: Using Symfony Serializer with XmlEncoder
- CSV: Using Symfony Serializer with CsvEncoder
- Excel: Using PhpSpreadsheet

### Pagination Parameters
- page: The page number (default: 1)
- limit: Items per page (default: 10)
- total: Total number of items (optional)

### Response Data Structure
```php
[
    'data' => [...],           // The actual data
    'pagination' => [          // Only present when paginated
        'page' => int,         // Current page
        'limit' => int,        // Items per page
        'total' => int,        // Total items
        'pages' => int,        // Total pages
    ]
]
``` 