---
status: implemented
---

# MCP Discovery

## Purpose
Provides AI agents and MCP-compatible clients with two complementary interfaces to the OpenRegister platform: a tiered REST-based discovery API for token-efficient API exploration, and a full MCP standard protocol endpoint implementing JSON-RPC 2.0 over Streamable HTTP for native tool and resource access. Together these interfaces allow any LLM or MCP client to discover capabilities, establish sessions, and perform CRUD operations on registers, schemas, and objects without prior knowledge of the API surface.

## Requirements

### Requirement: Tier 1 Discovery Catalog
The system SHALL expose a public endpoint at `GET /api/mcp/v1/discover` that returns a compact JSON catalog of all capability areas without requiring authentication, enabling AI agents to understand the full API surface in a single request.

#### Scenario: Agent discovers available capabilities
- **GIVEN** the MCP discovery endpoint is deployed
- **WHEN** an unauthenticated client sends `GET /api/mcp/v1/discover`
- **THEN** the response MUST be HTTP 200 with Content-Type `application/json`
- **AND** the response MUST include a `version` field with value `"1.0"`
- **AND** the response MUST include a `name` field with value `"OpenRegister"`
- **AND** the response MUST include a `description` field summarizing the platform
- **AND** the response MUST include a `base_url` field with the app's base path generated via `IURLGenerator`
- **AND** the response MUST include a `capabilities` array with at least 10 entries

#### Scenario: Capability entry structure
- **GIVEN** the discovery endpoint returns a capabilities array
- **WHEN** an agent reads a capability entry
- **THEN** each entry MUST contain `id` (kebab-case string), `name` (human-readable label), `description` (one concise sentence), and `href` (absolute URL to Tier 2 detail built from route `openregister.mcp.discoverCapability`)

#### Scenario: Authentication object in discovery response
- **GIVEN** the discovery endpoint is called
- **WHEN** the response is returned
- **THEN** the response MUST include an `authentication` object with `type` set to `"basic"`, a `description` explaining Nextcloud Basic Auth and session cookies, and a `header` field showing the expected `Authorization` header format

#### Scenario: CORS preflight for public discovery
- **GIVEN** the discovery endpoint is annotated with `@PublicPage` and `@CORS`
- **WHEN** a browser or agent sends an OPTIONS preflight request to `/api/mcp/v1/discover`
- **THEN** the response MUST include CORS headers allowing cross-origin access
- **AND** the GET request MUST NOT require CSRF tokens (annotated `@NoCSRFRequired`)

#### Scenario: Internal server error handling
- **GIVEN** the `McpDiscoveryService::getCatalog()` method throws an exception
- **WHEN** the `McpController::discover()` method catches the exception
- **THEN** the response MUST be HTTP 500 with an `error` field containing the exception message

### Requirement: Tier 2 Capability Detail with Live Data
The system SHALL expose an authenticated endpoint at `GET /api/mcp/v1/discover/{capability}` that returns detailed API documentation including endpoint definitions, parameter schemas, and live context data (real entity IDs and names) so that agents can immediately reference existing data without additional lookup calls.

#### Scenario: Agent drills into the objects capability
- **GIVEN** an authenticated client
- **WHEN** the client sends `GET /api/mcp/v1/discover/objects`
- **THEN** the response MUST be HTTP 200
- **AND** the response MUST include `id`, `name`, and `description` fields
- **AND** the response MUST include an `endpoints` array with method, path, description, and parameters for each endpoint (list, create, get, update, patch, delete, lock, unlock)
- **AND** the response MUST include a `context` object with a `registers` array where each register includes `id`, `title`, and a `schemas` sub-array with `id` and `title` for each associated schema

#### Scenario: Schema capability includes property counts
- **GIVEN** an authenticated client requests `GET /api/mcp/v1/discover/schemas`
- **WHEN** the response is returned
- **THEN** the `context` object MUST include a `schemas` array with `id`, `title`, `uuid`, and `property_count` for each schema
- **AND** `property_count` MUST reflect the actual number of properties defined on the schema

