# Open Registers

Open Registers provides the ability to work with objects based on [`schema.json`](https://json-schema.org/).

## What is Open Registers?

Open Registers is a system for managing registers in Nextcloud. A register is a collection of one or more object types that are defined by a [`schema.json`](https://json-schema.org/). Registers sort objects and validate them against their object types.

Registers can store objects either directly in the Nextcloud database, or in an external database or object store.

Registers provide APIs for consumption.

Registers can also apply additional logic to objects, such as validation that is not applicable through the [`schema.json`](https://json-schema.org/) format.

## Features

- 📦 **Object Management**: Work with objects based on [`schema.json`](https://json-schema.org/).
- 🗂️ **Register System**: Manage collections of object types.
- 🛡️ **Validation**: Validate objects against their types.
- 💾 **Flexible Storage**: Store objects in Nextcloud, external databases, or object stores.
- 🔄 **APIs**: Provide APIs for consumption.
- 🧩 **Additional Logic**: Apply extra validation and logic beyond [`schema.json`](https://json-schema.org/).

## Documentation

For more detailed information, please refer to the documentation files in the `docs` folder:

- [Developer Guide](docs/developers.md)
- [Styleguide](docs/styleguide.md)

## Project Structure

- **appinfo/routes.php**: Defines the routes for the application.
- **lib**: Contains all the PHP code for the application.
- **src**: Contains all the Vue.js code for the application.
- **docs**: Contains documentation files.

# Open Register

Open Register is a powerful object management system for Nextcloud that helps organizations store, track, and manage objects with their associated metadata, files, and relationships. Born from the Dutch Common Ground initiative, it addresses the need for quickly deploying standardized registers based on centralized definitions from standardization organizations.

## Background

Open Register emerged from the Dutch Common Ground movement, which aims to modernize municipal data management. The project specifically addresses the challenge many organizations face: implementing standardized registers quickly and cost-effectively while maintaining compliance with central definitions.

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
| 💾 [Object Storage](docs/object-storage.md) | Flexible storage backend selection per register | Storage flexibility, system integration, scalability |
| 📝 [Audit Trails](docs/audit-trails.md) | Complete history of all object changes | Compliance, accountability, change tracking |
| ⏰ [Time Travel](docs/time-travel.md) | View and restore previous object states | Data recovery, historical analysis, version control |
| 🔒 [Object Locking](docs/object-locking.md) | Prevent concurrent modifications | Data integrity, process management, conflict prevention |
| 🗑️ [Soft Deletes](docs/soft-deletes.md) | Safely remove objects with recovery options | Data safety, compliance, mistake recovery |
| 🔗 [Object Relations](docs/object-relations.md) | Create and manage connections between objects | Complex data structures, linked information, dependencies |
| 📎 [File Attachments](docs/file-attachments.md) | Manage files associated with objects | Document management, version control, previews |
| 🔍 [Content Search](docs/content-search.md) | Full-text search across objects and files | Quick discovery, unified search, advanced filtering |
| 🏷️ [Automatic Facets](docs/automatic-facets.md) | Dynamic filtering based on object properties | Intuitive navigation, pattern discovery, smart filtering |
| ✅ [Schema Validation](docs/schema-validation.md) | Validate objects against JSON schemas | Data quality, consistency, structure enforcement |
| 📚 [Register Management](docs/register-management.md) | Organize collections of related objects | Logical grouping, access control, process automation |
| 🔐 [Access Control](docs/access-control.md) | Fine-grained permissions management | Security, role management, granular control |
| ⚡ [Elasticsearch](docs/elasticsearch.md) | Advanced search and analytics capabilities | Performance, insights, complex queries |
| 📋 [Schema Import & Sharing](docs/schema-import.md) | Import schemas from Schema.org, OAS, GGM, and share via Open Catalogi | Standards compliance, reuse, collaboration |
| 🔔 [Events & Webhooks](docs/events.md) | React to object changes with events and webhooks | Integration, automation, real-time updates |

## Documentation

For detailed information about each feature, please visit our [documentation](docs/).

## Requirements

- Nextcloud 25 or higher
- PHP 8.1 or higher
- Database: MySQL/MariaDB

## Installation

[Installation instructions]

## Support

[Support information]

## License

This project is licensed under the AGPL-3.0 License - see the [LICENSE](LICENSE) file for details.
