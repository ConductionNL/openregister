# MCP Standard Protocol

OpenRegister implements the [Model Context Protocol (MCP)](https://modelcontextprotocol.io/) standard, enabling AI agents (Claude Code, Cursor, etc.) to interact with registers, schemas, and objects natively.

## Quick Start

### Endpoint

```
POST /index.php/apps/openregister/api/mcp
Content-Type: application/json
```

Requires Nextcloud Basic Auth. All requests use JSON-RPC 2.0 over HTTP.

### 1. Initialize a Session

```bash
curl -s -u admin:admin -X POST \
  http://localhost:8080/index.php/apps/openregister/api/mcp \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "initialize",
    "params": {
      "protocolVersion": "2025-03-26",
      "capabilities": {},
      "clientInfo": {"name": "test", "version": "1.0"}
    }
  }'
```

Response includes a `Mcp-Session-Id` header. Use it for all subsequent requests.

### 2. List Tools

```bash
curl -s -u admin:admin -X POST \
  http://localhost:8080/index.php/apps/openregister/api/mcp \
  -H "Content-Type: application/json" \
  -H "Mcp-Session-Id: <session-id>" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}'
```

### 3. Call a Tool

```bash
curl -s -u admin:admin -X POST \
  http://localhost:8080/index.php/apps/openregister/api/mcp \
  -H "Content-Type: application/json" \
  -H "Mcp-Session-Id: <session-id>" \
  -d '{
    "jsonrpc": "2.0",
    "id": 3,
    "method": "tools/call",
    "params": {
      "name": "registers",
      "arguments": {"action": "list"}
    }
  }'
```

## MCP Standard vs Discovery API

OpenRegister has **two** MCP-related endpoints that serve different purposes:

| Feature | MCP Standard (`/api/mcp`) | Discovery API (`/api/mcp/v1/discover`) |
|---------|---------------------------|----------------------------------------|
| Protocol | JSON-RPC 2.0 (MCP spec) | REST (custom) |
| Purpose | Native MCP client integration | LLM-friendly API discovery |
| Auth | Nextcloud Basic + MCP session | Tier 1 public, Tier 2 authenticated |
| Transport | Streamable HTTP (POST) | HTTP GET |
| Tools | registers, schemas, objects | — |
| Resources | openregister:// URIs | — |
| Use case | Claude Code, Cursor, MCP clients | Any LLM needing API docs |

**When to use which:**
- Use the **MCP Standard** endpoint when your client supports MCP (Claude Code, Cursor, etc.)
- Use the **Discovery API** when you need a lightweight, token-efficient way to explain the API to an LLM

## Available Tools

### `registers`
Manage registers (data containers that group schemas and objects).

| Action | Required Params | Description |
|--------|----------------|-------------|
| `list` | — | List all registers (supports `limit`, `offset`) |
| `get` | `id` | Get a single register |
| `create` | `data` | Create a new register |
| `update` | `id`, `data` | Update a register |
| `delete` | `id` | Delete a register |

### `schemas`
Manage schemas (data definitions that describe object structure).

| Action | Required Params | Description |
|--------|----------------|-------------|
| `list` | — | List all schemas (supports `limit`, `offset`) |
| `get` | `id` | Get a single schema |
| `create` | `data` | Create a new schema |
| `update` | `id`, `data` | Update a schema |
| `delete` | `id` | Delete a schema |

### `objects`
Manage objects (data records stored in a register under a schema).

All actions require `register` (int) and `schema` (int) parameters.

| Action | Required Params | Description |
|--------|----------------|-------------|
| `list` | `register`, `schema` | List objects (supports `limit`, `offset`) |
| `get` | `register`, `schema`, `id` (UUID) | Get a single object |
| `create` | `register`, `schema`, `data` | Create a new object |
| `update` | `register`, `schema`, `id`, `data` | Update an object |
| `delete` | `register`, `schema`, `id` | Delete an object |

## Available Resources

### Static Resources
- `openregister://registers` — All registers
- `openregister://schemas` — All schemas
- `openregister://objects/{registerId}/{schemaId}` — Objects for a register+schema pair

### URI Templates
- `openregister://registers/{id}` — Single register
- `openregister://schemas/{id}` — Single schema
- `openregister://objects/{register}/{schema}/{id}` — Single object

## Session Management

- Sessions are created via `initialize` and returned in the `Mcp-Session-Id` response header
- Sessions expire after 1 hour of inactivity
- All methods except `initialize` require a valid `Mcp-Session-Id` header
- Notifications (requests without `id`) return HTTP 202

## JSON-RPC Error Codes

| Code | Meaning |
|------|---------|
| -32700 | Parse error (invalid JSON) |
| -32600 | Invalid JSON-RPC request |
| -32601 | Method not found |
| -32602 | Invalid parameters |
| -32603 | Internal error |
| -32000 | Session required or invalid |

## Architecture

```
POST /api/mcp
     │
     ▼
McpServerController::handle()
  ├── Parse JSON-RPC 2.0 envelope
  ├── Route by method:
  │   ├── "initialize"              → McpProtocolService
  │   ├── "notifications/*"         → HTTP 202
  │   ├── "ping"                    → McpProtocolService
  │   ├── "tools/list"              → McpToolsService
  │   ├── "tools/call"              → McpToolsService
  │   ├── "resources/list"          → McpResourcesService
  │   ├── "resources/read"          → McpResourcesService
  │   └── "resources/templates/list"→ McpResourcesService
  └── Wrap in JSON-RPC response
```