#### Scenario: Unknown capability returns 404 with available list
- **GIVEN** an authenticated client
- **WHEN** the client sends `GET /api/mcp/v1/discover/nonexistent`
- **THEN** the response MUST be HTTP 404
- **AND** the response MUST include an `error` message containing the unknown capability name
- **AND** the response MUST include an `available` array listing all valid capability IDs from `McpDiscoveryService::getCapabilityIds()`

#### Scenario: Unauthenticated access to Tier 2 is rejected
- **GIVEN** an unauthenticated client (no Basic Auth or session)
- **WHEN** the client sends `GET /api/mcp/v1/discover/objects`
- **THEN** the Nextcloud framework MUST return HTTP 401 since the `discoverCapability` action is NOT annotated with `@PublicPage`

#### Scenario: Objects endpoint parameters are fully documented
- **GIVEN** the objects capability detail is returned
- **WHEN** the agent reads the list objects endpoint
- **THEN** the `parameters` array MUST include entries for `register` (path, integer, required), `schema` (path, integer, required), `_limit` (query, integer, optional), `_offset` (query, integer, optional), `_search` (query, string, optional), `_order[field]` (query, string, optional), and `field.subfield` dot-notation filters (query, string, optional)

### Requirement: Capability Coverage
The discovery catalog MUST cover at minimum these capability areas: registers, schemas, objects, search, files, audit, bulk, webhooks, chat, views. Each capability MUST have a corresponding builder method in `McpDiscoveryService` that returns endpoints and context.

#### Scenario: All core capabilities present
- **GIVEN** the discovery endpoint is called
- **WHEN** the capabilities array is returned
- **THEN** it MUST contain entries with IDs: `registers`, `schemas`, `objects`, `search`, `files`, `audit`, `bulk`, `webhooks`, `chat`, `views`

#### Scenario: Each capability has a builder method
- **GIVEN** the `McpDiscoveryService` class is inspected
- **WHEN** `getCapabilityDetail()` dispatches via the `$builders` map
- **THEN** each capability ID MUST map to a private `build{Name}Capability()` method that returns an array with `id`, `name`, `description`, `context`, and `endpoints` keys

#### Scenario: Search capability covers all search modes
- **GIVEN** the search capability detail is returned
- **WHEN** the agent reads the endpoints array
- **THEN** it MUST include endpoints for keyword search (`GET /api/search`), semantic search (`POST /api/search/semantic`), hybrid search (`POST /api/search/hybrid`), and file search variants (keyword, semantic, hybrid)

### Requirement: Token Efficiency
The Tier 1 response MUST be optimized for minimal token consumption by AI agents. Descriptions MUST be concise (one sentence each) and the total response SHOULD be under 500 tokens when serialized.

#### Scenario: Compact response size
- **GIVEN** the discovery endpoint is called
- **WHEN** the response is serialized to JSON
- **THEN** the total character count MUST be under 3000 characters (approximately 500 tokens)

#### Scenario: Descriptions are single sentences
- **GIVEN** the capabilities array is returned
- **WHEN** the agent reads any capability description
- **THEN** the description MUST be a single sentence (no period-separated sentences)

#### Scenario: No redundant data in Tier 1
- **GIVEN** the Tier 1 catalog response
- **WHEN** it is compared to the Tier 2 detail responses
- **THEN** Tier 1 MUST NOT include endpoint arrays, parameter schemas, or context data -- those belong exclusively in Tier 2

### Requirement: MCP Standard Protocol Endpoint (JSON-RPC 2.0)
The system SHALL expose a single `POST /api/mcp` endpoint implementing the MCP standard protocol via JSON-RPC 2.0 over Streamable HTTP transport. The `McpServerController` MUST parse the JSON-RPC envelope, validate the `jsonrpc` version field equals `"2.0"`, and dispatch to the appropriate service based on the `method` field.

#### Scenario: Valid JSON-RPC request is processed
- **GIVEN** an authenticated client with a valid MCP session
- **WHEN** the client sends `POST /api/mcp` with body `{"jsonrpc":"2.0","id":1,"method":"tools/list"}`
- **THEN** the response MUST be HTTP 200 with a JSON-RPC success envelope containing `jsonrpc`, `id`, and `result` fields

