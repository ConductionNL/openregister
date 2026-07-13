# AI & MCP Integration

## Overview

OpenRegister provides two complementary interfaces for AI systems and LLMs: a tiered REST-based discovery API for token-efficient API exploration, and a full MCP (Model Context Protocol) standard protocol endpoint implementing JSON-RPC 2.0 over Streamable HTTP. Together these interfaces allow any LLM or MCP client to discover capabilities, establish sessions, and perform CRUD operations on registers, schemas, and objects without prior knowledge of the API surface.

## MCP Standard Protocol

### Endpoint

```
POST /api/mcp/v1/messages    JSON-RPC 2.0 over Streamable HTTP (MCP standard)
GET  /api/mcp/v1/sse         Server-Sent Events stream for MCP session
```

The MCP endpoint implements the full Model Context Protocol specification:

- JSON-RPC 2.0 message framing
- Streamable HTTP transport (POST for requests, SSE for responses)
- Tool discovery and invocation
- Resource listing and reading
- Session management with `initialize` handshake

### Tools Exposed via MCP

MCP tools map to OpenRegister operations. AI clients can discover and call them programmatically:

| Tool | Description |
|------|-------------|
| `listRegisters` | List all registers with metadata |
| `getRegister` | Get a specific register |
| `listSchemas` | List schemas in a register |
| `getSchema` | Get a schema definition |
| `listObjects` | Search/filter objects in a schema |
| `getObject` | Get a single object by UUID |
| `createObject` | Create a new object |
| `updateObject` | Update an existing object |
| `deleteObject` | Delete an object |
| `searchObjects` | Full-text search across registers |
| `getAuditTrail` | Get audit history for an object |

### Resources Exposed via MCP

MCP resources provide structured data for context injection into LLM prompts:

| Resource URI | Description |
|-------------|-------------|
| `openregister://registers` | List of all registers |
| `openregister://registers/{slug}/schemas` | Schemas in a register |
| `openregister://registers/{slug}/schemas/{slug}/objects` | Objects in a schema |
| `openregister://objects/{uuid}` | A specific object |
| `openregister://schemas/{slug}` | A schema definition |

## Tiered Discovery API

The tiered discovery API enables token-efficient API exploration — AI agents can understand the full API surface with minimal context consumption.

### Tier 1: Capability Catalog

```
GET /api/mcp/v1/discover
```

Returns a compact JSON catalog of all capability areas without authentication:

```json
{
  "version": "1.0",
  "name": "OpenRegister",
  "description": "Structured data registration platform for Nextcloud",
  "base_url": "/index.php/apps/openregister",
  "capabilities": [
    {
      "id": "registers",
      "name": "Registers",
      "description": "Manage data registers and their schemas",
      "href": "/api/mcp/v1/discover/registers"
    },
    {
      "id": "objects",
      "name": "Objects",
      "description": "CRUD operations on register objects",
      "href": "/api/mcp/v1/discover/objects"
    }
  ]
}
```

A single request gives the AI agent a complete map of what OpenRegister can do — without exposing the full OpenAPI spec (which would consume many tokens).

### Tier 2: Capability Detail

```
GET /api/mcp/v1/discover/{capability}
```

Returns detailed documentation for a specific capability area: endpoints, parameters, example requests and responses. An agent drills into only the capability it needs for the current task.

### Tier 3: Live Data

```
GET /api/mcp/v1/discover/{capability}/data
```

Returns live data samples from the capability (e.g., the first 5 objects from a schema). Enables few-shot context injection.

## Schema-Declared MCP Tools (`x-openregister-mcp` dialect)

Per ADR-063 (MCP as Platform Abstraction) and ADR-031 (schema-declarative
business logic), a schema can declare which coarse CRUD MCP tools it exposes
via a top-level `x-openregister-mcp` annotation in
`lib/Settings/{app}_register.json`, a member of the `x-openregister-*`
dialect family alongside `x-openregister-lifecycle`,
`x-openregister-calculations`, `x-openregister-notifications`, etc. This is
the **declaration** step of a three-change arc — this change (validation +
storage only) is followed by a derived tool provider that emits the actual
MCP tools, then a `#[McpTool]` service attribute for non-CRUD behaviour. All
three chain changes have since shipped: schema-derived tools go live the
moment `enabled: true` is saved, and `#[McpTool]` now accepts the same
`readOnlyHint`/`destructiveHint`/`idempotentHint`/`scope` vocabulary shown
below, forwarded to both serving surfaces when a service author sets them
(`or-mcp-attribute-hints`).

### Shape

```jsonc
"x-openregister-mcp": {
  "enabled": true,                       // REQUIRED. Default OFF: absent or false = no tools.
  "tools": {                             // OPTIONAL. Absent => all five verbs with defaults.
    "search": {
      "description": "Search cases by status, assignee and free text.",
      "filters": ["status", "assignee", "createdAt"],   // search only; each MUST be a schema property
      "scope": "read",
      "readOnlyHint": true,
      "destructiveHint": false,
      "idempotentHint": true
    },
    "get":    { "description": "...", "scope": "read",   "readOnlyHint": true },
    "create": { "description": "...", "scope": "create", "destructiveHint": false, "idempotentHint": false },
    "update": { "description": "...", "scope": "update", "destructiveHint": false, "idempotentHint": true },
    "delete": { "description": "...", "scope": "delete", "destructiveHint": true,  "idempotentHint": true }
  }
}
```

