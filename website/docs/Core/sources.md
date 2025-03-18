---
title: Sources
sidebar_position: 5
---

import ApiSchema from '@theme/ApiSchema';
import Tabs from '@theme/Tabs';
import TabItem from '@theme/TabItem';

# Sources

## What is a Source?

In Open Register, a **Source** defines where and how data is stored. Sources provide the connection details and configuration for the storage backends that hold your registers and objects. They act as the bridge between your data model and the physical storage layer.

Sources allow Open Register to support multiple storage technologies, giving you flexibility in how you deploy and scale your data management solution.

## Source Structure

A source in Open Register consists of the following key components:

| Property | Description |
|----------|-------------|
| `id` | Unique identifier for the source |
| `title` | Human-readable name of the source |
| `description` | Detailed explanation of the source's purpose |
| `databaseUrl` | URL of the database |
| `type` | Type of the source (e.g., 'internal', 'mongodb') |
| `updated` | Timestamp of last update |
| `created` | Timestamp of creation |

## Example Source

```json
{
  "id": "primary-source",
  "title": "Primary Database",
  "description": "Main database for production data",
  "databaseUrl": "mongodb://localhost:27017/openregister",
  "type": "mongodb",
  "updated": "2023-03-10T16:45:00Z",
  "created": "2023-01-01T00:00:00Z"
}
```

## Supported Source Types

Open Register supports multiple types of storage backends:

### 1. Internal

The internal source type uses Nextcloud's built-in database for storage. This is the simplest option and works well for smaller deployments or when you want to keep everything within Nextcloud.

### 2. MongoDB

MongoDB sources provide scalable, document-oriented storage that works well with JSON data. This option is good for larger deployments or when you need advanced querying capabilities.

### 3. Custom Sources (via Extensions)

The Open Register architecture allows for extending the system with custom source types through extensions, enabling integration with other database technologies or specialized storage systems.

## Source Use Cases

Sources serve several important purposes in Open Register:

### 1. Storage Configuration

Sources define where your data is physically stored, allowing you to choose the right database technology for your needs.

### 2. Performance Optimization

Different sources can be configured for different performance characteristics:
- High-throughput sources for frequently accessed data
- Archival sources for historical data
- In-memory sources for ultra-fast access to critical data

### 3. Data Segregation

Multiple sources allow you to segregate data based on security requirements, regulatory needs, or organizational boundaries.

### 4. Scalability

As your data grows, you can add new sources to distribute the load across multiple databases or clusters.

## Working with Sources

### Creating a Source

To create a new source, you define its connection details and type:

```json
POST /api/sources
{
  "title": "Analytics Database",
  "description": "Database for analytics data",
  "databaseUrl": "mongodb://analytics.example.com:27017/analytics",
  "type": "mongodb"
}
```

### Retrieving Source Information

You can retrieve information about a specific source:

```
GET /api/sources/{id}
```

Or list all available sources:

```
GET /api/sources
```

### Updating a Source

Sources can be updated to change connection details or other properties:

```json
PUT /api/sources/{id}
{
  "title": "Analytics Database",
  "description": "Updated database for analytics data",
  "databaseUrl": "mongodb://new-analytics.example.com:27017/analytics",
  "type": "mongodb"
}
```

### Deleting a Source

Sources can be deleted when no longer needed:

```
DELETE /api/sources/{id}
```

**Note**: Deleting a source does not delete the data in the underlying database. It only removes the connection configuration from Open Register.

## Source Configuration Best Practices

1. **Use Descriptive Names**: Give sources clear, descriptive names that indicate their purpose
2. **Document Connection Details**: Keep detailed documentation of connection strings and credentials
3. **Monitor Performance**: Regularly monitor source performance and adjust as needed
4. **Plan for Growth**: Design your source strategy with future growth in mind
5. **Security First**: Use secure connection strings and follow database security best practices
6. **Regular Backups**: Ensure all sources have appropriate backup strategies

## Relationship to Other Concepts

- **Registers**: Registers are associated with sources that determine where their data is stored
- **Objects**: Objects are stored in the sources configured for their registers
- **Databases**: Sources provide the connection details for the physical databases

## Advanced Source Features

### Connection Pooling

For high-traffic deployments, sources can be configured with connection pooling to optimize database connections.

### Read/Write Separation

Some source types support configuring separate read and write endpoints, allowing you to optimize for different access patterns.

### Sharding and Partitioning

For very large datasets, sources can be configured to support sharding or partitioning strategies.

## Conclusion

Sources are a critical part of the Open Register architecture, providing the flexibility to choose the right storage technology for your needs while maintaining a consistent data model and API. By separating the storage configuration from the data model, Open Register allows you to evolve your storage strategy independently from your data structure, giving you the best of both worlds: structured data with flexible storage options. 