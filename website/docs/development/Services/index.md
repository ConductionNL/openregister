---
title: Development
sidebar_position: 1
description: Overview of all OpenRegister Services
---

# Welcome Developers

Welcome to the OpenRegister development documentation. This guide will help you set up your first development environment with OpenRegister.

## Setting Up a Production Environment

### Step 1: Setting Up a Generic Nextcloud Development Environment

To begin, you need to install Nextcloud. Follow the [official Nextcloud installation guide](https://docs.nextcloud.com/server/latest/developer_manual/exapp_development/DevSetup.html) to set up a generic Nextcloud environment on your server or local machine. Ensure that the Nextcloud instance is configured and running smoothly.

### Step 2: Setting Up This Specific Repository

With Nextcloud operational, proceed to set up the OpenRegister repository. Clone the repository from GitHub into the `apps-extra` folder of your Nextcloud installation using the following command:
   ```bash
   git clone https://github.com/your-repo/OpenRegister.git /path/to/nextcloud/apps/OpenRegister
   ```

Navigate into the cloned repository and install the necessary dependencies with:
   ```bash
   cd /path/to/nextcloud/apps-extra/OpenRegister
   npm install
   composer install
   ```

### Step 3: Useful Commands

After setting up, there are several commands that can assist you in development. To watch for changes and automatically rebuild the project, use:
   ```bash
   npm run watch
   ```
To ensure your code is properly formatted, run the linting command:
   ```bash
   npm run lint
   ```

### Step 4: Setting Up a Database Connection

The next step is to set up a database connection. Use DBeaver as your database management tool, which can be downloaded from [https://dbeaver.io/](https://dbeaver.io/). Open DBeaver and configure a new database connection to your Nextcloud database using the following default settings:

| Setting   | Value      |
|-----------|------------|
| host      | localhost  |
| port      | 8215       |
| database  | nextcloud  |
| username  | nextcloud  |
| password  | nextcloud  |

By completing these steps, you will have a fully functional development environment for OpenRegister. Happy coding!

## Core Services

OpenRegister is built on several key services that handle different aspects of the application:

### Performance & Caching
- **[CacheInvalidationService](../../technical/cache-invalidation-system.md)**: Enterprise-grade cache consistency across all CRUD operations with RBAC compliance

### Object Management  
- **ObjectService**: High-level object orchestration and bulk operations
- **SaveObject**: Individual object save/create/update logic with relations handling
- **GetObject**: Object retrieval with RBAC and multi-tenancy
- **DeleteObject**: Object deletion with cascade handling

### Data Validation
- **ValidateObject**: Schema validation and error handling
- **ValidationService**: General validation utilities

### Search & Faceting
- **[FacetService](../../technical/unified-faceting-system.md)**: Hyper-performant faceting system with intelligent caching
- **SearchService**: Full-text search capabilities
- **SearchTrailService**: Search analytics and trail logging

### Configuration & Schema
- **ConfigurationService**: Schema and register configuration management  
- **SchemaPropertyValidatorService**: Schema property validation

### Multi-tenancy & Organizations
- **OrganisationService**: Multi-tenant organization management
- **RegisterService**: Register lifecycle management

### Import/Export
- **[ImportService](../../technical/import-export-implementation.md)**: Bulk data import capabilities
- **ExportService**: Data export in various formats
- **UploadService**: File upload handling

### Files & Assets
- **FileService**: File attachment management
- **DownloadService**: File download and streaming

For detailed technical documentation on these services, see the [Cache Invalidation System](../../technical/cache-invalidation-system.md) and [Unified Faceting System](../../technical/unified-faceting-system.md).
