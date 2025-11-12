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
- 🔍 **SOLR Integration**: Enhanced search capabilities with improved metadata handling and configuration management.
- 🔧 **Self-Metadata Handling**: Advanced metadata processing for better data organization and retrieval.
- 💾 **Flexible Storage**: Store objects in Nextcloud, external databases, or object stores.
- 🔄 **APIs**: Provide APIs for consumption.
- 🧩 **Additional Logic**: Apply extra validation and logic beyond [`schema.json`](https://json-schema.org/).
- 🗑️ [Object Deletion](website/docs/object-deletion.md) | Soft deletion with retention and recovery | Data safety, compliance, lifecycle management

## Comprehensive Feature Documentation

Detailed technical and user documentation for all features is available in the feature documentation:

**Core Features:**
- [Objects](website/docs/Features/objects.md) - Object management, lifecycle, and relationships
- [Schemas](website/docs/Features/schemas.md) - Schema definition, validation, and management
- [Registers](website/docs/Features/registers.md) - Register configuration and organization
- [Files](website/docs/Features/files.md) - File attachments, text extraction (LLPhant & Dolphin AI), OCR support
- [Events](website/docs/Features/events.md) - Event system and webhooks
- [Multi-Tenancy](website/docs/Features/multi-tenancy.md) - Organization-based isolation and access control
- [Access Control](website/docs/Features/access-control.md) - Role-based permissions and security

**Search & Discovery:**
- [Search](website/docs/Features/search.md) - Full-text search, case-insensitive search, metadata filtering, ordering
- [Faceting](website/docs/Features/faceting.md) - Automatic facets, UUID resolution, dynamic filtering
- [Search Trails](website/docs/Features/search-trails.md) - Search history and analytics

**Additional Resources:**
- [Developer Guide](website/docs/developers.md) - Development setup and guidelines
- [Styleguide](website/docs/styleguide.md) - Coding standards and best practices
- [Enhanced Validation Errors](website/docs/Features/enhanced-validation-errors.md) - Detailed error messages

Full documentation site: [https://openregisters.app/](https://openregisters.app/)

## Documentation

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
- AI-powered semantic search and content understanding

## Key Features

### Core Features

| Feature | Description | Benefits |
|---------|-------------|-----------|
| 💾 [Storing Objects](website/docs/storing-objects.md) | Configure how and where register data is stored | Storage flexibility, system integration, scalability |
| 📝 [Audit Trails](website/docs/audit-trails.md) | Complete history of all object changes | Compliance, accountability, change tracking |
| ⏰ [Time Travel](website/docs/time-travel.md) | View and restore previous object states | Data recovery, historical analysis, version control |
| 🔒 [Object Locking](website/docs/object-locking.md) | Prevent concurrent modifications | Data integrity, process management, conflict prevention |
| 🗑️ [Soft Deletes](website/docs/soft-deletes.md) | Safely remove objects with recovery options | Data safety, compliance, mistake recovery |
| 🔗 [Object Relations](website/docs/object-relations.md) | Create and manage connections between objects | Complex data structures, linked information, dependencies |
| 📎 [File Attachments](website/docs/file-attachments.md) | Manage files associated with objects | Document management, version control, previews |
| ✅ [Schema Validation](website/docs/schema-validation.md) | Validate objects against JSON schemas | Data quality, consistency, structure enforcement |
| 📚 [Register Management](website/docs/register-management.md) | Organize collections of related objects | Logical grouping, access control, process automation |
| 🔐 [Access Control](website/docs/access-control.md) | Fine-grained permissions management | Security, role management, granular control |
| 📋 [Schema Import & Sharing](website/docs/schema-import.md) | Import schemas from Schema.org, OAS, GGM, and share via Open Catalogi | Standards compliance, reuse, collaboration |
| 🔔 [Events & Webhooks](website/docs/events.md) | React to object changes with events and webhooks | Integration, automation, real-time updates |
| ✂️ [Data Filtering](website/docs/data-filtering.md) | Select specific properties to return | Data minimalization, GDPR compliance, efficient responses |
| ⚡ [Bulk Operations](website/docs/api/bulk-operations.md) | Perform operations on multiple objects simultaneously | Performance, efficiency, batch processing |

### AI & Search Features

| Feature | Description | Benefits |
|---------|-------------|-----------|
| 🔍 [Content Search](website/docs/content-search.md) | Full-text search across objects and files with SOLR | Quick discovery, unified search, advanced filtering |
| 🏷️ [Automatic Facets](website/docs/automatic-facets.md) | Dynamic filtering based on object properties | Intuitive navigation, pattern discovery, smart filtering |
| 🔍 [Advanced Search](website/docs/advanced-search.md) | Filter objects using flexible property-based queries | Precise filtering, complex conditions, efficient results |
| 🤖 **Semantic Search** | AI-powered semantic search across objects and files | Find by meaning, not just keywords, better discovery |
| 🧮 **Vector Embeddings** | Automatic vectorization of objects and files | Enable semantic search, similarity matching, content understanding |
| ✍️ **Text Generation** | AI-powered content generation and completion | Automated documentation, content creation, efficiency |
| 📋 **Document Summarization** | Automatic summarization of documents and objects | Quick insights, time savings, overview generation |
| 🌍 **Translation** | Multi-language content translation | Accessibility, international reach, localization |
| 🏷️ **Content Classification** | Automatic content categorization and tagging | Organization, automation, metadata enrichment |
| 📄 **File Vectorization** | Chunk and vectorize documents for semantic search | Semantic file search, RAG capabilities, content understanding |

## AI-Powered Features

Open Register includes powerful AI capabilities powered by Large Language Models (LLMs) that enhance content discovery, organization, and understanding.

### Supported LLM Providers

- **OpenAI**: GPT-4, GPT-3.5 Turbo for chat and text-embedding models
- **Fireworks AI**: Fast, optimized inference with various open-source models
- **Ollama**: Run models locally for privacy and cost-effectiveness
- **Azure OpenAI**: Enterprise-grade AI through Microsoft Azure

### Key AI Capabilities

**🔍 Semantic Search**
- Find content by meaning, not just keywords
- Search across objects and files simultaneously
- Understand context and intent
- More accurate results than traditional keyword search

**🧮 Vector Embeddings**
- Automatic vectorization of objects on creation/update
- Automatic vectorization of files on upload (text extraction → chunks → embeddings)
- Multiple embedding models supported
- Efficient vector storage and retrieval
- **Process Flow**: File → Text Extraction → Chunks (smaller text portions) → Embeddings (vector representations)

**📄 Intelligent File Processing**
- Support for PDF, DOCX, XLSX, TXT, MD, HTML, JSON, XML
- Image OCR support (JPG, PNG, GIF, TIFF, WebP)
- Smart document chunking (splitting files into smaller text portions)
- Configurable chunking strategies with overlap for better context preservation
- Text extraction required before chunking and vectorization

**✍️ Content Generation & Summarization**
- AI-powered text generation
- Automatic document summarization
- Content classification and tagging
- Multi-language translation

### Configuration

AI features are easily configured through the Settings page:

1. **LLM Configuration**: Set up your preferred AI provider and models
2. **File Management**: Configure which file types to vectorize and chunking settings
3. **Object Management**: Control which schemas are vectorized and when

### Privacy & Cost Management

- **Local Options**: Use Ollama to run models on your own infrastructure
- **Usage Tracking**: Monitor API usage and estimated costs
- **Flexible Control**: Enable/disable features per your needs
- **Selective Vectorization**: Choose which objects and files to process

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

## Installation

This project is designed to be installed from the [nextcloud app store](https://apps.nextcloud.com/apps/openregister). Alternativly a [docker-compose.yml](docker-compose.yml) is available in the repostory for quick testing. If you are looking to contribute then please setup your own development environment following [setting up a development environment](https://cloud.nextcloud.com/s/iyNGp8ryWxc7Efa?dir=/1%20Setting%20up%20a%20development%20environment/Tutorial%20for%20Windows&openfile=true).

## Contact

For more information, please contact [info@conduction.nl](mailto:info@conduction.nl).
