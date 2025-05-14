# Importing and Exporting Data

## Importing Data

OpenRegister allows you to import data from various formats:
- JSON files for configuration data
- Excel files (.xlsx) for bulk data import
- CSV files for simple data import

### How to Import

1. Navigate to the register you want to import data into
2. Click the 'Import' button
3. Choose your import file (JSON, Excel, or CSV)
4. Select whether you want to include objects in the import
5. Click 'Import' to start the process

### Import Options

- **Include Objects**: When checked, both schemas and their associated objects will be imported. If unchecked, only schemas will be imported.
- **File Type**: Choose between JSON, Excel, or CSV format based on your needs

### Import File Formats

#### JSON Format
Your JSON import file should contain:
- Schemas: The structure definitions for your data
- Objects (optional): The actual data entries that follow these schemas

Example of a valid JSON import file:
```json
{
  "schemas": [
    {
      "name": "Employee",
      "description": "Employee record schema",
      "fields": [
        {
          "name": "firstName",
          "type": "string",
          "required": true,
          "description": "Employee's first name"
        }
      ]
    }
  ],
  "objects": [
    {
      "name": "john_doe",
      "schema": "Employee",
      "data": {
        "firstName": "John"
      }
    }
  ]
}
```

#### Excel Format
Excel files (.xlsx) should follow these guidelines:
- First row should contain headers matching your schema properties
- Each subsequent row represents an object
- Multiple sheets can be used for different schemas
- Sheet names should match schema names

#### CSV Format
CSV files should follow these guidelines:
- First row should contain headers matching your schema properties
- Each subsequent row represents an object
- Use comma as delimiter
- Use double quotes for text fields

### Import Validation

The system will validate your import file to ensure:
- The file format is correct
- All required fields are present
- Schema references in objects are valid
- Data types match the schema definitions

### Import Results Summary

After importing data, you will see a summary of the import results showing:
- Created: Log entries for objects that were newly created
- Updated: Log entries for objects that were modified
- Unchanged: Log entries for objects that remained the same

Example summary:
```json
{
  "message": "Import successful",
  "summary": {
    "created": [ { "action": "created", "timestamp": "..." } ],
    "updated": [ { "action": "updated", "timestamp": "..." } ],
    "unchanged": [ { "action": "unchanged", "timestamp": "..." } ]
  }
}
```

Each entry contains log details about the import action for that object. These logs are shown for informational purposes and are not stored in the database.

## Exporting Data

OpenRegister allows you to export your registers and configurations in multiple formats:
- JSON for configuration data
- Excel (.xlsx) for bulk data export
- CSV for simple data export

### How to Export

1. Navigate to the register you want to export
2. Click the 'Export' button
3. Choose your preferred export format (JSON, Excel, or CSV)
4. Select whether to include objects in the export
5. Click 'Export' to download the file

### Export Options

- **Include Objects**: When checked, both the schema and all associated objects will be exported
- **Format**: Choose between JSON, Excel, or CSV based on your needs

### Export File Formats

#### JSON Format
The JSON export will contain:
- Schema: The structure definition of your register
- Objects (if included): All data entries that follow this schema

#### Excel Format
The Excel export will:
- Create a separate sheet for each schema
- Include all object data in tabular format
- Use headers matching schema properties

#### CSV Format
The CSV export will:
- Include all object data in a single file
- Use headers matching schema properties
- Use comma as delimiter

### Error Handling

If any errors occur during the export process:
- A notification will appear explaining the issue
- Check that you have the necessary permissions
- Ensure the register exists and is accessible
- Try refreshing the page if the issue persists

### Best Practices

1. **Regular Backups**: Export your data regularly as backups
2. **Version Control**: Keep track of different versions of your exports
3. **Documentation**: Add comments or documentation to explain the purpose of exported data
4. **Validation**: Always validate imported files before using them in production
5. **Security**: Store exported files securely, especially if they contain sensitive data
6. **Format Choice**: 
   - Use JSON for configuration and complex data structures
   - Use Excel for bulk data and when working with multiple schemas
   - Use CSV for simple data and when working with external tools 