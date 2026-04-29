# Tasks: MCP Discovery

> **Status:** Implementation lives across `lib/Service/McpDiscoveryService.php`, `lib/Service/Mcp/McpProtocolService.php`, `lib/Service/Mcp/McpToolsService.php`, `lib/Service/Mcp/McpResourcesService.php`, with controllers `McpController` (REST tier-1/2 discovery) and `McpServerController` (JSON-RPC). `tests/Service/McpDiscoveryIntegrationTest` (10 tests) verifies the discovery + protocol surface end-to-end.

## Implemented

- [x] **Tier 1 Discovery Catalog** — `McpDiscoveryService::getCatalog()` returns the canonical 10-capability catalog (registers, schemas, objects, search, files, audit, bulk, webhooks, chat, views) with per-capability `href` for tier-2 drill-down. **Verified live** by `testCatalogReturnsCanonicalCapabilities` and `testGetCapabilityIdsMatchesCatalogIds`.

- [x] **Tier 2 Capability Detail with Live Data** — `getCapabilityDetail(string $capability)` returns the per-capability detail envelope (id + endpoints/operations + descriptions). **Verified live** by `testCapabilityDetailReturnsTier2ForKnownId` (object detail surfaces endpoints) and `testCapabilityDetailReturnsNullForUnknownId` (unknown ids fail closed).

- [x] **Capability Coverage** — all 10 capabilities documented in the proposal are surfaced by the catalog and resolvable via tier-2 detail. Verified by `testCatalogReturnsCanonicalCapabilities` (asserts every expected id is present).

- [x] **Token Efficiency** — tier-1 catalog is a flat array of `{id, name, description, href}` entries; total payload is sub-1KB for the 10 capabilities. Tier-2 detail is fetched on demand via `href`, not pre-baked into tier-1.

- [x] **MCP Standard Protocol Endpoint (JSON-RPC 2.0)** — `McpServerController` accepts JSON-RPC requests at `POST /apps/openregister/mcp`. Routes `initialize` / `ping` / `tools/list` / `tools/call` / `resources/list` / `resources/templates/list` / `resources/read` to the corresponding service methods. **Verified live** by `testProtocolInitializeReturnsServerInfo` driving the full initialize handshake.

- [x] **MCP Session Management** — `McpProtocolService::createSession()` / `validateSession()` / `destroySession()` with the session id returned by the initialize envelope. **Verified live** by `testSessionLifecycleCreateValidateDestroy` (create returns string, validate returns the original uid, destroy makes validate return null) and `testValidateSessionReturnsNullForUnknownId`.

- [x] **MCP Tool Definitions** — `McpToolsService::listTools()` surfaces three foundational tools (registers, schemas, objects), each with the standard MCP `{name, description, inputSchema}` envelope. **Verified live** by `testListToolsReturnsToolDefinitions` (asserts presence + per-tool shape).

- [x] **MCP Resource Definitions** — `McpResourcesService::listResources()` surfaces baseline resources (`openregister://registers`, `openregister://schemas`) plus one entry per register+schema pair, each with `{uri, name, description, mimeType}`. `listTemplates()` returns URI-template definitions. **Verified live** by `testListResourcesReturnsResourceDefinitions` and `testListTemplatesReturnsTemplateDefinitions`.

- [x] **MCP Capabilities Negotiation** — `initialize()` returns `capabilities: {tools: {listChanged: false}, resources: {subscribe: false, listChanged: false}}` per the MCP spec. Verified by `testProtocolInitializeReturnsServerInfo`.

- [x] **MCP Authentication via Nextcloud** — controllers run inside Nextcloud's authenticated middleware. `McpServerController` uses `IUserSession::getUser()` for the actor on every JSON-RPC call; sessions are bound to the user id at create time and validated on every subsequent call.

- [x] **JSON-RPC Notification Handling** — `McpServerController` distinguishes JSON-RPC requests (have `id`) from notifications (no `id`). Notifications return 204 No Content per spec; requests return the JSON-RPC response envelope.

## Open / partial

- [ ] **MCP Audit Logging** — partial. Tool calls log via `LoggerInterface` at debug level (`McpToolsService::callTool`), but a dedicated structured-audit table (separate from the standard log file) for MCP-attributed actions is not yet implemented. **Open** — design question whether MCP actions need a dedicated audit channel or whether the existing `oc_activity` + `oc_openregister_audit_trails` (which already capture object writes) are sufficient.

- [ ] **Multi-Register Tool Scoping** — partial. The `registers` / `schemas` / `objects` tools take register/schema arguments, so callers can scope each call. A pre-call scoping mechanism (e.g. session-bound register subset) is not implemented. **Open** — typical clients scope per-call rather than per-session, so this may be a non-requirement.

## Test coverage

- [x] `tests/Service/McpDiscoveryIntegrationTest` — 10 integration tests covering catalog, capability ids parity, tier-2 detail (known + unknown), initialize handshake (envelope shape + serverInfo + capabilities), full session lifecycle (create/validate/destroy + unknown-id fail-closed), tools list shape + foundational presence, resources list shape + baseline URIs, templates list shape.
