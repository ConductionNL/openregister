---
kind: code
depends_on: []
---

## Why

Per the amended hermiq ADR-001 (Workstream E, `SPECTR-NEXTCLOUD-PLAN.md` §7),
OpenRegister keeps the MCP tool registry (`ToolRegistry`, `McpProviderBridge`,
`ToolRegistrationListener`, the `IMcpToolProvider` ABI, `McpToolsService` JSON-RPC
discovery) but no longer owns the engine that consumes it — Hermiq's ported
`ToolLoop` (tracked separately as `agent-engine-port`) does. Today the only way
to reach the chat-path tool registry is to depend on OR's internal wiring
directly: `ToolRegistry` and `McpProviderBridge` are plain service/tool classes
with no stability contract, and the actual invocation path (resolve a function
name to its owning `ToolInterface`, then call `executeFunction()`) is private
logic duplicated inside `ToolManagementHandler`/`ResponseGenerationHandler`.

An external app reaching into that wiring directly is exactly the un-contracted
cross-app dependency `hydra-gate-no-phantom-cross-app-rpc` (gate-27) flags —
the same class of incident as opencatalogi calling the since-removed
`ObjectService->publish()`. Gate-27's own spec says the forward half of the
gate is "structured to consume OpenRegister's public-API contract... once it
is published" — this change is that contract's first concrete instance for
the MCP tool-loop surface, satisfying ADR-022 (apps consume OR abstractions)
and gate-27's push toward an explicit, documented OR public API.

## What Changes

- Add a new public service class, `OCA\OpenRegister\Service\Mcp\ToolRegistryFacade`,
  exposing exactly two methods:
  - `listTools(array $toolWhitelist = []): array` — returns every LLPhant-compatible
    function/tool descriptor known to `ToolRegistry` (built-in tools + every
    MCP-bridged per-app tool), optionally narrowed to a whitelist of
    `{appId}.{toolName}` registry ids. Empty whitelist = all discovered tools
    allowed, matching hydra ADR-035 Decision 4's `Agent.toolWhitelist` default
    semantics.
  - `invokeTool(string $toolId, array $arguments): array` — resolves `$toolId`
    against the same flattened function index `listTools()` built and calls the
    owning tool's `executeFunction()`, returning a `{result, isError}` envelope
    consistent with `McpToolsService::invokeTool()`'s existing shape.
- Zero behavior change to OR itself: the facade is a pure read/invoke wrapper
  around the existing `ToolRegistry::getAllTools()`/`getTool()` accessors and
  each tool's own `getFunctions()`/`executeFunction()` contract. It does not
  change how per-app `IMcpToolProvider` implementations register, how
  `ToolRegistrationListener` builds `McpProviderBridge` instances, or how the
  in-app chat orchestrator (`ChatService`/`ResponseGenerationHandler`) invokes
  tools today.
- Auth flowthrough only: `invokeTool()` takes no acting-user or impersonation
  parameter. It calls straight through to `executeFunction()` in the ambient
  Nextcloud request/session context — the same "no impersonation, no elevation"
  contract `IMcpToolProvider::invokeTool()` already documents (hydra ADR-034
  Decision 7). The facade does not attach agent context (`setAgent()`) to the
  underlying tool instances — see design.md's Non-Goals for why, and the
  accepted trade-off this implies for the built-in `AbstractTool`-derived tools.
  IDOR boundaries remain each provider's own responsibility, unchanged.
- Marked as OR's supported public API surface for this purpose: the class
  docblock states the stability contract, and `openspec/platform-capabilities.md`
  gains a row pointing at this capability so a future OR refactor cannot
  silently remove it out from under Hermiq (or any other consumer) the way
  `ObjectService->publish()` was removed out from under opencatalogi.

## MCP coverage

No MCP surface — this change adds no new `{appId}.{toolName}` tool of its own.
It is purely an internal read/invoke calling convention over the *existing*
tool registry, for another app's engine (Hermiq) to depend on instead of OR's
private wiring. There is no new user-actionable surface in OpenRegister itself.

## Impact

- **PHP**: new file `lib/Service/Mcp/ToolRegistryFacade.php`. No changes to
  `lib/Service/ToolRegistry.php`, `lib/Tool/McpProviderBridge.php`,
  `lib/Listener/ToolRegistrationListener.php`, `lib/Service/Mcp/McpToolsService.php`,
  or the `IMcpToolProvider` ABI.
- **DI**: no explicit `Application.php` registration needed — the facade's
  constructor depends only on `ToolRegistry` and `LoggerInterface`, both of
  which Nextcloud's container already autowires (mirrors `ToolRegistry` itself,
  which also has no explicit `registerService` entry).
- **Schema**: none — no register/schema/migration changes.
- **Routes**: none — in-process PHP call only, same shape as Hermiq's existing
  `ScheduleService` → `ChatService` dependency today, just against a narrower,
  intentionally-public surface instead of the internal `ChatService`.
- **Tests**: `tests/Unit/Service/Mcp/ToolRegistryFacadeTest.php` — registry with
  fake `ToolInterface` mocks: list shape (built-in + bridge-shaped descriptors),
  whitelist filtering, invoke delegation, unknown-id error envelope, and that no
  agent/user-impersonation parameter exists on the public signature.
- **Docs**: `openspec/platform-capabilities.md` gains one row under the AI/MCP
  section pointing at the `ai-mcp` capability spec.
