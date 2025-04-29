# Import and Export

The OpenRegister application supports importing and exporting of configuration data in JSON format.

## Import

The OpenRegister application supports importing configuration data in JSON format. The import functionality is handled by the `ConfigurationService::importFromJson()` method.

### Import Parameters

- `jsonData` (string): A JSON string containing the configuration data to import
- `includeObjects` (boolean, optional): Whether to include objects in the import (default: false)
- `owner` (string, optional): The owner to assign to the imported data (default: '')

### JSON Format

The import JSON should follow this structure:

```json
{
  "schemas": [
    {
      "name": "string",
      "description": "string",
      "fields": [
        {
          "name": "string",
          "type": "string",
          "required": boolean,
          "description": "string"
        }
      ],
      "metadata": {
        // Additional schema metadata
      }
    }
  ],
  "objects": [
    {
      "name": "string",
      "schema": "string",
      "data": {
        // Object data matching schema fields
      },
      "metadata": {
        // Additional object metadata
      }
    }
  ]
}
```

### Response Format

The import operation returns an array with the following structure:

```php
[
  'schemas' => [
    'schema_name' => [
      // Imported schema data
    ],
  ],
  'objects' => [
    'object_name' => [
      // Imported object data
    ],
  ]
]
```

### Error Handling

The import process includes strict validation and will throw exceptions in the following cases:

- `JsonException`: When the provided JSON data is invalid
- `InvalidArgumentException`: When the JSON structure is invalid or required fields are missing

### Example Usage

```php
try {
    $jsonData = '{"schemas": [...], "objects": [...]}';
    $result = $configurationService->importFromJson($jsonData, true, 'admin');
    // Process result
} catch (\Exception $e) {
    // Handle error
}
```

## Export

// ... existing export documentation ... 