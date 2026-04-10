# Design: mcp-discovery-endpoint

## Architecture Overview

A lightweight, read-only discovery layer on top of OpenRegister's existing API. No new database tables, no new entities — just a controller + service that inspects existing routes, registers, and schemas to build structured JSON responses.

```
Agent                    OpenRegister
  │                         │
  ├─ GET /api/mcp/v1/discover ──────► McpController::discover()
  │   (public, no auth)              │
  │   ◄── compact catalog ──────────┘
  │                                  │
  ├─ GET /api/mcp/v1/discover/objects ► McpController::discoverCapability('objects')
  │   (authenticated)                │
  │   ◄── endpoints + live data ────┘
  │                                  │
  ├─ PUT /api/objects/1/2/3 ────────► ObjectsController::update()
  │   (existing API, unchanged)      │
```

## API Design

### `GET /api/mcp/v1/discover`

Public endpoint. Returns a compact catalog of capability areas.

**Response (200):**
```json
{
  "version": "1.0",
  "name": "OpenRegister",
  "description": "A flexible data register platform for Nextcloud. Manages structured objects across registers and schemas with full CRUD, search, audit trails, file management, and AI capabilities.",
  "authentication": {
    "type": "basic",
    "description": "Use Nextcloud username:password via HTTP Basic Auth or session cookies.",
    "header": "Authorization: Basic base64(user:pass)"
  },
  "base_url": "/index.php/apps/openregister",
  "capabilities": [
    {
      "id": "registers",
      "name": "Registers",
      "description": "Data containers that group schemas and their objects. CRUD, export, import, publish.",
      "href": "/index.php/apps/openregister/api/mcp/v1/discover/registers"
    },
    {
      "id": "schemas",
      "name": "Schemas",
      "description": "JSON Schema definitions that define object structure. CRUD, upload, download, publish.",
      "href": "/index.php/apps/openregister/api/mcp/v1/discover/schemas"
    },
    {
      "id": "objects",
      "name": "Objects",
      "description": "Data records stored in register/schema pairs. Full CRUD, filtering, pagination, lock/unlock, publish.",
      "href": "/index.php/apps/openregister/api/mcp/v1/discover/objects"
    },
    {
      "id": "search",
      "name": "Search",
      "description": "Full-text, semantic, and hybrid search across objects and files.",
      "href": "/index.php/apps/openregister/api/mcp/v1/discover/search"
    },
    {
      "id": "files",
      "name": "Files",
      "description": "File attachments on objects. Upload, download, text extraction, anonymization.",
      "href": "/index.php/apps/openregister/api/mcp/v1/discover/files"
    },
    {
      "id": "audit",
      "name": "Audit Trails",
      "description": "Change history for objects. View, export, and manage audit records.",
      "href": "/index.php/apps/openregister/api/mcp/v1/discover/audit"
    },
    {
      "id": "bulk",
      "name": "Bulk Operations",
      "description": "Batch save, delete, publish/depublish objects across a register/schema.",
      "href": "/index.php/apps/openregister/api/mcp/v1/discover/bulk"
    },
    {
      "id": "webhooks",
      "name": "Webhooks",
      "description": "Event-driven HTTP callbacks. CRUD, test, view logs.",
      "href": "/index.php/apps/openregister/api/mcp/v1/discover/webhooks"
    },
    {
      "id": "chat",
      "name": "AI Chat",
      "description": "Conversational AI assistant for querying and managing register data.",
      "href": "/index.php/apps/openregister/api/mcp/v1/discover/chat"
    },
    {
      "id": "views",
      "name": "Views",
      "description": "Saved search/filter configurations for reusable data views.",
      "href": "/index.php/apps/openregister/api/mcp/v1/discover/views"
    }
  ]
}
```

### `GET /api/mcp/v1/discover/{capability}`

Authenticated endpoint. Returns detailed API docs + live data for one capability.

**Example: `GET /api/mcp/v1/discover/objects`**

