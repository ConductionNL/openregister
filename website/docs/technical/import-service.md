# Import Service

The Import Service handles data import operations from various formats (CSV, Excel) with efficient batch processing and proper categorization of results.

## Overview

The Import Service has been refactored to use the new `saveObjects` method from `ObjectService` for improved performance. Instead of processing objects one by one, it now processes them in batches and provides detailed feedback on what was created vs updated.

## Key Features

- **Batch Processing**: Uses `ObjectService::saveObjects` for efficient bulk operations
- **Proper Categorization**: Distinguishes between created and updated objects
- **CSV and Excel Support**: Handles both file formats with consistent processing
- **Chunked Processing**: Processes large files in configurable chunks to prevent memory issues
- **Error Handling**: Comprehensive error reporting for both individual rows and batch operations

## Import Methods

### CSV Import

```php
public function importFromCsv(
    string $filePath, 
    ?Register $register = null, 
    ?Schema $schema = null, 
    int $chunkSize = 100
): array
```

**Requirements**: CSV import requires a specific schema to be provided.

**Return Format**:
```php
[
    'Worksheet' => [
        'found' => 5600,
        'created' => ['uuid1', 'uuid2', ...],
        'updated' => ['uuid3', 'uuid4', ...],
        'unchanged' => [],
        'errors' => [],
        'schema' => [
            'id' => 90,
            'title' => 'Compliancy',
            'slug' => 'compliancy'
        ]
    ]
]
```

### Excel Import

```php
public function importFromExcel(
    string $filePath, 
    ?Register $register = null, 
    ?Schema $schema = null, 
    int $chunkSize = 100
): array
```

**Multi-schema Support**: If a register is provided without a schema, each worksheet is processed as a different schema based on the worksheet name.

## Data Structure Requirements

### Object Format

Each object in the import must follow this structure:

```php
[
    '@self' => [
        'id' => 'optional-uuid-for-updates',
        'register' => 1,
        'schema' => 90,
        'organisation' => 'org-slug',
        // ... other system properties
    ],
    'property1' => 'value1',
    'property2' => 'value2',
    // ... other object properties
]
```

### Special Property Handling

- **System Properties**: Properties prefixed with `_` or `@self.` are automatically placed in the `@self` section
- **ID Handling**: If an `id` is provided in the CSV/Excel, it will be used for updates; otherwise, a new UUID will be generated
- **Required Fields**: `register` and `schema` IDs must be set in the `@self` section

## Processing Workflow

1. **Column Mapping**: Headers are mapped to object properties
2. **Chunking**: Data is processed in configurable chunks (default: 100 rows)
3. **Transformation**: Each row is transformed into the required object format
4. **Batch Saving**: All objects in a chunk are saved using `ObjectService::saveObjects`
5. **Categorization**: Results are categorized as created vs updated based on input IDs
6. **Error Collection**: Individual row errors and batch errors are collected

## Performance Benefits

- **Reduced Database Calls**: Single batch operation instead of individual saves
- **Transaction Efficiency**: All objects in a chunk are processed in one transaction
- **Memory Management**: Chunked processing prevents memory overflow
- **Scalability**: Performance scales better with large datasets

## Error Handling

The service provides comprehensive error reporting:

- **Row-level Errors**: Individual row processing errors with row number and data
- **Batch Errors**: Errors from the bulk save operation
- **Validation Errors**: Schema and data validation issues
- **System Errors**: File reading and processing errors

## Configuration

### Chunk Size

The default chunk size is 100 rows, but this can be customized:

```php
$result = $importService->importFromCsv($filePath, $register, $schema, 500);
```

### Memory Considerations

- Larger chunk sizes improve performance but use more memory
- Monitor memory usage when processing very large files
- Consider reducing chunk size if memory issues occur

## Example Usage

```php
// CSV Import with specific schema
$result = $importService->importFromCsv(
    '/path/to/data.csv',
    $register,
    $schema
);

// Check results
if (!empty($result['Worksheet']['errors'])) {
    echo "Import completed with errors:\n";
    foreach ($result['Worksheet']['errors'] as $error) {
        echo "Row {$error['row']}: {$error['error']}\n";
    }
}

echo "Created: " . count($result['Worksheet']['created']) . " objects\n";
echo "Updated: " . count($result['Worksheet']['updated']) . " objects\n";
```

## Migration Notes

### From Previous Version

- The `updated` key is now properly populated in import results
- Return structure includes both `created` and `updated` arrays
- Performance is significantly improved for large imports
- Error handling is more comprehensive

### Breaking Changes

- The `updated` key is now always present (previously missing)
- Return type annotations have been updated to reflect the new structure
- Individual row processing has been replaced with batch processing

## Testing

The service includes comprehensive unit tests covering:

- Successful imports with mixed create/update operations
- Error handling scenarios
- Empty file handling
- Schema validation
- Asynchronous operation wrappers

Run tests with:
```bash
vendor/bin/phpunit tests/unit/Service/ImportServiceTest.php
```

## Dependencies

- **PhpOffice\PhpSpreadsheet**: For CSV and Excel file reading
- **ObjectService**: For batch object saving
- **SchemaMapper**: For schema validation and lookup
- **ObjectEntityMapper**: For database operations