#### Scenario: Invalid JSON body returns parse error
- **GIVEN** any client
- **WHEN** the client sends `POST /api/mcp` with a body that is not valid JSON
- **THEN** the response MUST be a JSON-RPC error with code `-32700` and message `"Parse error: invalid JSON"`

#### Scenario: Missing jsonrpc version returns invalid request error
- **GIVEN** any client
- **WHEN** the client sends a JSON body without `jsonrpc: "2.0"` or without a `method` field
- **THEN** the response MUST be a JSON-RPC error with code `-32600` and message `"Invalid JSON-RPC 2.0 request"`

#### Scenario: Unknown method returns method not found error
- **GIVEN** an authenticated client with a valid session
- **WHEN** the client sends a request with method `"unknown/method"`
- **THEN** the response MUST be a JSON-RPC error with code `-32601` and message containing `"Method not found"`

#### Scenario: Missing required parameters returns invalid params error
- **GIVEN** an authenticated client with a valid session
- **WHEN** the client calls `tools/call` without the required `name` parameter
- **THEN** the response MUST be a JSON-RPC error with code `-32602` and message `"Missing required parameter: name"`

### Requirement: MCP Session Management
The system SHALL implement session-based access control for the MCP standard protocol. Sessions MUST be created during `initialize`, stored in Nextcloud's distributed cache (APCu) via `ICacheFactory`, and validated on every subsequent request via the `Mcp-Session-Id` HTTP header.

#### Scenario: Initialize creates a session
- **GIVEN** an authenticated Nextcloud user
- **WHEN** the client sends an `initialize` request
- **THEN** the response MUST include a `Mcp-Session-Id` HTTP header containing a 32-character alphanumeric session ID generated via `ISecureRandom`
- **AND** the response result MUST include `protocolVersion` (value `"2025-03-26"`), `capabilities` object, `serverInfo` with `name` (`"OpenRegister"`) and `version` (`"1.0.0"`), and `instructions` text
- **AND** the session MUST be stored in the `openregister_mcp_sessions` cache with a TTL of 3600 seconds (1 hour)

#### Scenario: Request without session is rejected
- **GIVEN** an authenticated client that has NOT called `initialize`
- **WHEN** the client sends a `tools/list` request without the `Mcp-Session-Id` header
- **THEN** the response MUST be a JSON-RPC error with code `-32000` and message `"Mcp-Session-Id header required"`

#### Scenario: Expired or invalid session is rejected
- **GIVEN** a client with an expired or forged session ID
- **WHEN** the client sends any non-initialize request with that session ID
- **THEN** `McpProtocolService::validateSession()` MUST return `null`
- **AND** the response MUST be a JSON-RPC error with code `-32000` and message `"Invalid or expired session"`

#### Scenario: Session is scoped to authenticated user
- **GIVEN** a session is created for user `alice`
- **WHEN** `McpProtocolService::validateSession()` is called with that session ID
- **THEN** it MUST return the string `"alice"` (the user ID stored in cache)

#### Scenario: Ping keeps session alive
- **GIVEN** a client with a valid session
- **WHEN** the client sends `{"jsonrpc":"2.0","id":5,"method":"ping"}`
- **THEN** the response result MUST be an empty object `{}`

### Requirement: MCP Tool Definitions
The MCP server SHALL expose three tools -- `registers`, `schemas`, and `objects` -- via the `tools/list` method. Each tool MUST include a `name`, `description`, and `inputSchema` (JSON Schema format) defining all accepted parameters including `action` (enum of CRUD operations), entity-specific fields, and pagination parameters.

#### Scenario: Tools list returns three tools
- **GIVEN** a client with a valid session
- **WHEN** the client calls `tools/list`
- **THEN** the result MUST contain a `tools` array with exactly 3 entries named `"registers"`, `"schemas"`, and `"objects"`

