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

## OAS Export Property Cleaning

When exporting OpenAPI Specification (OAS) schemas, the application performs a cleaning step to ensure compatibility with Redocly and other OpenAPI tools.

### Property Prefixing

Certain property keys in schema definitions are prefixed with 'x-openregisters-' to prevent Redocly from crashing or misinterpreting them. The following keys are affected:

- 'deprecated'
- 'cascadeDelete'
- '$ref'
- 'objectConfiguration'
- 'fileConfiguration'

For example, a property like:

'cascadeDelete': true

will be exported as:

'x-openregisters-cascadeDelete': true

### Removal of Null and False Values

Any property whose value is null or false is removed from the exported OAS schema. This helps keep the OAS output clean and avoids issues with tools that may not handle these values gracefully.

### Example

Given the following schema property definition:

{
  'deprecated': true,
  'cascadeDelete': false,
  '$ref': '#/components/schemas/SomeSchema',
  'objectConfiguration': null,
  'fileConfiguration': {'foo': 'bar'},
  'normal': 'value',
  'nested': {
    'deprecated': null,
    'cascadeDelete': true,
    'foo': 'bar'
  }
}

The exported OAS schema will be:

{
  'x-openregisters-deprecated': true,
  'x-openregisters-ref': '#/components/schemas/SomeSchema',
  'x-openregisters-fileConfiguration': {'foo': 'bar'},
  'normal': 'value',
  'nested': {
    'x-openregisters-cascadeDelete': true,
    'foo': 'bar'
  }
}

### Rationale

This cleaning logic ensures that the OAS export is robust and compatible with Redocly and other OpenAPI tools, preventing crashes and validation errors. It also keeps the exported schema concise and free of unnecessary or problematic values.

## OAS Collection Endpoint Search Query Parameters

When generating OpenAPI Specification (OAS) documentation for collection endpoints, the application automatically adds query parameters for searching/filtering based on schema properties.

- Only properties of type 'string' and 'integer' are included as query parameters for exact match filtering.
- Each such property will appear as an optional query parameter in the OAS documentation for the collection GET endpoint.
- This allows API consumers to filter results by providing exact values for these fields.

### Example

If a schema has the following properties:

{
  'name': {'type': 'string'},
  'age': {'type': 'integer'},
  'active': {'type': 'boolean'},
  'meta': {'type': 'object'}
}

The generated OAS for the collection endpoint will include:

- 'name' (query, string): Exact match filter for name (string)
- 'age' (query, integer): Exact match filter for age (integer)

### Extensibility

In the future, additional query options (such as partial matches, range queries, or advanced filters) will be made available. The current implementation is designed to be easily extended to support these features.

// ... end of documentation ... 