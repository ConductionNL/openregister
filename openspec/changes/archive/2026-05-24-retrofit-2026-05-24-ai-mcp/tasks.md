# Tasks

- [x] task-1: ai-mcp#REQ-001 — The system MUST collect chat-agent tools from all installed apps via an event-dispatched registry (retroactive annotation)
- [x] task-2: ai-mcp#REQ-002 — The system MUST validate tool ids and reject duplicate or malformed registrations (retroactive annotation)
- [x] task-3: ai-mcp#REQ-003 — The system MUST select agent-enabled tools by id and emit warnings for missing ids (retroactive annotation)
- [x] task-4: ai-mcp#REQ-004 — The system MUST adapt IMcpToolProvider implementations to the LLPhant ToolInterface contract (retroactive annotation)
- [x] task-5: ai-mcp#REQ-005 — The system MUST round-trip MCP dotted ids to LLPhant-safe function names and forward invocations back to the provider (retroactive annotation)

## future-pass:next — deferred from this run

The following methods were in the 77-method `ai-mcp` batch but are NOT covered by REQ-001..REQ-005:

**Annotation drift (already-speced behavior, missing `@spec` tags):**
- `lib/Service/Mcp/McpProtocolService.php` (initialize, ping, createSession, validateSession, destroySession) — covered by `mcp-discovery` REQs "MCP Standard Protocol Endpoint", "MCP Session Management", "MCP Capabilities Negotiation".
- `lib/Service/Mcp/McpResourcesService.php` (listResources, listTemplates, readResource, parseUri, readRegisters, readSchemas, readObjects) — covered by `mcp-discovery` REQ "MCP Resource Definitions".
- `lib/Service/Mcp/McpToolsService.php` (listTools, callTool) — covered by `mcp-discovery` REQ "MCP Tool Definitions" + "MCP Audit Logging".
- `lib/Controller/McpServerController.php` private methods (handleNotification, dispatch, handleToolCall, jsonRpcSuccess, jsonRpcError, handleInitialize, handleResourceRead) — covered by `mcp-discovery` REQ "MCP Standard Protocol Endpoint" + "JSON-RPC Notification Handling" + "MCP Error Response Format". Currently only file-level `@spec` exists.
- `lib/Service/McpDiscoveryService.php::getCapabilityIds`, `getBaseUrl` — trivial getters but technically uncovered; add `@spec` to existing REQs.
- `lib/Tool/StreamingToolInstanceWrapper.php::normaliseArguments` — already file-annotated to `ai-chat-companion-streaming`; add per-method `@spec`.

**Already covered by in-flight `ai-chat-companion-orchestrator` (`chat-ai` capability):**
- `lib/Mcp/IMcpToolProvider.php` (getTools, invokeTool) — see `imcptoolprovider-php-interface` REQ in orchestrator change.
- `lib/Mcp/BuiltIn/RegistersToolProvider.php`, `SchemasToolProvider.php`, `ObjectsToolProvider.php` (getAppId, getTools, invokeTool, plus per-action methods) — see `imcptoolprovider-built-in-migration` REQ in orchestrator change.
- `lib/Service/Mcp/McpToolsService.php::invokeTool`, `findProviderForTool`, `getProviders`, `addProvider` — see `mcptoolsservice-provider-discovery-refactor` REQ.

**Trivial constructors and DI-wiring** (no observable behavior worth a REQ):
- `lib/Tool/AbstractTool.php::__construct`
- `lib/Tool/ApplicationTool.php::__construct`
- `lib/Tool/ObjectsTool.php::__construct`
- `lib/Service/Mcp/McpProtocolService.php::__construct`
- `lib/Service/Mcp/McpResourcesService.php::__construct`

A follow-up annotate-only pass (`/opsx-annotate`, no new REQs) should backfill `@spec` tags from the existing `mcp-discovery` and `chat-ai` specs.