#### Scenario: Registers tool schema defines all parameters
- **GIVEN** the registers tool definition
- **WHEN** the `inputSchema` is inspected
- **THEN** it MUST define `action` (string, enum: list/get/create/update/delete, required), `id` (integer), `data` (object), `limit` (integer), and `offset` (integer)
- **AND** `required` MUST be `["action"]`

#### Scenario: Objects tool requires register and schema scoping
- **GIVEN** the objects tool definition
- **WHEN** the `inputSchema` is inspected
- **THEN** `required` MUST be `["action", "register", "schema"]`
- **AND** `register` and `schema` MUST be typed as `integer`
- **AND** `id` MUST be typed as `string` (UUID format for object identifiers)

#### Scenario: Tool call executes CRUD action
- **GIVEN** a client calls `tools/call` with `name: "registers"` and `arguments: {"action": "list"}`
- **WHEN** `McpToolsService::callTool()` processes the request
- **THEN** the result MUST contain a `content` array with a single `text` entry containing JSON-serialized register data
- **AND** `isError` MUST be `false`

#### Scenario: Failed tool call returns error content
- **GIVEN** a client calls `tools/call` with `name: "registers"` and `arguments: {"action": "get"}` (missing required `id`)
- **WHEN** `McpToolsService::callTool()` catches the exception
- **THEN** the result MUST contain a `content` array with a `text` entry containing a JSON error object
- **AND** `isError` MUST be `true`

### Requirement: MCP Resource Definitions
The MCP server SHALL expose resources using the `openregister://` URI scheme. The `resources/list` method MUST return static resources for registers and schemas, plus dynamically generated resources for each register+schema pair. The `resources/templates/list` method MUST return URI templates for single-entity access.

#### Scenario: Resources list includes static and dynamic entries
- **GIVEN** a client with a valid session
- **WHEN** the client calls `resources/list`
- **THEN** the result MUST contain a `resources` array
- **AND** the array MUST include `openregister://registers` (name: "All Registers") and `openregister://schemas` (name: "All Schemas") as static entries
- **AND** for each register+schema pair in the database, there MUST be an entry with URI `openregister://objects/{registerId}/{schemaId}`, name formatted as `"{registerTitle} — {schemaTitle}"`, and mimeType `application/json`

#### Scenario: Deleted schema is skipped in resource listing
- **GIVEN** a register references a schema ID that no longer exists in the database
- **WHEN** `McpResourcesService::listResources()` iterates over schemas
- **THEN** the `DoesNotExistException` MUST be caught and the missing schema MUST be skipped without failing the entire listing

#### Scenario: URI templates define single-entity access patterns
- **GIVEN** a client calls `resources/templates/list`
- **WHEN** the result is returned
- **THEN** the `resourceTemplates` array MUST include templates for `openregister://registers/{id}`, `openregister://schemas/{id}`, and `openregister://objects/{register}/{schema}/{id}`

#### Scenario: Resource read parses URI and fetches data
- **GIVEN** a client calls `resources/read` with URI `openregister://objects/1/2`
- **WHEN** `McpResourcesService::readResource()` processes the request
- **THEN** it MUST parse the URI into `type: "objects"`, `registerId: 1`, `schemaId: 2`
- **AND** the response MUST contain a `contents` array with `uri`, `mimeType` (`application/json`), and `text` (JSON-serialized object data)

#### Scenario: Invalid URI scheme is rejected
- **GIVEN** a client calls `resources/read` with URI `http://example.com/foo`
- **WHEN** `McpResourcesService::parseUri()` checks the scheme
- **THEN** it MUST throw `InvalidArgumentException` with message `"Invalid URI scheme, expected openregister://"`

### Requirement: MCP Capabilities Negotiation
The MCP `initialize` response SHALL declare the server's capabilities so that clients know which MCP features are supported. The capabilities object MUST accurately reflect the current implementation state.

#### Scenario: Server declares tool and resource capabilities
- **GIVEN** a client sends an `initialize` request
- **WHEN** the response `result.capabilities` object is inspected
- **THEN** `tools.listChanged` MUST be `false` (tools are static, not dynamically changing)
- **AND** `resources.subscribe` MUST be `false` (resource subscriptions are not implemented)
- **AND** `resources.listChanged` MUST be `false` (resource list changes are not pushed)

