# Importing and Exporting Data

## Importing Data

OpenRegister allows you to import configuration data from JSON files. This is useful for:
- Backing up and restoring your configurations
- Migrating data between instances
- Sharing configurations with other users

### How to Import

1. Navigate to the 'Import' section in the OpenRegister interface
2. Click the 'Import Configuration' button
3. Choose your JSON file containing the configuration data
4. Select whether you want to include objects in the import
5. Click 'Import' to start the process

### Import Options

- **Include Objects**: When checked, both schemas and their associated objects will be imported. If unchecked, only schemas will be imported.

### Import File Format

Your import file should be a JSON file containing:
- Schemas: The structure definitions for your data
- Objects (optional): The actual data entries that follow these schemas

Example of a valid import file:

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

### Import Validation

The system will validate your import file to ensure:
- The JSON format is correct
- All required fields are present
- Schema references in objects are valid
- Data types match the schema definitions

If any validation errors occur, you'll receive a detailed error message explaining the issue.

## Exporting Data

OpenRegister allows you to export your registers and configurations to JSON files. This is useful for:
- Backing up your data
- Sharing configurations with other users
- Migrating data between instances
- Creating templates for new registers

### How to Export a Configuration

1. Navigate to the 'Configurations' section
2. Find the configuration you want to export
3. Click the 'Export' button (download icon)
4. Choose whether to include objects in the export
5. Click 'Export' to download the JSON file

### How to Export a Register

1. Navigate to the 'Registers' section
2. Select the register you want to export
3. Click the 'Export' button (download icon)
4. Choose whether to include objects in the export
5. Click 'Export' to download the JSON file

### Export Options

- **Include Objects**: When checked, both the schema and all associated objects will be exported. If unchecked, only the schema will be exported.

### Export File Format

The export file will be a JSON file containing:
- Schema: The structure definition of your register
- Objects (if included): All data entries that follow this schema

Example of an export file:

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

### Error Handling

If any errors occur during the export process:
- A notification will appear explaining the issue
- Check that you have the necessary permissions
- Ensure the register or configuration exists and is accessible
- Try refreshing the page if the issue persists

### Best Practices

1. **Regular Backups**: Export your configurations regularly as backups
2. **Version Control**: Keep track of different versions of your exports
3. **Documentation**: Add comments or documentation to explain the purpose of exported configurations
4. **Validation**: Always validate imported files before using them in production
5. **Security**: Store exported files securely, especially if they contain sensitive data 