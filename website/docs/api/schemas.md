## Error Handling for Missing Register or Schema

If you request a schema or register by slug or ID that does not exist, the API will return a 404 Not Found response with a clear error message. This applies to all endpoints that use register or schema slugs/IDs, including object listing, creation, update, and detail endpoints.

### Example Error Response

'
{
  'message': 'Schema not found: voorzieningen'
}
'

or

'
{
  'message': 'Register not found: voorzieningen'
}
'

**Note:**
- The error message will specify whether the missing resource is a register or a schema.
- This behavior ensures that clients can distinguish between missing resources and other types of errors.

## Schema Relationships (related endpoint)

The '/related' endpoint for schemas returns both:
- **incoming**: schemas that reference the given schema (i.e., schemas that have a property with a $ref to this schema)
- **outgoing**: schemas that the given schema refers to in its own properties (i.e., schemas this schema references)

This provides a full bidirectional view of schema relationships.

### Example Request

'GET /api/schemas/{id}/related'

### Example Response

'
{
  'incoming': [
    { 'id': 2, 'title': 'Referrer Schema', ... },
    ...
  ],
  'outgoing': [
    { 'id': 3, 'title': 'Referenced Schema', ... },
    ...
  ],
  'total': 2
}
'

- 'incoming' contains schemas that reference the given schema.
- 'outgoing' contains schemas that are referenced by the given schema.
- 'total' is the sum of both arrays.

This endpoint helps you understand both which schemas depend on a given schema and which schemas it depends on.

## Schema Statistics (stats)

The 'stats' object for a schema now includes the following fields:

| Field      | Type   | Description |
|------------|--------|-------------|
| objects    | object | Statistics about objects attached to the schema |
| logs       | object | Statistics about logs (audit trails) for the schema |
| files      | object | Statistics about files for the schema |
| registers  | int    | The number of registers that reference this schema |

Example:

'
{
  'id': 123,
  'title': 'My Schema',
  ...
  'stats': {
    'objects': { 'total': 10, ... },
    'logs': { 'total': 5, ... },
    'files': { 'total': 0, 'size': 0 },
    'registers': 2
  }
}
' 