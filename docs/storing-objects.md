# Storing Objects

Open Register provides flexible storage options for objects, allowing organizations to store their data in various backends while maintaining a consistent interface.

## Overview

The storage system supports:
- Multiple storage backends
- Transparent data access
- Unified querying interface
- Consistent audit logging
- Schema validation across stores

## Storage Options

### Nextcloud Internal Store (Default)
Default storage using Nextcloud's database:
- Objects stored as JSON in MariaDB/MySQL
- Full CRUD operations
- Built-in versioning
- Automatic audit trails
- Integrated with Nextcloud permissions

### Relational Databases
Traditional SQL databases:
- MariaDB/MySQL
- PostgreSQL
- JSON column types
- SQL query optimization
- Transaction support

### Document Stores
NoSQL databases optimized for JSON documents:
- MongoDB support
- Native JSON storage
- Schema-less flexibility
- High performance queries
- Scalable architecture

### EAV (Entity-Attribute-Value)
Integration with systems like "Uit Betrouwbare Bron":
- Dynamic attribute storage
- Flexible schema support
- Legacy system compatibility
- Custom attribute mapping
- Historical data support

## Storage Configuration

Each register defines:
- Storage backend type
- Connection details
- Schema mapping
- Query optimization
- Cache settings

## Implementation Details

### Internal Store
```sql
CREATE TABLE objects (
    id INT PRIMARY KEY,
    uuid VARCHAR(255),
    object JSON,
    metadata JSON
)
```

### Document Store
```json
{
    "_id": "object_id",
    "uuid": "unique_id",
    "object": {
        "property": "value"
    },
    "metadata": {
        "created": "timestamp"
    }
}
```

### Relational Store
```sql
CREATE TABLE objects (
    id INT PRIMARY KEY,
    uuid VARCHAR(255),
    data JSONB,
    created_at TIMESTAMP
)
```

### EAV Store
```sql
CREATE TABLE object_values (
    object_id INT,
    attribute VARCHAR(255),
    value TEXT,
    PRIMARY KEY (object_id, attribute)
)
```

## Key Benefits

1. **Flexibility**
   - Choose optimal storage per register
   - Match existing infrastructure
   - Scale independently
   - Custom implementations

2. **Integration**
   - Legacy system support
   - Multiple databases
   - External systems
   - Distributed storage

3. **Performance**
   - Storage optimization
   - Query efficiency
   - Caching strategies
   - Scalability options

## Best Practices

1. **Storage Selection**
   - Consider data structure
   - Evaluate query patterns
   - Assess volume requirements
   - Plan for growth

2. **Configuration**
   - Optimize connections
   - Set up caching
   - Configure backups
   - Monitor performance

3. **Management**
   - Regular maintenance
   - Performance monitoring
   - Security updates
   - Backup verification

## Related Features

- [Schema Validation](schema-validation.md) - Validate across storage types
- [Audit Trails](audit-trails.md) - Track changes in all stores
- [Content Search](content-search.md) - Search across stores
- [Object Relations](object-relations.md) - Relations across stores 