# Object Storage

Open Register provides flexible storage options for objects through configurable data sources per register. This allows organizations to store their data where it makes the most sense while maintaining a unified interface.

## Overview

The storage system supports:
- Default blob storage in Nextcloud
- External database connections
- Object storage systems
- Elasticsearch backends
- External API integrations
- Multiple sources per installation

## Storage Options

### Default Storage
When no source is defined, objects are stored as JSON blobs in the Nextcloud database, providing:
- Simple setup
- Built-in backup
- Direct integration
- No external dependencies

### External Databases
Connect registers to external databases:
- MySQL/MariaDB
- PostgreSQL
- MongoDB
- Custom database adapters

### Object Stores
Store objects in dedicated object storage:
- S3-compatible storage
- MinIO
- Azure Blob Storage
- Custom object store adapters

### Search Engines
Use search engines as primary storage:
- Elasticsearch
- OpenSearch
- Custom search engine adapters

### External APIs
Connect to external systems:
- REST APIs
- GraphQL endpoints
- Custom API adapters
- Other Open Register instances

## Key Benefits

1. **Flexibility**
   - Choose optimal storage per register
   - Mix different storage types
   - Adapt to existing infrastructure
   - Scale storage independently

2. **Integration**
   - Connect to existing systems
   - Maintain data where it belongs
   - Unified access interface
   - Transparent to users

3. **Performance**
   - Optimize for specific use cases
   - Scale according to needs
   - Use specialized systems
   - Maintain efficiency

4. **Migration**
   - Easy data migration
   - Change sources without impact
   - Test different storage options
   - Gradual transitions

## Configuration

Sources are configured at the register level, allowing different registers to use different storage backends while maintaining a consistent interface for users and applications.

## Related Features

- [Register Management](register-management.md) - Configure register storage
- [Schema Validation](schema-validation.md) - Validate across storage types
- [Content Search](content-search.md) - Search across storage types
- [Access Control](access-control.md) - Unified permissions across sources 