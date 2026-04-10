# Object Storage & Lifecycle

## Overview

Objects are the core data entities in OpenRegister. An object is a single record that conforms to a schema, belongs to a register, and carries a rich set of metadata, lifecycle state, relations, and file attachments. The storage layer is backend-agnostic: objects are written through the `MagicMapper` abstraction which targets PostgreSQL, MySQL/MariaDB, or external document stores without changing application logic.

## Object Structure

Every object carries a fixed set of system fields alongside its schema-defined properties:

| Field | Type | Description |
|-------|------|-------------|
| `_uuid` | UUID | Globally unique identifier, assigned on creation |
| `_register` | string (slug) | The register this object belongs to |
| `_schema` | string (slug) | The schema this object conforms to |
| `_organisation` | string | Organisation scope for multi-tenancy |
| `_name` | string | Human-readable display name |
| `_description` | string | Short description |
| `_summary` | string | One-line summary for search and listings |
| `_version` | string | Semantic version (e.g. `1.0.4`) |
| `_created` | datetime | UTC timestamp of first creation |
| `_updated` | datetime | UTC timestamp of last modification |
| `_owner` | string | Nextcloud user ID of the creating user |
| `_locked` | object/null | Lock metadata when object is being edited |
| `_deleted` | object/null | Soft deletion metadata |
| `retention` | object | Archival metadata (MDTO-compliant) |

## Lifecycle States

### Creation

- Schema validation runs before any database write
- Version is set to `1.0.0` on first creation
- An `ObjectCreatingEvent` is dispatched (stoppable — hooks can reject creation)
- After persistence, an `ObjectCreatedEvent` is dispatched
- Audit trail entry is written with action `create` and full object snapshot

### Update

- Partial updates (PATCH) are merged with the existing object
- Schema validation runs on the merged result
- Version is incremented (PATCH component)
- `ObjectUpdatingEvent` is dispatched with both old and new state
- After persistence, `ObjectUpdatedEvent` is dispatched
- Audit trail records both old and new values for changed fields

### Soft Delete

All deletions are soft by default:

- `_deleted` field is set to a JSON object containing `deletedBy`, `deletedAt`, and `reason`
- Object is excluded from normal queries but retrievable via `?_deleted=true`
- A configurable retention period before physical purge (configured per schema via `archive.purgeAfter`)
- Deletion audit trail entry is written
- `ObjectDeletingEvent` and `ObjectDeletedEvent` are dispatched

Restore from soft delete is available via `POST /api/objects/{register}/{schema}/{id}/restore`.

### Object Locking

Objects can be locked to prevent concurrent edits:

- `POST /api/objects/{register}/{schema}/{id}/lock` — acquire a lock
- `DELETE /api/objects/{register}/{schema}/{id}/lock` — release a lock
- Lock metadata includes the user who holds the lock and when it was acquired
- Stale locks expire after a configurable timeout

## Relations

Objects can reference other objects within the same register or across registers:

- Properties with `type: string, format: uuid` and a `$ref` to another schema define typed relations
- `RelationHandler` validates that referenced UUIDs exist (reference existence validation)
- `BulkRelationHandler` resolves inverse relations efficiently during bulk operations
- Cascade delete and cascade update behaviours are configurable per relation property

## File Attachments

Files are linked to objects via Nextcloud's file storage:

- `POST /api/objects/{register}/{schema}/{id}/files` — attach a file
- Files are stored in Nextcloud's DMS layer
- File metadata (name, size, MIME type, PRONOM identifier, SHA-256 checksum) is tracked on the object
- Files are included in MDTO SIP packages for archival transfer

## Bulk Operations

The `SaveObjects` endpoint supports bulk create/update with:

- `ChunkProcessingHandler` — 60-70% fewer DB calls versus individual saves, 2-3x faster throughput
- `BulkValidationHandler` — caches schema analysis across items to avoid repeated parsing
- `BulkRelationHandler` — resolves inverse relations in a single pass
- Per-object error reporting in the response summary

```
POST /api/objects/{register}/{schema}/bulk    Bulk create or update
```

## Storage Backends

| Backend | Description | Status |
|---------|-------------|--------|
| PostgreSQL | Primary supported backend with full-text search via pg_trgm | Supported |
| MySQL / MariaDB | Supported via `mariadb-ci-matrix` CI testing | Supported |
| External SQL | Via `Source` configuration (external database connection) | Supported |
| MongoDB | Document store adapter | Planned |

## API

```
GET    /api/objects/{register}/{schema}           List objects (with filtering, search, pagination)
POST   /api/objects/{register}/{schema}           Create a new object
GET    /api/objects/{register}/{schema}/{id}      Get an object by UUID or slug
PUT    /api/objects/{register}/{schema}/{id}      Full update (replace)
PATCH  /api/objects/{register}/{schema}/{id}      Partial update (merge)
DELETE /api/objects/{register}/{schema}/{id}      Soft delete
POST   /api/objects/{register}/{schema}/{id}/restore  Restore from soft delete
POST   /api/objects/{register}/{schema}/{id}/lock     Acquire lock
DELETE /api/objects/{register}/{schema}/{id}/lock     Release lock
GET    /api/objects/{register}/{schema}/{id}/audit    Object audit trail
POST   /api/objects/{register}/{schema}/bulk          Bulk create/update
```

## Related Features

- [Registers & Schemas](registers-and-schemas.md) — schema validation and configuration
- [Search, Filtering & Faceting](search-and-faceting.md) — querying objects
- [Content Versioning & Audit Trail](versioning-and-audit.md) — version history and compliance
- [Access Control (RBAC)](access-control.md) — who can read, create, update, delete
- [Object Interactions](object-interactions.md) — notes, tasks, tags on objects
- [Archiving & Records Management](archiving.md) — retention and destruction lifecycle
- [Event-Driven Architecture](event-driven-architecture.md) — object lifecycle events
