# Retrofit — ai-mcp

Describes observed behavior of 14 methods (across 3 files) under `ai-mcp` as 5 new REQs. Code already exists — this change retroactively specifies it.

## Affected code units

**Tool registry — event-driven cross-app tool registration:**
- `lib/Service/ToolRegistry.php::__construct`
- `lib/Service/ToolRegistry.php::loadTools` (private, lazy)
- `lib/Service/ToolRegistry.php::registerTool`
- `lib/Service/ToolRegistry.php::getTool`
- `lib/Service/ToolRegistry.php::getTools` (multi-id selection)
- `lib/Event/ToolRegistrationEvent.php::__construct`
- `lib/Event/ToolRegistrationEvent.php::registerTool` (already annotated to retrofit-2026-04-23 task-27 — re-annotated under ai-mcp REQ-001)

**LLPhant adapter for IMcpToolProvider:**
- `lib/Tool/McpProviderBridge.php::__construct`
- `lib/Tool/McpProviderBridge.php::setOnlyMcpId`
- `lib/Tool/McpProviderBridge.php::getName`
- `lib/Tool/McpProviderBridge.php::getDescription`
- `lib/Tool/McpProviderBridge.php::getFunctions`
- `lib/Tool/McpProviderBridge.php::sanitiseSchema` (private)
- `lib/Tool/McpProviderBridge.php::collapseType` (private)
- `lib/Tool/McpProviderBridge.php::executeFunction`
- `lib/Tool/McpProviderBridge.php::setAgent`
- `lib/Tool/McpProviderBridge.php::safeFunctionName` (private)
- `lib/Tool/McpProviderBridge.php::resolveMcpId` (private)
- `lib/Tool/McpProviderBridge.php::__call` (magic — LLPhant dispatch entry)

## Approach

`ai-mcp` is minted as a NEW umbrella capability for the LLPhant tool-bridging machinery that complements the existing `mcp-discovery` (JSON-RPC server) and the in-flight `ai-chat-companion-orchestrator` (`chat-ai` orchestrator) capabilities. Concretely:

- **`mcp-discovery`** already specifies the JSON-RPC 2.0 server, session management, tools/list, tools/call, resources/list, resources/read — the wire protocol.
- **`ai-chat-companion-orchestrator`** (in-flight in `openspec/changes/`) specifies `IMcpToolProvider` plus the `McpToolsService` provider-discovery refactor — the per-app MCP plugin contract.
- **`ai-mcp` (this change)** specifies the intermediate layer that exposes registered tools to LLPhant chat agents: an event-driven `ToolRegistry` so other Nextcloud apps can drop tools onto the chat tool-loop, and an `McpProviderBridge` adapter so the same per-app MCP tool providers can be invoked through LLPhant's `ToolInterface` contract (dot-vs-underscore naming, JSON-Schema nullable collapse, structured-error envelope).

For each method, the REQs describe observed inputs/outputs, validation, lazy-load semantics, and failure modes. The 5 REQs cluster the methods by observable behavior rather than per-file.

### Dropped from the original 77-method batch

- **54 methods tagged "(triaged DROP from X)" in the batch JSON** — these were checked against other capabilities, found NOT to belong there. Re-inspection shows the genuine homes are:
  - `lib/Mcp/IMcpToolProvider.php`, `lib/Mcp/BuiltIn/*ToolProvider.php`, `lib/Service/Mcp/McpToolsService.php` provider methods (15 methods) → already speced in the in-flight `ai-chat-companion-orchestrator` change under `chat-ai`. Already annotated there. Dropped — re-speccing would conflict.
  - `lib/Controller/McpServerController.php` (7 methods: handleNotification, dispatch, handleToolCall, jsonRpcSuccess, jsonRpcError, handleInitialize, handleResourceRead) → already covered by `mcp-discovery` REQs (JSON-RPC dispatch, notification handling, error format). File-level `@spec` already points at `retrofit-2026-04-30-annotate-openregister#task-54`. Method-level `@spec` gaps are an annotation drift, not a new-REQ need. Flagged in Notes.
  - `lib/Service/Mcp/McpResourcesService.php` (8 methods incl. parseUri, readRegisters, readSchemas, readObjects) → already speced under `mcp-discovery` "MCP Resource Definitions" REQ. Annotation drift.
  - `lib/Tool/StreamingToolInstanceWrapper.php::normaliseArguments` → already annotated to `ai-chat-companion-streaming`.

- **Constructors and trivial getters** (7 methods: McpProtocolService, McpResourcesService, McpToolsService constructors; AbstractTool, ApplicationTool, ObjectsTool constructors; McpDiscoveryService::getCapabilityIds, getBaseUrl) → behavior is trivial DI wiring or list-of-keys getters; no observable REQ-worthy behavior. Dropped.

- **`lib/Service/Mcp/McpProtocolService.php` (6 methods: initialize, ping, createSession, validateSession, destroySession)** → already speced under `mcp-discovery` REQs "MCP Standard Protocol Endpoint", "MCP Session Management", "MCP Capabilities Negotiation". Annotation drift only. Deferred to `future-pass:next` for annotation backfill.

- **`lib/Service/Mcp/McpToolsService.php::listTools`, `::callTool`** → `mcp-discovery` REQ "MCP Tool Definitions" + "MCP Audit Logging" already covers these. Annotation gap, not reverse-spec gap.

### Drift flags (for future-pass)

- Many MCP services have file-level `@spec` to retrofit-2026-04-23-annotate-openregister but lack method-level annotations. A separate annotate pass should backfill `@spec` references to existing `mcp-discovery` REQs.
- `McpServerController` has only ONE file-level `@spec` (task-54). The 7 private/internal methods listed above need per-method `@spec` references to `mcp-discovery#JSON-RPC dispatch / notifications / tool call`.
- The `ToolRegistrationEvent::registerTool` method is already annotated to `retrofit-2026-04-23-annotate-openregister#task-27`. In this change it is re-annotated under `ai-mcp#REQ-001` (additive, not replacing — both tags coexist).

Source: openspec/coverage-report.json generated 2026-05-23. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
