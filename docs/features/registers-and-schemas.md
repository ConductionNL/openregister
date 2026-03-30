# Registers & Schemas

## Overview

Registers and Schemas form the foundational data model in OpenRegister. A **Register** is a named container that groups one or more related schemas and their objects — analogous to a database or application namespace. A **Schema** is a JSON Schema-based definition that specifies the structure, validation rules, types, and authorization policies for objects stored within a register.

Together they provide a schema-driven, storage-independent architecture: all objects are validated against their schema before persistence, and schema definitions can be sourced from standards (Schema.org, GGM) or defined manually.

## Key Concepts

### Registers

A register is a logical grouping of schemas and objects. It provides:

- A shared namespace for a domain (e.g., `meldingen-register`, `personen-register`)
- Default authorization that cascades to schemas without their own RBAC
- Configuration export/import in OpenAPI 3.0.0 format for environment portability
- Slug-based routing so every register gets its own REST and GraphQL API surface
- Optional multi-tenancy scoping via the `organisation` field

### Schemas

A schema defines the structure and validation rules for objects. Key capabilities:

- **JSON Schema validation** — properties, required fields, types (`string`, `integer`, `number`, `boolean`, `array`, `object`), formats (`date`, `date-time`, `uuid`, `email`, `uri`)
- **Schema versioning** — semantic versioning (`MAJOR.MINOR.PATCH`) with automatic increment on structural changes
- **Translatable properties** — `translatable: true` on a property enables multi-language object values
- **Computed properties** — `computed: true` with a Twig expression evaluates derived values at save or read time
- **Facetable properties** — `facetable: true` or a configuration object enables faceted drill-down search
- **Authorization block** — per-property and per-schema RBAC rules (see [Access Control](access-control.md))
- **Hooks** — lifecycle callbacks to workflow engines (see [Workflow Automation](workflow-automation.md))
- **Archive configuration** — `archive.enabled`, `archive.defaultNominatie`, `archive.defaultBewaartermijn` for automatic archival metadata (see [Archiving](archiving.md))

### Schema Sources

Schemas can be imported from:

| Source | Description |
|--------|-------------|
| Manual | Define properties and validation rules in the UI or API |
| Schema.org | Import standardized vocabulary (Person, Organization, Event, etc.) |
| GGM (Gemeentelijk Gegevensmodel) | Dutch municipal data model for government registers |
| Custom JSON Schema | Upload or POST any valid JSON Schema document |
| OAS Import | Import schema definitions from an existing OpenAPI 3.0+ specification |

## API

### Registers

```
GET    /api/registers                  List all registers (with pagination)
POST   /api/registers                  Create a new register
GET    /api/registers/{id}             Get a register by ID or slug
PUT    /api/registers/{id}             Update a register
DELETE /api/registers/{id}             Delete a register
GET    /api/registers/{id}/export      Export register configuration as OpenAPI YAML
POST   /api/registers/import           Import register + schemas from OpenAPI YAML
```

### Schemas

```
GET    /api/schemas                    List all schemas
POST   /api/schemas                    Create a new schema
GET    /api/schemas/{id}               Get a schema by ID or slug
PUT    /api/schemas/{id}               Update a schema
DELETE /api/schemas/{id}               Delete a schema
POST   /api/schemas/import             Import schema from JSON Schema or Schema.org URL
GET    /api/schemas/{id}/oas           Get the OpenAPI spec generated from this schema
```

## Configuration Export / Import

Register configurations (including all schemas, hooks, and authorization blocks) can be exported and imported as OpenAPI 3.0.0 documents. This enables:

- Environment promotion (dev → test → production)
- Version-controlled schema definitions in Git
- Sharing register templates between organisations
- Idempotent re-import: running an import twice produces the same result

The export format uses slug-based references so imported configurations work regardless of database IDs on the target environment.

## Standards

| Standard | Role |
|----------|------|
| JSON Schema (Draft 7 / 2020-12) | Property type/format validation |
| Schema.org | Vocabulary import |
| GGM (Gemeentelijk Gegevensmodel) | Dutch municipal schema import |
| OpenAPI 3.0.0 | Configuration export/import format |
| NL API Design Rules | REST endpoint naming and versioning conventions |

## Related Features

- [Object Storage & Lifecycle](object-storage.md) — objects stored under schemas
- [Access Control (RBAC)](access-control.md) — schema-level and property-level authorization
- [OpenAPI & GraphQL APIs](api-generation.md) — auto-generated API from schema definitions
- [Computed Fields](computed-fields.md) — Twig-evaluated derived properties on schemas
- [Workflow Automation](workflow-automation.md) — schema hooks on lifecycle events
