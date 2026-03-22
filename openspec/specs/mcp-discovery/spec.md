---
status: partial
---
# MCP Discovery

## Purpose
Provides AI agents and MCP-compatible clients with two complementary interfaces to the OpenRegister platform: a tiered REST-based discovery API for token-efficient API exploration, and a full MCP standard protocol endpoint implementing JSON-RPC 2.0 over Streamable HTTP for native tool and resource access. Together these interfaces allow any LLM or MCP client to discover capabilities, establish sessions, and perform CRUD operations on registers, schemas, and objects without prior knowledge of the API surface.

## Requirements


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