#### Scenario: Server instructions guide the agent
- **GIVEN** the `initialize` response is returned
- **WHEN** the `result.instructions` field is read
- **THEN** it MUST contain a human-readable string explaining OpenRegister's purpose and how to use tools and resources

#### Scenario: Protocol version matches MCP spec
- **GIVEN** the `initialize` response is returned
- **WHEN** `result.protocolVersion` is checked
- **THEN** it MUST be `"2025-03-26"` matching the MCP specification version implemented

### Requirement: JSON-RPC Notification Handling
The system SHALL handle JSON-RPC notifications (requests without an `id` field) according to the MCP specification by returning HTTP 202 Accepted with no response body.

#### Scenario: Notification returns 202 Accepted
- **GIVEN** any client
- **WHEN** the client sends `POST /api/mcp` with body `{"jsonrpc":"2.0","method":"notifications/initialized"}` (no `id` field)
- **THEN** the response MUST be HTTP 202 Accepted

#### Scenario: Notification method is logged
- **GIVEN** a notification is received
- **WHEN** `McpServerController::handleNotification()` processes it
- **THEN** the method name MUST be logged at debug level via `LoggerInterface` with context `['method' => $method]`

#### Scenario: All MCP lifecycle notifications are accepted
- **GIVEN** any client
- **WHEN** notifications such as `notifications/initialized`, `notifications/cancelled`, or `notifications/progress` are sent
- **THEN** all MUST receive HTTP 202 regardless of the notification method name

### Requirement: MCP Authentication via Nextcloud
The MCP standard endpoint SHALL require Nextcloud authentication (Basic Auth or session cookies) enforced by the framework. The `McpServerController` is annotated with `@NoAdminRequired` and `@NoCSRFRequired` but NOT `@PublicPage`, ensuring only authenticated Nextcloud users can access it.

#### Scenario: Basic Auth grants access
- **GIVEN** a client sends `POST /api/mcp` with `Authorization: Basic base64(admin:admin)`
- **WHEN** Nextcloud validates the credentials
- **THEN** the request MUST be processed by `McpServerController::handle()`
- **AND** the `$userId` constructor parameter MUST be populated with the authenticated user ID

#### Scenario: Missing authentication is rejected by framework
- **GIVEN** a client sends `POST /api/mcp` with no authentication headers
- **WHEN** the Nextcloud middleware checks authentication
- **THEN** the request MUST be rejected with HTTP 401 before reaching the controller

#### Scenario: CORS is enabled for cross-origin MCP clients
- **GIVEN** the `handle()` method is annotated with `@CORS`
- **WHEN** a cross-origin MCP client (e.g., Claude Code running in a browser) sends a preflight OPTIONS request
- **THEN** the Nextcloud CORS middleware MUST return appropriate CORS headers

### Requirement: MCP Audit Logging
All MCP protocol operations SHALL be logged via `Psr\Log\LoggerInterface` for debugging and operational visibility. Tool calls, session lifecycle events, and errors MUST produce structured log entries.

#### Scenario: Tool calls are logged at debug level
- **GIVEN** a client calls `tools/call`
- **WHEN** `McpToolsService::callTool()` is invoked
- **THEN** a debug-level log entry MUST be written with message `"[MCP] Tool call"` and context containing `tool` name and `arguments`

#### Scenario: Failed tool calls are logged at error level
- **GIVEN** a tool execution throws an exception
- **WHEN** `McpToolsService::callTool()` catches the exception
- **THEN** an error-level log entry MUST be written with message `"[MCP] Tool execution failed"` and context containing `tool` name and `error` message

#### Scenario: Session creation is logged
- **GIVEN** a client calls `initialize`
- **WHEN** `McpProtocolService::createSession()` generates a session
- **THEN** a debug-level log entry MUST be written with message `"[MCP] Session created"` and context containing `sessionId` and `userId`