- **`enabled`** (boolean, required when the block is present) is the opt-in
  gate — the dialect is **default OFF** fleet-wide. A schema with no block,
  or `enabled:false`, exposes no MCP tools.
- **`tools`** (object, optional) keys are restricted to the closed verb set
  `{search, get, create, update, delete}` — this is a **coarse CRUD
  template**, not a mechanism for declaring arbitrary per-REST-endpoint
  tools. Naive OpenAPI→MCP (one tool per endpoint) measurably degrades LLM
  tool-selection accuracy and burns tokens on large surfaces; the dialect
  reuses the schema itself as the tool's input/output schema instead.
  Non-CRUD, behaviour-specific tools belong to the `#[McpTool]` service
  attribute, not this dialect.
- Per-verb `description` / `scope` (`read|create|update|delete`) / the MCP
  2025-11-25 annotation hints `readOnlyHint` / `destructiveHint` /
  `idempotentHint` are validated for **type and shape only**. `search` also
  accepts `filters` — property names the search tool accepts as query
  filters; every entry must name a real property on the schema.

### Untrusted hints vs. authoritative RBAC

The `readOnlyHint` / `destructiveHint` / `idempotentHint` values are
**untrusted UX hints** passed through to the tool descriptor for client-side
display — they are never treated as a security decision. The authoritative
gate at invoke time is always OpenRegister RBAC via `ObjectService`,
identical to every other access path (UI, REST, GraphQL). A schema author
declaring `destructiveHint: false` on `delete` does not weaken RBAC in any
way; it only affects how an MCP client chooses to surface the tool to a
human-in-the-loop.

### Validation

A malformed `x-openregister-mcp` block fails the schema save loudly — the
same failure mode as every sibling `x-openregister-*` dialect — via
`McpAnnotationValidator` (`lib/Service/Mcp/McpAnnotationValidator.php`),
invoked from `SchemaMapper::cleanObject()`. Unknown verbs, unknown `filters`
property references, `filters` on a non-`search` verb, invalid `scope`
values, and non-boolean hints are all rejected with a schema-identifying
error rather than silently mis-exposing data.

## Agent Use Cases

OpenRegister is designed to serve as a data backend for AI-driven applications:

### Data Enrichment Agent

An AI agent processes incoming objects and enriches them with derived data:

1. Subscribe to `object.created` events via SSE
2. For each new object, call `getObject` to retrieve full data
3. Call an LLM to derive summary, tags, or classification
4. Call `updateObject` to write enriched fields back

### Query Agent

A conversational AI agent answers questions about register data:

1. Call `listRegisters` and `listSchemas` to build context
2. Translate the user's natural language question to filter parameters
3. Call `searchObjects` with those parameters
4. Format and return results in natural language

### Classification Agent

An AI agent classifies incoming documents against selectielijsten:

1. Receive `object.creating` hook payload
2. Extract document content via text extraction
3. Call LLM with selectielijst categories
4. Return the suggested `classificatie` and `archiefnominatie` to the hook response

## Authentication for AI Clients

MCP clients authenticate using standard Nextcloud authentication:

| Method | Use case |
|--------|---------|
| Bearer token (API key) | Service-to-service; non-interactive agents |
| OAuth2 | Interactive AI applications acting on behalf of a user |
| Consumer entity | Maps an external AI identity to a Nextcloud user for RBAC inheritance |

All RBAC rules (schema, row, property level) apply to AI clients identically to human users — an agent can only see data the mapped user is authorized to access.

## Related AI Features

The `docs/features/` directory contains additional documentation for specific AI capabilities:

- [agents.md](agents.md) — OpenRegister Agent entities for persistent AI agent configuration
- [rag-implementation.md](rag-implementation.md) — Retrieval-Augmented Generation using register objects as a vector store
- [text-extraction-enhanced.md](text-extraction-enhanced.md) — Text extraction from attached files for AI processing

## Standards

| Standard | Role |
|----------|------|
| MCP (Model Context Protocol) | AI agent tool and resource protocol |
| JSON-RPC 2.0 | MCP message framing |
| Server-Sent Events (SSE) | MCP response streaming and subscriptions |
| OpenAPI 3.1.0 | Machine-readable API surface for AI clients |

## Related Features

- [OpenAPI & GraphQL APIs](api-generation.md) — generated OpenAPI spec used by AI clients for discovery
- [Real-Time Updates](realtime-updates.md) — SSE for agent event subscriptions
- [Event-Driven Architecture](event-driven-architecture.md) — hooks that trigger AI agent pipelines
- [Workflow Automation](workflow-automation.md) — n8n workflows that orchestrate AI agents
- [Access Control (RBAC)](access-control.md) — AI agents respect the same RBAC as human users
