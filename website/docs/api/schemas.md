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