#### Scenario: Invalid session access is logged
- **GIVEN** a client sends a request with an invalid session ID
- **WHEN** `McpProtocolService::validateSession()` returns null
- **THEN** a debug-level log entry MUST be written with message `"[MCP] Invalid or expired session"` and context containing the `sessionId`

#### Scenario: Method dispatch failures are logged
- **GIVEN** the dispatch method encounters an unexpected exception
- **WHEN** `McpServerController::dispatch()` catches a generic `Exception`
- **THEN** an error-level log entry MUST be written with message `"[MCP] Method dispatch failed"` and context containing `method` and `error`

### Requirement: Versioned URL Paths
All MCP-related routes MUST use versioned URL prefixes to allow future protocol evolution without breaking existing integrations. The discovery API uses `/api/mcp/v1/` and the standard protocol uses `/api/mcp`.

#### Scenario: Discovery routes are under versioned prefix
- **GIVEN** the MCP discovery feature is deployed
- **WHEN** routes are registered in `appinfo/routes.php`
- **THEN** the Tier 1 route MUST be `GET /api/mcp/v1/discover`
- **AND** the Tier 2 route MUST be `GET /api/mcp/v1/discover/{capability}` with requirement `[a-z-]+`

#### Scenario: Standard protocol route is at base path
- **GIVEN** the MCP standard protocol is deployed
- **WHEN** routes are registered in `appinfo/routes.php`
- **THEN** the JSON-RPC endpoint MUST be `POST /api/mcp`
- **AND** it MUST map to `McpServerController::handle()`

#### Scenario: Capability href uses URL generator
- **GIVEN** the `McpDiscoveryService` builds capability entries
- **WHEN** `getCapabilityHref()` is called
- **THEN** it MUST use `IURLGenerator::linkToRoute()` with route name `openregister.mcp.discoverCapability` and the capability ID as argument to generate absolute URLs

### Requirement: Multi-Register Tool Scoping
The objects tool MUST enforce that every operation is scoped to a specific register and schema pair. The `McpToolsService` MUST set the register and schema context on the `ObjectService` before executing any object operation.

#### Scenario: Objects tool requires both register and schema
- **GIVEN** a client calls `tools/call` with `name: "objects"` and `arguments: {"action": "list"}` (missing register and schema)
- **WHEN** `McpToolsService::executeObjects()` checks the arguments
- **THEN** it MUST throw `InvalidArgumentException` with message `"Both register and schema IDs are required for object operations"`

#### Scenario: Register and schema are set on ObjectService
- **GIVEN** a client calls `tools/call` with `name: "objects"` and `arguments: {"action": "list", "register": 1, "schema": 2}`
- **WHEN** `McpToolsService::executeObjects()` processes the request
- **THEN** it MUST call `$this->objectService->setRegister(1)` and `$this->objectService->setSchema(2)` before executing the action

#### Scenario: Each object operation is independently scoped
- **GIVEN** a client makes two sequential `tools/call` requests for objects in different register+schema pairs
- **WHEN** each request is processed
- **THEN** each request MUST independently set register and schema on the `ObjectService`, not rely on state from a previous call

### Requirement: MCP Error Response Format
All JSON-RPC error responses from the MCP standard endpoint MUST follow the JSON-RPC 2.0 error format with `jsonrpc`, `id`, and `error` (containing `code` and `message`) fields. Error responses MUST use HTTP 200 status (per JSON-RPC convention) with the error conveyed in the response body.

#### Scenario: Error response structure
- **GIVEN** any error condition in the MCP endpoint
- **WHEN** `McpServerController::jsonRpcError()` builds the response
- **THEN** the response body MUST be `{"jsonrpc":"2.0","id":<request-id>,"error":{"code":<int>,"message":"<string>"}}`
- **AND** the HTTP status MUST be 200

#### Scenario: Parse error uses null id
- **GIVEN** the incoming JSON is unparseable
- **WHEN** the error response is built
- **THEN** the `id` field MUST be `null` (since the request ID cannot be extracted)

#### Scenario: Error codes follow JSON-RPC 2.0 and MCP conventions
- **GIVEN** the `McpServerController` defines error constants
- **WHEN** error codes are used
- **THEN** `-32700` MUST be used for parse errors, `-32600` for invalid requests, `-32601` for method not found, `-32602` for invalid params, `-32603` for internal errors, and `-32000` for session-related errors

