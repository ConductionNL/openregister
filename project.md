# OpenRegister

## Overview
OpenRegister is a Nextcloud app that provides a core data registration platform for structured data management. It enables creating registers, defining schemas, and managing objects with full CRUD operations.

## Repository
- **GitHub**: https://github.com/ConductionNL/openregister
- **Organization**: ConductionNL
- **Container mount**: `/var/www/html/custom_apps/openregister`

## Architecture

### Key Components
- **Registers** — Named collections of objects (like databases)
- **Schemas** — JSON Schema definitions that validate objects
- **Objects** — Individual data records conforming to a schema
- **Sources** — External data sources that can sync into registers
- **ObjectService** — Core service for CRUD operations on objects

### Important Patterns
- `ObjectService::saveObject($objectOrArray)` — Takes entity or array as first argument (NOT a type string)
- Config stored via `IAppConfig` with keys like `listing_schema`, `listing_register`, `listing_source`
- Route ordering: specific routes MUST come before wildcard `{slug}` routes

### Directory Structure
```
lib/
  Controller/       # API and page controllers
  Service/          # Business logic (ObjectService, RegisterService, etc.)
  Db/               # Entities and Mappers
  Migration/        # Database migrations
appinfo/
  info.xml          # App metadata
  routes.php        # Route definitions
```

## Dependencies
- **Depends on**: Nextcloud core, PostgreSQL with pgvector
- **Depended on by**: opencatalogi, softwarecatalog (they use OpenRegister's ObjectService)

## API
- Base URL: `/index.php/apps/openregister/api/`
- Auth: Nextcloud session or Basic auth
- Format: JSON

## Testing
- Test via the Nextcloud container: `docker exec nextcloud php /var/www/html/custom_apps/openregister/...`
- Verify with dependent apps enabled
