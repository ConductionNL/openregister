# Open Registers

Open Registers provides a way to quicly build and deploy standardized registers based on [NLGov REST API Design Rules](https://logius-standaarden.github.io/API-Design-Rules/) and [Common Ground Principles](https://common-ground.nl/common-ground-principes/). It is based on based on the concepts of defining object types in [`schema.json`](https://json-schema.org/) and storing objects in configurable source.

## What is Open Registers? 

Open Registers is a system for managing registers in Nextcloud. A register is a collection of one or more object types that are defined by a [`schema.json`](https://json-schema.org/). Registers sort objects and validate them against their object types.

Registers can store objects either directly in the Nextcloud database, or in an external database or object store.

Registers provide APIs for consumption.

Registers can also apply additional logic to objects, such as validation that is not applicable through the [`schema.json`](https://json-schema.org/) format.

## Key Features

- 📦 **Object Management**: Work with objects based on [`schema.json`](https://json-schema.org/).
- 🗂️ **Register System**: Manage collections of object types.
- 🛡️ **Validation**: Validate objects against their types.
- 🏢 **Multi-Tenancy**: Complete organisation-based data isolation with user management and role-based access control.
- 💾 **Flexible Storage**: Store objects in Nextcloud, external databases, or object stores.
- 🔄 **APIs**: Provide APIs for consumption.
- 🧩 **Additional Logic**: Apply extra validation and logic beyond [`schema.json`](https://json-schema.org/).
- 🗑️ [Object Deletion](website/docs/object-deletion.md) | Soft deletion with retention and recovery | Data safety, compliance, lifecycle management

## Documentation

For more detailed information, please refer to the documentation files in the `docs` folder:

- [Developer Guide](website/docs/developers.md)
- [Styleguide](website/docs/styleguide.md)

# Open Register

Open Register is a powerful object management system for Nextcloud that helps organizations store, track, and manage objects with their associated metadata, files, and relationships. Born from the Dutch Common Ground initiative, it addresses the need for quickly deploying standardized registers based on centralized definitions from standardization organizations.

## Background

Open Register emerged from the Dutch Common Ground movement, which aims to modernize municipal datamanagement. The project specifically addresses the challenge many organizations face: implementing standardized registers quickly and cost-effectively while maintaining compliance with central definitions.

### Common Ground Principles
- Decentralized data storage
- Component-based architecture
- Standardized definitions
- API-first approach

Open Register makes these principles accessible to any organization by providing:
- Quick register deployment based on standard schemas
- Flexible storage options
- Built-in compliance features
- Cost-effective implementation

## Key Features

| Feature | Description | Benefits |
|---------|-------------|-----------|
| 💾 [Storing Objects](website/docs/storing-objects.md) | Configure how and where register data is stored | Storage flexibility, system integration, scalability |
| 📝 [Audit Trails](website/docs/audit-trails.md) | Complete history of all object changes | Compliance, accountability, change tracking |
| ⏰ [Time Travel](website/docs/time-travel.md) | View and restore previous object states | Data recovery, historical analysis, version control |
| 🔒 [Object Locking](website/docs/object-locking.md) | Prevent concurrent modifications | Data integrity, process management, conflict prevention |
| 🗑️ [Soft Deletes](website/docs/soft-deletes.md) | Safely remove objects with recovery options | Data safety, compliance, mistake recovery |
| 🔗 [Object Relations](website/docs/object-relations.md) | Create and manage connections between objects | Complex data structures, linked information, dependencies |
| 📎 [File Attachments](website/docs/file-attachments.md) | Manage files associated with objects | Document management, version control, previews |
| 🔍 [Content Search](website/docs/content-search.md) | Full-text search across objects and files | Quick discovery, unified search, advanced filtering |
| 🏷️ [Automatic Facets](website/docs/automatic-facets.md) | Dynamic filtering based on object properties | Intuitive navigation, pattern discovery, smart filtering |
| ✅ [Schema Validation](website/docs/schema-validation.md) | Validate objects against JSON schemas | Data quality, consistency, structure enforcement |
| 📚 [Register Management](website/docs/register-management.md) | Organize collections of related objects | Logical grouping, access control, process automation |
| 🔐 [Access Control](website/docs/access-control.md) | Fine-grained permissions management | Security, role management, granular control |
| ⚡ [Elasticsearch](website/docs/elasticsearch.md) | Advanced search and analytics capabilities | Performance, insights, complex queries |
| 📋 [Schema Import & Sharing](website/docs/schema-import.md) | Import schemas from Schema.org, OAS, GGM, and share via Open Catalogi | Standards compliance, reuse, collaboration |
| 🔔 [Events & Webhooks](website/docs/events.md) | React to object changes with events and webhooks | Integration, automation, real-time updates |
| ✂️ [Data Filtering](website/docs/data-filtering.md) | Select specific properties to return | Data minimalization, GDPR compliance, efficient responses |
| 🔍 [Advanced Search](website/docs/advanced-search.md) | Filter objects using flexible property-based queries | Precise filtering, complex conditions, efficient results |
| 🗑️ [Object Deletion](website/docs/object-deletion.md) | Soft deletion with retention and recovery | Data safety, compliance, lifecycle management |
| ⚡ [Bulk Operations](website/docs/api/bulk-operations.md) | Perform operations on multiple objects simultaneously | Performance, efficiency, batch processing |

## Documentation

Documentation is available at [https://openregisters.app/](https://openregisters.app/) and created from the website folder of this repository.

## Requirements

- Nextcloud 25 or higher
- PHP 8.1 or higher
- Database: MySQL/MariaDB

<!-- ## Installation

[Installation instructions](https://conduction.nl/openconnector/installation)

## Support

[Support information](https://conduction.nl/openconnector/support) -->

## Project Structure

This monorepo is a Nextcloud app, it is based on the following structure:

    /
    ├── app/          # App initialization and bootstrap files
    ├── appinfo/      # Nextcloud app metadata and configuration
    ├── css/          # Stylesheets for the app interface
    ├── docker/       # Docker configuration for development
    ├── img/          # App icons and images
    ├── js/           # JavaScript files for frontend functionality
    ├── lib/          # PHP library files containing core business logic
    ├── src/          # Vue.js frontend application source code
    ├── templates/    # Template files for rendering app views
    └── website/      # Documentation website source files

When running locally, or in development mode the folders node_modules and vendor are added. These should however not be commited.

## Contributing

Please see our [Contributing Guide](CONTRIBUTING.md) for details on how to contribute to this project.

## License

This project is licensed under the EUPL License - see the [LICENSE](LICENSE) file for details.

## Contact

For more information, please contact [info@conduction.nl](mailto:info@conduction.nl).
