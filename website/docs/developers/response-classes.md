# Response Classes

The OpenRegister application uses a hierarchy of response classes to handle different types of object operations. These classes provide a consistent interface for working with single objects, multiple objects, and their related data.

## Class Hierarchy

```
ObjectResponse
├── SingleObjectResponse
└── MultipleObjectResponse
```

## ObjectResponse

The base response class that provides common functionality for all object responses.

### Properties

```php
protected array $data;        // The response data
protected ?int $page = null;  // Current page number
protected ?int $limit = null; // Items per page
protected ?int $total = null; // Total number of items
```

### Methods

#### Constructor
```php
public function __construct(array|ObjectEntity $data)
```
Creates a new response instance with the given data.

#### Pagination
```php
public function paginate(
    int $page = 1,
    ?int $limit = 10,
    ?int $total = null
): self
```
Paginates the response data.

#### Download
```php
public function download(string $format): string
```
Downloads the data in the specified format (json, xml, csv, excel).

#### Get Data
```php
public function getData(): array
```
Returns the response data with optional pagination metadata.

### Protected Methods

```php
protected function downloadJson(array $normalizers): string
protected function downloadXml(array $normalizers): string
protected function downloadCsv(array $normalizers): string
protected function downloadExcel(): string
```

## SingleObjectResponse

Response class for single object operations, extending ObjectResponse.

### Properties

```php
private GetObject $getHandler;    // Handler for fetching related data
private ObjectEntity $object;     // The object entity
```

### Methods

#### Constructor
```php
public function __construct(ObjectEntity $object, GetObject $getHandler)
```
Creates a new single object response.

#### Get Relations
```php
public function getRelations(): ObjectResponse
```
Returns related objects for the current object.

#### Get Logs
```php
public function getLogs(): ObjectResponse
```
Returns logs for the current object.

#### Get Object
```php
public function getObject(): ObjectEntity
```
Returns the object entity.

## MultipleObjectResponse

Response class for multiple object operations, extending ObjectResponse.

### Properties

```php
private GetObject $getHandler;    // Handler for fetching related data
```

### Methods

#### Constructor
```php
public function __construct(array $objects, GetObject $getHandler)
```
Creates a new multiple object response.

#### Get Relations
```php
public function getRelations(): ObjectResponse
```
Returns related objects for all objects in the collection.

#### Get Logs
```php
public function getLogs(): ObjectResponse
```
Returns logs for all objects in the collection.

## Usage Examples

### Single Object Operations

```php
// Get an object with its relations
$response = $objectService->getObject($uuid);
$relations = $response->getRelations()->paginate(1, 10);

// Get object logs
$logs = $response->getLogs()->paginate(1, 10);

// Download object data
$jsonData = $response->download('json');
```

### Multiple Object Operations

```php
// Get multiple objects
$response = $objectService->getObjects(['status' => 'active']);

// Get relations for all objects
$relations = $response->getRelations()->paginate(1, 10);

// Get logs for all objects
$logs = $response->getLogs()->paginate(1, 10);

// Download all objects
$csvData = $response->download('csv');
```

### Pagination Example

```php
$response = $objectService->getObjects()
    ->paginate(
        page: 2,
        limit: 25,
        total: 100
    );

$data = $response->getData();
// Returns:
// [
//     'data' => [...],
//     'pagination' => [
//         'page' => 2,
//         'limit' => 25,
//         'total' => 100,
//         'pages' => 4
//     ]
// ]
```

## Best Practices

1. Use the appropriate response type for your operation
2. Always handle pagination for large datasets
3. Consider memory usage when working with multiple objects
4. Use type hints in your code
5. Handle download formats appropriately
6. Chain methods in a logical order
7. Use error handling when working with responses

## Error Handling

```php
try {
    $response = $objectService->getObject($uuid);
    $data = $response->download('json');
} catch (Exception $e) {
    // Handle download error
}

try {
    $response = $objectService->getObjects()
        ->paginate(1, 10);
} catch (Exception $e) {
    // Handle pagination error
}
``` 