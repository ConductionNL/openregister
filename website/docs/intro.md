# Introduction

Open Register is a versatile system for creating and managing domain-specific or organizational data registers. Whether you need to build a social security database, manage client information, or create any other structured data repository, Open Register provides a storage-independent solution for managing and validating data objects.

## Core Concepts


Open Register operates on three fundamental principles:

1. **JSON Schema Validation**: All data objects are validated against predefined [JSON Schema](https://json-schema.org/) definitions. This ensures data integrity and consistency across your application.

2. **Storage Independence**: Objects can be stored in various backends without changing your application logic:
   - Nextcloud internal database
   - External SQL databases (MySQL, MariaDB, PostgreSQL)
   - Document stores (MongoDB)
   - *More backends can be added through the storage adapter interface*

3. **Flexible Schema Sources**: Register schemas can be:
   - Defined manually for custom requirements
   - Imported from Schema.org for standardized data structures
   - Imported from Dutch GGM (Gemeentelijk Gegevensmodel) for government data
   - Created from other sources and standards


## Key Features

| Feature | Description | Benefits |
|---------|-------------|-----------|
| 💾 [Storing Objects](Features/storing-objects.md) | Configure how and where register data is stored | Storage flexibility, system integration, scalability |
| 📝 [Audit Trails](Features/audit-trails.md) | Complete history of all object changes | Compliance, accountability, change tracking |
| ⏰ [Time Travel](Features/time-travel.md) | View and restore previous object states | Data recovery, historical analysis, version control |
| 🔒 [Object Locking](Features/object-locking.md) | Prevent concurrent modifications | Data integrity, process management, conflict prevention |
| 🗑️ [Soft Deletes](Features/soft-deletes.md) | Safely remove objects with recovery options | Data safety, compliance, mistake recovery |
| 🔗 [Object Relations](Features/object-relations.md) | Create and manage connections between objects | Complex data structures, linked information, dependencies |
| 📎 [File Attachments](Features/file-attachments.md) | Manage files associated with objects | Document management, version control, previews |
| 🔍 [Content Search](Features/content-search.md) | Full-text search across objects and files | Quick discovery, unified search, advanced filtering |
| 🏷️ [Automatic Facets](Features/automatic-facets.md) | Dynamic filtering based on object properties | Intuitive navigation, pattern discovery, smart filtering |
| ✅ [Schema Validation](Features/schema-validation.md) | Validate objects against JSON schemas | Data quality, consistency, structure enforcement |
| 📚 [Register Management](Features/register-management.md) | Organize collections of related objects | Logical grouping, access control, process automation |
| 🔐 [Access Control](Features/access-control.md) | Fine-grained permissions management | Security, role management, granular control |
| ⚡ [Elasticsearch](Features/elasticsearch.md) | Advanced search and analytics capabilities | Performance, insights, complex queries |
| 📋 [Schema Import & Sharing](Features/schema-import.md) | Import schemas from Schema.org, OAS, GGM, and share via Open Catalogi | Standards compliance, reuse, collaboration |
| 🔔 [Events & Webhooks](Features/events.md) | React to object changes with events and webhooks | Integration, automation, real-time updates |
| ✂️ [Data Filtering](Features/data-filtering.md) | Select specific properties to return | Data minimalization, GDPR compliance, efficient responses |
| 🔍 [Advanced Search](Features/advanced-search.md) | Filter objects using flexible property-based queries | Precise filtering, complex conditions, efficient results |
| 🗑️ [Object Deletion](Features/object-deletion.md) | Soft deletion with retention and recovery | Data safety, compliance, lifecycle management |

### Basic Workflow

1. Define or import your register schema (e.g., client database, social security records)
2. Client sends a JSON object via API
3. Open Register validates it against the corresponding JSON Schema
4. If valid, the object is stored in the configured backend
5. The object can be retrieved later, regardless of the storage backend

![Core Concepts](diagrams/core-concept.svg)

## Project Structure

    ```plantuml
    @startuml
    
    package "Open Register" {
      [Register] o-- [Schema]
      [Register] o-- [Object]
      [Object] o-- [File]
      [Object] o-- [Relation]
      [Object] o-- [AuditTrail]
      [Object] o-- [Lock]
      
      database "Storage" {
        [SQL Database]
        [MongoDB]
        [Nextcloud DB]
      }
      
      [Object] --> [Storage]
    }
    
    note right of [Register]
      Manages collections of related objects
      with shared schemas and permissions
    end note
    
    note right of [Schema] 
      JSON Schema definition that validates
      object structure and data types
    end note
    
    note right of [Object]
      Core entity containing the actual data,
      validated against its schema
    end note
    
    @enduml
    ```

## Contributing

1. Create a new branch from 'documentation'
2. Make your changes
3. Test locally using 'npm start'
4. Create a Pull Request to the 'documentation' branch
