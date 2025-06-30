# Import and Export

The OpenRegister application supports importing and exporting data in multiple formats:
- JSON for configuration data
- Excel (.xlsx) for bulk data
- CSV for simple data

## Import

The import functionality is handled by the `ImportService` class, which provides methods for different file formats.

### Import Methods

#### JSON Import
```php
public function importFromJson(string $jsonData, ?Register $register = null, ?Schema $schema = null): array
```

#### Excel Import
```php
public function importFromExcel(string $filePath, ?Register $register = null, ?Schema $schema = null): array
```

#### CSV Import
```php
public function importFromCsv(string $filePath, ?Register $register = null, ?Schema $schema = null): array
```

### Import Parameters

- `filePath` (string): Path to the file to import
- `register` (Register|null): Optional register to associate with imported objects
- `schema` (Schema|null): Optional schema to associate with imported objects

### File Format Specifications

#### JSON Format
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

#### Excel Format
Excel files should follow these guidelines:
- First row contains headers matching schema properties
- Each subsequent row represents an object
- Multiple sheets can be used for different schemas
- Sheet names should match schema names

#### CSV Format
CSV files should follow these guidelines:
- First row contains headers matching schema properties
- Each subsequent row represents an object
- Uses comma as delimiter
- Uses double quotes for text fields

### Import Process

1. File Validation
   - Validates file format and structure
   - Checks for required fields
   - Verifies schema references

2. Data Processing
   - Reads data from file
   - Maps fields to schema properties
   - Validates data types and constraints

3. Object Creation/Update
   - Creates new objects or updates existing ones
   - Maintains object relationships
   - Preserves metadata

### Response Format

The import operation returns an array with the following structure:
```json
{
  "message": "Import successful",
  "summary": {
    "created": [ { "action": "created", "timestamp": "...", ... } ],    // Log entries for newly created objects
    "updated": [ { "action": "updated", "timestamp": "...", ... } ],    // Log entries for updated objects
    "unchanged": [ { "action": "unchanged", "timestamp": "...", ... } ] // Log entries for unchanged objects
  }
}
```

Each entry in "created", "updated", and "unchanged" is a log array (see "lastLog" property on ObjectEntity). The "lastLog" property is set at runtime and is not persisted in the database.

### Error Handling

The import process includes strict validation and will throw exceptions in the following cases:
- `JsonException`: When the provided JSON data is invalid
- `InvalidArgumentException`: When the file structure is invalid or required fields are missing
- `RuntimeException`: When file processing fails

## Export

The export functionality is handled by the `ExportService` class, which provides methods for different file formats.

### Export Methods

#### JSON Export
```php
public function exportToJson(Register $register, bool $includeObjects = false): string
```

#### Excel Export
```php
public function exportToExcel(Register $register, bool $includeObjects = false): Spreadsheet
```

#### CSV Export
```php
public function exportToCsv(Register $register, bool $includeObjects = false): string
```

### Export Parameters

- `register` (Register): The register to export
- `includeObjects` (boolean): Whether to include objects in the export

### Export Process

1. Data Collection
   - Retrieves register data
   - Optionally includes associated objects
   - Gathers metadata and relationships

2. Format Conversion
   - Converts data to target format
   - Maintains data structure
   - Preserves relationships

3. File Generation
   - Creates file in requested format
   - Includes all necessary data
   - Maintains data integrity

### Error Handling

The export process will throw exceptions in the following cases:
- `RuntimeException`: When file generation fails
- `InvalidArgumentException`: When invalid parameters are provided

### Best Practices

1. **Data Validation**
   - Validate data before import
   - Check file format and structure
   - Verify schema compatibility

2. **Error Handling**
   - Implement proper error handling
   - Provide meaningful error messages
   - Log import/export operations

3. **Performance**
   - Use batch processing for large files
   - Implement proper memory management
   - Consider using streaming for large exports

4. **Security**
   - Validate file types
   - Sanitize input data
   - Implement proper access control 