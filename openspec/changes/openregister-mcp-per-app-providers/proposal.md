---
kind: code
depends_on: []
---

## Why

OpenRegister's MCP endpoint exposes three built-in tools (`registers`,
`schemas`, `objects`). Apps that build on top of OpenRegister
(Decidesk, OpenBuilt, and future integrations) need to surface their
own tools through the same endpoint so the AI Chat Companion and any
external MCP client can drive them in one place — create a meeting,
add an agenda item, build a virtual app, promote a draft version, etc.

A previous iteration had this: an `OCA\OpenRegister\Mcp\IMcpToolProvider`
interface, three built-in providers, and a factory in
`Application::registerMcpToolProviders` that walked
`IAppManager::getInstalledApps()` and resolved each app's provider via
three FQCN candidates (DI alias, `ucfirst($appId)` namespace, and
`<namespace>` read from `appinfo/info.xml`). That layer was removed
during an unrelated refactor and the only remaining MCP surface today
is the three hard-coded built-ins.

The two consumer apps still ship working providers on disk —
`OpenBuilt\Mcp\OpenBuiltToolProvider` (8 tools) and
`Decidesk\Mcp\DecideskToolProvider` (5 tools) — both implementing the
old `OCA\OpenRegister\Mcp\IMcpToolProvider` interface. They register
themselves under the DI alias key
`OCA\OpenRegister\Mcp\IMcpToolProvider::{appId}`. Eleven persona
scenarios in `tests/mcp-personas/scenarios/` exercise these tools and
were all green in the same dev session that observed the regression.

The chat-companion contract (`specs/chat-ai/spec.md` REQ-004) already
assumes "tools registered in the OpenRegister `ToolRegistry` from all
apps". Without per-app provider discovery that contract is unsatisfied.

## What Changes

- Reintroduce `OCA\OpenRegister\Mcp\IMcpToolProvider` with the three
  method signatures the consumer providers already implement:
  `getAppId(): string`, `getTools(): array`, `invokeTool(string $toolId,
  array $arguments): array`.
- Restore the three built-in providers
  (`RegistersToolProvider`, `SchemasToolProvider`, `ObjectsToolProvider`)
  as `IMcpToolProvider` implementations wrapping the existing service-level
  CRUD logic on `RegisterService`, `SchemaMapper`, and `ObjectService`.
  No behaviour change for the three built-in tool ids.
- Change `McpToolsService` to take an injected `array<IMcpToolProvider>
  $providers` (replacing the current direct service deps). `listTools()`
  flattens `$provider->getTools()` across all providers; `callTool($name,
  $args)` routes by `getAppId()` prefix of the tool id to the owning
  provider and wraps the return value in the existing
  `{content:[{type:text,text}], isError}` envelope.
- Add a factory in `Application::registerMcpToolProviders(IRegistrationContext)`
  that registers `McpToolsService::class` via a closure which:
  1. Resolves the three built-in providers from the container.
  2. Walks `IAppManager::getInstalledApps()` and tries three FQCN
     candidates per app: DI alias `OCA\OpenRegister\Mcp\IMcpToolProvider::{appId}`,
     `OCA\{ucfirst(appId)}\Mcp\{ucfirst(appId)}ToolProvider`, and
     `OCA\{<namespace> from info.xml}\Mcp\{<namespace>}ToolProvider`.
  3. Skips silently (with a `warning`-level log line carrying the
     candidate that failed) when none of the three resolves. App
     enumeration must never throw a fatal — a single bad app cannot
     take the MCP endpoint down.
- The `appinfo/info.xml` read MUST use `file_get_contents` +
  `simplexml_load_string`, NOT `simplexml_load_file`. NC's bootstrap
  nulls libxml's external-entity resolver and `simplexml_load_file`
  returns false on otherwise well-formed files (already documented in
  the OpenBuilt MCP follow-up).
- Discovery happens once per request, at container resolution time of
  `McpToolsService`. No process-wide cache.
- The 11 existing persona scenarios in `tests/mcp-personas/scenarios/`
  pass back-to-back via the `harness.php` runner against the same
  Nextcloud instance with both consumer apps installed.

## Non-Goals

- Provider hot-reload (an installed app added mid-request will not be
  discovered until the next request — matches every other NC discovery
  path).
- Tool-level RBAC at the discovery layer. Per-tool auth stays inside
  each provider's `invokeTool` body, as both consumer providers already
  enforce via `requireAuthenticatedUser()`.
- Schema validation of provider-supplied `inputSchema` JSON. We trust
  the provider; bad descriptors surface as LLM call failures and are a
  provider-side bug.
- Inventing new tool ids. The 16 tool ids already shipping in the two
  consumer providers (8 OpenBuilt + 5 Decidesk + 3 OpenRegister
  built-ins) define the surface — this change only restores discovery.
- Touching the chat-companion side. `ChatStreamController` and
  `ToolManagementHandler` already consume whatever `McpToolsService`
  exposes; no change needed.
