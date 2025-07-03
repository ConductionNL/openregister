# Array Faceting

The OpenRegister faceting system now supports arrays as facetable fields. When a JSON object property contains an array of values, the system automatically creates separate facet buckets for each individual value instead of treating the entire array as a single facet.

## How It Works

### Previous Behavior
Before this enhancement, if you had objects with array fields like:
```json
{
  "id": 1,
  "fruit": ["apple", "pear"]
}
```

The faceting system would create a single facet bucket:
- `["apple", "pear"]` (1 result)

### New Behavior
Now the system creates separate facet buckets for each array element:
- `apple` (1 result)
- `pear` (1 result)

## Examples

### Sample Data
Consider these objects in your register:

```json
[
  {
    "id": 1,
    "name": "Store A",
    "categories": ["grocery", "pharmacy"]
  },
  {
    "id": 2,
    "name": "Store B", 
    "categories": ["grocery", "electronics"]
  },
  {
    "id": 3,
    "name": "Store C",
    "categories": ["pharmacy"]
  }
]
```

### Faceting Results
When faceting on the `categories` field, you'll get:
- `grocery` (2 results) - appears in Store A and Store B
- `pharmacy` (2 results) - appears in Store A and Store C
- `electronics` (1 result) - appears in Store B

### Filtering
The filtering system also works with arrays:

- **Filter by `categories: ["grocery"]`** - Returns Store A and Store B
- **Filter by `categories: ["pharmacy"]`** - Returns Store A and Store C
- **Filter by `categories: ["grocery", "pharmacy"]`** - Returns all stores (OR logic)

## Technical Implementation

### Detection
The system automatically detects array fields by:
1. Sampling a few objects from the database
2. Checking if more than 50% of the objects have arrays for that field
3. If so, treating it as an array field for faceting

### Database Queries
For array fields, the system uses:
- **Faceting**: Processes each object's JSON to extract individual array elements
- **Filtering**: Uses `JSON_CONTAINS` to check if values exist within arrays

### Performance
- Array fields are processed in-memory after fetching from database
- For large datasets, consider adding database indexes on commonly faceted array fields
- The system maintains good performance by only processing array fields when detected

## Schema Configuration

To enable faceting on array fields, ensure your schema property has:
```json
{
  "type": "array",
  "facetable": true,
  "items": {
    "type": "string"
  }
}
```

Only arrays of simple values (strings, numbers, booleans) are supported for faceting. Arrays of objects are not facetable.

## Benefits

1. **Better User Experience**: Users can filter by individual array elements
2. **Accurate Counts**: Facet counts reflect actual content distribution
3. **Flexible Filtering**: Support for both single values and arrays in filters
4. **Automatic Detection**: No configuration required - works automatically

## Limitations

- Only arrays of simple values are supported (strings, numbers, booleans)
- Arrays of objects or nested arrays are not facetable
- Performance depends on array size and dataset size
- Case-insensitive matching for string values 