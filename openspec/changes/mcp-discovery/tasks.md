# Tasks: MCP Discovery

> **Status (Phase 2):** All 14 spec requirements ticked. The two previously-open tasks ("MCP Audit Logging" and "Multi-Register Tool Scoping") are both already implemented; their spec scenarios match the existing code at `McpToolsService` lines 89-92, 113-117, 325-329, and `McpProtocolService` lines 163, 183, 204. The new `tests/Service/McpToolScopingIntegrationTest` (7 tests) locks in the scoping contract — both via the public `callTool` envelope and via direct `executeObjects` reflection.

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

- [x] **MCP Audit Logging** — `McpToolsService::callTool` ([lib/Service/Mcp/McpToolsService.php:89](../../../lib/Service/Mcp/McpToolsService.php)) emits `[MCP] Tool call` at debug level on every invocation and `[MCP] Tool execution failed` at error level when an exception is caught (line 114). `McpProtocolService::createSession` ([lib/Service/Mcp/McpProtocolService.php:163](../../../lib/Service/Mcp/McpProtocolService.php)) emits `[MCP] Session created`; `validateSession` (line 183) emits `[MCP] Invalid or expired session`; `destroySession` (line 204) emits `[MCP] Session destroyed`. The structured-audit-table question raised in earlier review is settled: MCP-attributed object writes flow through `MagicMapper::saveObjectFromArray` → AuditTrailService just like REST writes, so MCP actions show up in `oc_openregister_audit_trails` automatically. The `LoggerInterface` channel covers protocol-level events (sessions, tool calls); the audit-trail table covers data mutations. No separate "MCP audit channel" is needed.

- [x] **Multi-Register Tool Scoping** — `McpToolsService::executeObjects` ([lib/Service/Mcp/McpToolsService.php:318](../../../lib/Service/Mcp/McpToolsService.php)) requires both `register` and `schema` arguments, throws `InvalidArgumentException("Both register and schema IDs are required for object operations")` if either is missing (line 325-329), and calls `setRegister()`/`setSchema()` on the live `ObjectService` before delegating to the action handler (line 331-332). The `callTool` wrapper (line 87) catches the exception and surfaces it as an MCP error envelope (`isError: true` + content-text containing the error message). Session-bound pre-scoping was deliberately not implemented — typical MCP clients scope per call, and per-call scoping is enforced by the missing-argument throw. Verified by `tests/Service/McpToolScopingIntegrationTest` (7 tests): missing-register error envelope, missing-schema error envelope, missing-both error envelope, executeObjects throws InvalidArgumentException directly via reflection, register+schema set on ObjectService after a successful call, fresh per-call scoping (call A → call B doesn't inherit A's context), `registers` tool requires no scoping argument.

## Test coverage

- [x] `tests/Service/McpDiscoveryIntegrationTest` — 10 integration tests covering catalog, capability ids parity, tier-2 detail (known + unknown), initialize handshake (envelope shape + serverInfo + capabilities), full session lifecycle (create/validate/destroy + unknown-id fail-closed), tools list shape + foundational presence, resources list shape + baseline URIs, templates list shape.
- [x] `tests/Service/McpToolScopingIntegrationTest` — 7 integration tests proving the multi-register scoping contract on the `objects` tool (3 missing-argument error envelopes, 1 reflection-driven InvalidArgumentException, 2 successful-scoping context assertions, 1 negative case proving `registers` doesn't require scoping).

17 MCP-discovery tests across the spec, all green.

## Architecture (Phase 2 decisions)

| Decision | Choice |
|---|---|
| Audit channel for MCP | `LoggerInterface` for protocol events (session lifecycle, tool calls); existing `oc_openregister_audit_trails` for data mutations triggered by MCP. No dedicated MCP-only audit table. |
| Tool scoping mechanism | Per-call required arguments (`register` + `schema` on `objects`). No session-bound pre-scoping; clients pass scope on every call. Missing arguments fail-closed via `InvalidArgumentException`. |
