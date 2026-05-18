## Context

Parent capability: `openregister/openspec/specs/mcp-discovery/spec.md`.
That spec defines the public surface (Tier 1 discovery catalog, Tier 2
capability detail, JSON-RPC 2.0 envelope, session management via
`Mcp-Session-Id`, three built-in tools) but is silent on how
non-built-in tools enter `tools/list` / `tools/call`. This change adds
that mechanism.

Sibling capability: `openregister/openspec/specs/chat-ai/spec.md`
REQ-004 already assumes tools "registered in the OpenRegister
ToolRegistry from all apps". That contract has been unsatisfiable
since the per-app discovery layer was removed; this change closes the
gap without touching chat-ai itself.

## Goals

- Restore per-app tool discovery via a stable provider interface that
  the existing consumer apps already implement.
- Keep the three built-in tools (`registers`, `schemas`, `objects`)
  reachable via the same dispatcher so the only thing that changes for
  built-ins is the wrapping class — the tool ids and behaviour are
  identical.
- Discovery failure of one app MUST NOT take the MCP endpoint down.
  Bad apps log and skip.
- All 11 persona scenarios under `tests/mcp-personas/scenarios/` must
  pass on a back-to-back sweep without manual intervention between
  scenarios.

## Non-Goals

- Designing a new tool contract. The existing `getAppId`/`getTools`/
  `invokeTool` shape is what the two consumer providers already
  implement; this change codifies it.
- Solving the chat-companion's separate `ToolRegistry` story. That
  registry lives behind `ChatStreamController` and consumes
  `McpToolsService` indirectly; once discovery is restored the registry
  populates itself.

## Architecture

```
┌──────────────────────────────┐    ┌────────────────────────────────┐
│ Consumer apps                │    │ OpenRegister                   │
│ (openbuilt, decidesk, ...)   │    │                                │
│                              │    │ Application::register():       │
│ Application::register() {    │    │   registerService(McpToolsServ.│
│   registerServiceAlias(      │ ─► │     closure walks              │
│     'OCA\OpenRegister\Mcp\   │    │       IAppManager::            │
│      IMcpToolProvider::      │    │         getInstalledApps()     │
│      openbuilt',             │    │     + 3 built-in providers     │
│     OpenBuiltToolProvider::  │    │     = array<IMcpToolProvider>) │
│       class                  │    │                                │
│   );                         │    │ McpToolsService                │
│ }                            │    │   ::listTools() flattens .getTools()
│                              │    │   ::callTool($name, $args)     │
│ class OpenBuiltToolProvider  │    │     finds provider by getAppId │
│   implements IMcpToolProvider│    │       prefix, invokes,         │
│                              │    │     wraps in MCP envelope      │
└──────────────────────────────┘    └────────────────────────────────┘
                                                  │
                                                  ▼
                                    JSON-RPC 2.0 / SSE over
                                    /api/mcp  (existing)
```

### Discovery candidate order

For each `$appId` returned by `IAppManager::getInstalledApps()`:

1. **DI alias key** — `OCA\OpenRegister\Mcp\IMcpToolProvider::{appId}`.
   Works when the consumer app registered the alias on the same
   container instance OR opted into cross-app container exposure.
   Default for new apps.
2. **Naïve FQCN** — `OCA\{ucfirst($appId)}\Mcp\{ucfirst($appId)}ToolProvider`.
   Works for apps whose namespace equals `ucfirst(appId)` (e.g.
   `decidesk` → `Decidesk`).
3. **Namespace-aware FQCN** — read `<namespace>` from
   `{appPath}/appinfo/info.xml`, build `OCA\{$ns}\Mcp\{$ns}ToolProvider`.
   Required for camelCase-namespaced apps where `ucfirst($appId)` is
   wrong (e.g. `openbuilt` → `OpenBuilt`, `softwarecatalog` →
   `SoftwareCatalog`). Read via `file_get_contents` +
   `simplexml_load_string`; `simplexml_load_file` fails inside
   Nextcloud because the bootstrap nulls the external-entity resolver.

Stop at the first candidate that resolves to an `IMcpToolProvider`
instance. Log other failed attempts at `warning` level for ops
visibility; never throw.

### Dispatch

`McpToolsService::callTool($name, $args)` splits the tool id on the
first `.`, finds the unique provider whose `getAppId()` equals the
prefix, and calls `$provider->invokeTool($name, $args)`. Built-in tool
ids (`registers`, `schemas`, `objects`) carry no `.` and route to the
matching built-in provider (whose `getAppId()` returns the literal
tool name) for backwards compatibility.

If two providers claim the same `getAppId()` the factory logs an error
and keeps the first one; this never happens in practice because the
appId is the Nextcloud app id and `getInstalledApps()` returns unique
strings.

### Failure envelope

Provider throws → `McpToolsService` catches, logs, returns the existing
`{content:[{type:text,text:<error json>}], isError:true}` envelope per
the MCP spec. Provider returns its own `isError:true` payload →
`McpToolsService` wraps it without setting outer `isError` (callers
parse the inner JSON, matching the convention the persona harness
already uses).

## Standards & References

- Parent spec: `openregister/openspec/specs/mcp-discovery/spec.md`
- Existing chat contract: `openregister/openspec/specs/chat-ai/spec.md`
  REQ-004 (tool discovery via `ToolRegistry`)
- Consumer implementations:
  - `openbuilt/lib/Mcp/OpenBuiltToolProvider.php` (8 tools)
  - `openregister/custom_apps/decidesk/lib/Mcp/DecideskToolProvider.php`
    (5 tools)
- Consumer registration sites:
  - `openbuilt/lib/AppInfo/Application.php::register()` (DI alias)
  - `openregister/custom_apps/decidesk/lib/AppInfo/Application.php::register()`
    (DI alias)
- Test harness: `openregister/tests/mcp-personas/harness.php`
- 11 persona scenarios under `openregister/tests/mcp-personas/scenarios/`
- MCP protocol: <https://modelcontextprotocol.io/specification>
  (`tools/list` and `tools/call` shape)

## Production notes

- **Single-request cost.** Discovery runs once per resolution of
  `McpToolsService`. Reading `info.xml` for every installed app at
  every MCP request is ~50 XML parses on a stock instance; cheap, but
  if profiling shows it as hot we cache the resolved `array<appId,
  FQCN|null>` in `IMemcache` keyed by NC version + app set hash.
- **App namespace drift.** When a consumer app rebrands and changes
  `<namespace>` in info.xml without bumping its appId, the namespace
  candidate (#3) re-resolves automatically. The DI-alias candidate (#1)
  also keeps working — it's keyed on appId, not namespace.
- **Removed-app behaviour.** When an app is uninstalled,
  `getInstalledApps()` stops returning it; no stale provider lingers in
  `McpToolsService`. Compare to chat-ai's `ToolRegistry`, which has the
  same lifecycle.
- **Multiple consumer apps with the same tool prefix.** Forbidden by
  contract — `getAppId()` MUST equal the NC app id, which is unique.
  The dispatch logs an error and keeps the first if it ever happens.