**Response (200):**
```json
{
  "id": "objects",
  "name": "Objects",
  "description": "Data records stored in register/schema pairs.",
  "context": {
    "registers": [
      { "id": 1, "title": "Zaken", "schemas": [
        { "id": 1, "title": "Zaak", "object_count": 42 },
        { "id": 2, "title": "Document", "object_count": 15 }
      ]}
    ]
  },
  "endpoints": [
    {
      "method": "GET",
      "path": "/api/objects/{register}/{schema}",
      "description": "List objects in a register/schema pair. Supports filtering and pagination.",
      "parameters": [
        { "name": "register", "in": "path", "type": "integer", "required": true, "description": "Register ID" },
        { "name": "schema", "in": "path", "type": "integer", "required": true, "description": "Schema ID" },
        { "name": "_limit", "in": "query", "type": "integer", "required": false, "description": "Max results (default 30)" },
        { "name": "_offset", "in": "query", "type": "integer", "required": false, "description": "Skip N results" },
        { "name": "_search", "in": "query", "type": "string", "required": false, "description": "Full-text search" },
        { "name": "_order[field]", "in": "query", "type": "string", "required": false, "description": "Sort by field (asc/desc)" },
        { "name": "field.subfield", "in": "query", "type": "string", "required": false, "description": "Dot-notation filter on object properties" }
      ],
      "example": {
        "request": "GET /api/objects/1/1?_limit=5&_search=test",
        "note": "Returns first 5 objects matching 'test' in register 1, schema 1"
      }
    },
    {
      "method": "POST",
      "path": "/api/objects/{register}/{schema}",
      "description": "Create a new object.",
      "parameters": [
        { "name": "register", "in": "path", "type": "integer", "required": true },
        { "name": "schema", "in": "path", "type": "integer", "required": true }
      ],
      "body": "JSON object matching the schema definition. Check GET /api/schemas/{id} for the schema.",
      "example": {
        "request": "POST /api/objects/1/1",
        "body": { "title": "New record", "status": "draft" }
      }
    },
    {
      "method": "GET",
      "path": "/api/objects/{register}/{schema}/{id}",
      "description": "Get a single object by ID."
    },
    {
      "method": "PUT",
      "path": "/api/objects/{register}/{schema}/{id}",
      "description": "Full update of an object."
    },
    {
      "method": "PATCH",
      "path": "/api/objects/{register}/{schema}/{id}",
      "description": "Partial update of an object."
    },
    {
      "method": "DELETE",
      "path": "/api/objects/{register}/{schema}/{id}",
      "description": "Soft-delete an object (can be restored from /api/deleted)."
    }
  ]
}
```

**Error (404):**
```json
{ "error": "Unknown capability: foo", "available": ["registers", "schemas", "objects", "search", "files", "audit", "bulk", "webhooks", "chat", "views"] }
```

## Database Changes
None. This is purely read-only — it inspects existing data via existing mappers.

## Nextcloud Integration

- **Controller:** `McpController` extending `OCP\AppFramework\Controller`
  - `discover()` — `@PublicPage @CORS @NoCSRFRequired`
  - `discoverCapability($capability)` — `@CORS @NoCSRFRequired` (requires auth)
- **Service:** `McpDiscoveryService`
  - Injected with `RegisterMapper`, `SchemaMapper` to fetch live data
  - Contains capability definitions as structured arrays (not hardcoded strings)
  - Each capability has a builder method: `buildObjectsCapability()`, `buildRegistersCapability()`, etc.
- **No Mappers/Entities** — uses existing ones
- **No Events/Hooks** — read-only

## File Structure
```
lib/
  Controller/
    McpController.php          # New — 2 actions
  Service/
    McpDiscoveryService.php    # New — capability catalog + live data builders
```

## Security Considerations
- Tier 1 (discover): `@PublicPage` — no auth, no sensitive data exposed (just endpoint names/descriptions)
- Tier 2 (discoverCapability): requires Nextcloud auth — exposes register/schema names, object counts
- Both use `@CORS` + `@NoCSRFRequired` for cross-origin agent access
- No write operations — purely read-only, zero mutation risk
- Rate limiting: inherits OpenRegister's existing APCu-based rate limiter

## NL Design System
Not applicable — this is a JSON API with no UI components.

## Trade-offs

| Alternative | Why not |
|---|---|
| Serve full OpenAPI spec in one response | Too many tokens (~5000+). Defeats the purpose of token-efficient discovery. |
| Generate from existing OAS endpoint | OAS is register-scoped (`/api/registers/{id}/oas`), not capability-scoped. Would need restructuring. |
| Full MCP JSON-RPC server | Overkill for phase 1. REST is simpler to test and debug. Can add MCP transport later. |
| Static JSON file | Can't include live data (register names, object counts). Dynamic service is needed. |
