# Importing Data

The OpenRegister application supports importing data from CSV and Excel files with improved performance and better result reporting.

## Supported Formats

- **CSV files** (.csv) - Single schema imports
- **Excel files** (.xlsx, .xls) - Single or multi-schema imports

## Import Process

### 1. Prepare Your Data

Your import file should have:
- **Headers row**: First row containing column names
- **Data rows**: Subsequent rows with actual data
- **ID column** (optional): Include an `id` column if you want to update existing records

### 2. Column Mapping

The system automatically maps CSV/Excel columns to object properties:

- **Regular columns** (e.g., `name`, `description`) become object properties
- **System columns** (prefixed with `_` or `@self.`) are placed in the system metadata
- **ID column**: If you include an `id` column, existing records will be updated; new records will be created

### 3. Import Results

After import, you'll see a detailed summary:

```json
{
    "message": "Import successful",
    "summary": {
        "Worksheet": {
            "found": 5600,
            "created": ["uuid1", "uuid2", "uuid3"],
            "updated": ["uuid4", "uuid5"],
            "unchanged": [],
            "errors": [],
            "schema": {
                "id": 90,
                "title": "Compliancy",
                "slug": "compliancy"
            }
        }
    }
}
```

#### Understanding the Results

- **`found`**: Total number of rows processed (excluding headers)
- **`created`**: Array of UUIDs for newly created objects
- **`updated`**: Array of UUIDs for existing objects that were updated
- **`unchanged`**: Array of UUIDs for objects that didn't change (if any)
- **`errors`**: Array of any errors encountered during import
- **`schema`**: Information about the schema used for the import

## Best Practices

### For Large Imports

- **Chunk size**: The system processes data in chunks (default: 100 rows) to manage memory
- **File size**: Very large files are automatically handled efficiently
- **Progress monitoring**: Monitor the import progress through the result summary

### Data Quality

- **Validate data**: Ensure your data meets schema requirements before import
- **Check headers**: Make sure column names match expected property names
- **Test with small files**: Test your import format with a small dataset first

### Update vs Create

- **To create new records**: Don't include an `id` column, or leave it empty
- **To update existing records**: Include the `id` column with existing UUIDs
- **Mixed operations**: You can have both new and existing records in the same import

## Common Scenarios

### Scenario 1: Creating New Records

```
CSV Content:
name,description,status
Item 1,First item description,active
Item 2,Second item description,pending
```

**Result**: All records will be created (UUIDs generated automatically)

### Scenario 2: Updating Existing Records

```
CSV Content:
id,name,description,status
existing-uuid-123,Updated Item 1,New description,active
existing-uuid-456,Updated Item 2,New description,pending
```

**Result**: Existing records will be updated with new values

### Scenario 3: Mixed Create and Update

```
CSV Content:
id,name,description,status
existing-uuid-123,Updated Item 1,New description,active
,New Item 2,New item description,pending
```

**Result**: First record will be updated, second will be created

## Error Handling

### Common Errors

- **Schema mismatch**: Column names don't match schema properties
- **Data type errors**: Values don't match expected data types
- **Required field missing**: Required properties are empty
- **Invalid UUIDs**: ID column contains invalid UUIDs

### Error Details

Each error includes:
- **Row number**: Which row had the problem
- **Error message**: Description of what went wrong
- **Data context**: The problematic data for debugging

## Performance Improvements

The latest version includes significant performance improvements:

- **Batch processing**: Multiple records are processed together for better efficiency
- **Reduced database calls**: Fewer database operations mean faster imports
- **Memory optimization**: Large files are processed in manageable chunks
- **Transaction efficiency**: Related operations are grouped for better performance

## Troubleshooting

### Import Not Working

1. **Check file format**: Ensure your file is a valid CSV or Excel file
2. **Verify schema**: Make sure you've selected the correct schema for import
3. **Check permissions**: Ensure you have permission to import to the selected register
4. **Review errors**: Check the error messages for specific issues

### Performance Issues

1. **Reduce chunk size**: If memory issues occur, try smaller chunk sizes
2. **Check file size**: Very large files may take longer to process
3. **Monitor system resources**: Ensure adequate memory and CPU resources

### Data Not Appearing

1. **Check import status**: Verify the import completed successfully
2. **Review error log**: Look for any errors that prevented data from being saved
3. **Verify schema mapping**: Ensure columns are mapped to the correct properties
4. **Check permissions**: Confirm you can view the imported data

## Support

If you encounter issues with data import:

1. **Check the documentation**: Review this guide for common solutions
2. **Review error messages**: The detailed error information often contains the solution
3. **Contact support**: Provide the error details and import file format for assistance