## Current Implementation Status
- **Fully implemented -- Discovery API**: `McpDiscoveryService` (`lib/Service/McpDiscoveryService.php`) provides Tier 1 public catalog via `getCatalog()` and Tier 2 authenticated detail via `getCapabilityDetail()`. Routes registered at `/api/mcp/v1/discover` and `/api/mcp/v1/discover/{capability}` in `appinfo/routes.php`.
- **Fully implemented -- MCP Standard Protocol**: `McpServerController` (`lib/Controller/McpServerController.php`) handles JSON-RPC 2.0 dispatch. `McpProtocolService` (`lib/Service/Mcp/McpProtocolService.php`) manages sessions via APCu cache with 1-hour TTL. `McpToolsService` (`lib/Service/Mcp/McpToolsService.php`) provides three tools (registers, schemas, objects) with full CRUD. `McpResourcesService` (`lib/Service/Mcp/McpResourcesService.php`) provides resource listing, reading, and URI templates using the `openregister://` scheme.
- **Fully implemented -- Controller layer**: `McpController` (`lib/Controller/McpController.php`) handles discovery HTTP routing with proper annotations (`@PublicPage` for Tier 1, authenticated for Tier 2). `McpServerController` handles MCP protocol with `@NoAdminRequired`, `@NoCSRFRequired`, and `@CORS`.
- **Fully implemented -- Capabilities negotiation**: Initialize response declares `tools.listChanged: false`, `resources.subscribe: false`, `resources.listChanged: false` with protocol version `2025-03-26`.
- **Fully implemented -- Error handling**: All six JSON-RPC error codes are defined and used correctly. Tool execution errors return `isError: true` in content.
- **Fully implemented -- Audit logging**: All services log via `Psr\Log\LoggerInterface` at appropriate levels (debug for normal operations, error for failures).

## Standards & References
- [Model Context Protocol (MCP) specification](https://modelcontextprotocol.io/) -- defines tools, resources, prompts, and transport protocols
- [JSON-RPC 2.0 specification](https://www.jsonrpc.org/specification) -- request/response envelope format, error codes, notifications
- MCP Streamable HTTP transport -- single POST endpoint with session management via custom headers
- Nextcloud `IURLGenerator` for building absolute route URLs
- Nextcloud `ICacheFactory` (APCu distributed cache) for session storage
- Nextcloud `ISecureRandom` for cryptographically secure session ID generation
- CORS (Cross-Origin Resource Sharing) W3C specification for public endpoint access

## Cross-References
- **openapi-generation**: The discovery API complements OpenAPI specs by providing a token-efficient summary; the two should stay in sync regarding available endpoints
- **auth-system**: MCP authentication relies on Nextcloud's built-in Basic Auth and session handling; the same auth system protects both REST API and MCP endpoints

## Architecture

```
Discovery API (REST):
  GET /api/mcp/v1/discover           → McpController::discover()         → McpDiscoveryService::getCatalog()
  GET /api/mcp/v1/discover/{cap}     → McpController::discoverCapability() → McpDiscoveryService::getCapabilityDetail()

MCP Standard Protocol (JSON-RPC 2.0):
  POST /api/mcp                      → McpServerController::handle()
    ├── Parse JSON body + validate JSON-RPC 2.0 envelope
    ├── Notifications (no id)         → HTTP 202 Accepted
    ├── "initialize"                  → McpProtocolService::initialize()    (creates session)
    ├── Session validation            → McpProtocolService::validateSession() (Mcp-Session-Id header)
    └── Dispatch by method:
        ├── "ping"                    → McpProtocolService::ping()
        ├── "tools/list"              → McpToolsService::listTools()
        ├── "tools/call"              → McpToolsService::callTool()
        ├── "resources/list"          → McpResourcesService::listResources()
        ├── "resources/read"          → McpResourcesService::readResource()
        └── "resources/templates/list"→ McpResourcesService::listTemplates()
```
