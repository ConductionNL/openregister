## Context

Ground truth re-verified at HEAD (2026-07-06), not trusted from the plan text
per the "sonnet fabricates wave-X" project note. The chat-side tool-loop wiring
is **two cooperating services, not one**, which corrects `SPECTR-NEXTCLOUD-PLAN.md`
§7.2's single-bridge framing and matches the appendix in
`hermiq/openspec/changes/agent-engine-schemas/design.md` (Appendix A), which was
itself already written against this corrected wiring:

- **`ToolRegistry`** (`lib/Service/ToolRegistry.php`) — the generic, lazily-loaded
  container of LLPhant `ToolInterface` instances. `ChatService`'s
  `ToolManagementHandler` reads from this registry (`getTool`/`getTools`/
  `getAllTools`) to build the function list the LLM sees. This is the path
  `ResponseGenerationHandler` actually drives at chat time.
- **`McpProviderBridge`** (`lib/Tool/McpProviderBridge.php`) — wraps one
  `IMcpToolProvider` so its MCP tool descriptors show up as LLPhant functions.
  `ToolRegistrationListener` (`lib/Listener/ToolRegistrationListener.php`)
  constructs one bridge per `(provider, function)` pair and registers it on
  `ToolRegistry` under the tool's full dotted MCP id (`setOnlyMcpId`).
  `McpToolsService` (`lib/Service/Mcp/McpToolsService.php`) is a **separate**
  facade that only the MCP JSON-RPC endpoint (`McpController`) uses — it is not
  in the chat-invocation path at all, it just happens to hold the ordered
  `IMcpToolProvider` list that `ToolRegistrationListener` iterates once at boot
  to build the bridges.

Hermiq's ported `ToolLoop` (tracked separately as `agent-engine-port`, out of
scope here) needs a small, stable, additive surface to call across the app
boundary instead of depending on `ToolRegistry`/`McpProviderBridge` directly.
This change is exactly Appendix A of that design doc, materialized in
OpenRegister's own repo per the appendix's own instruction ("written here as
proposal-shaped design text for the Hydra orchestrator to materialize as real
`openspec/changes/<slug>/` directories... when the build sequence reaches
them").

## Goals / Non-Goals

**Goals:**
- One new class, `OCA\OpenRegister\Service\Mcp\ToolRegistryFacade`, with
  exactly two public methods: `listTools()` and `invokeTool()`.
- Zero behavior change to `ToolRegistry`, `McpProviderBridge`,
  `ToolRegistrationListener`, `McpToolsService`, or the `IMcpToolProvider` ABI.
- Auth flowthrough only — no acting-user parameter, no impersonation, no
  elevation. The facade runs in the caller's ambient Nextcloud request/session
  context, same as every other in-process OR service call.
- Mark the class as OR's supported public API surface for this purpose (gate-27
  / ADR-022), so a future internal refactor of `ToolRegistry` cannot silently
  break Hermiq (or any other consumer) the way `ObjectService->publish()` broke
  opencatalogi.

**Non-Goals:**
- No new MCP tool of its own (see proposal.md's "MCP coverage" section).
- No `agent-engine-port` work — this change does not touch Hermiq, does not add
  a `chatAppId` concept, and does not implement the tool *loop* (turn assembly,
  LLM provider call, streaming). It only implements the *registry read/invoke*
  seam the loop will call.
- No `setAgent()`/agent-context passthrough. `AbstractTool`-derived built-in
  tools (`RegisterTool`, `SchemaTool`, `ObjectsTool`, `ApplicationTool`,
  `AgentTool`) use an attached `Agent` for view-scoped filtering
  (`AbstractTool::getViews()`) when `ChatService` invokes them today. Hermiq's
  own `Agent` concept is moving into Hermiq's own register under the amended
  ADR-001 — it is no longer, and after the migration will never again be, an
  `OCA\OpenRegister\Db\Agent` instance. Accepting one here would force Hermiq to
  hold a stale OR entity type for a facade explicitly scoped to be small and
  additive. **Accepted trade-off:** tools invoked through this facade fall back
  to ambient-NC-user scoping only, with no agent-specific view filter. This is
  acceptable because the facade's motivating consumer needs the MCP-bridged
  per-app tools (which do their own per-object IDOR checks against the ambient
  session per the `IMcpToolProvider` contract, unaffected by `setAgent()`), not
  agent-view-scoped built-in OR tools. Tracked as a documented limitation, not
  silently dropped.
- No route, no controller, no HTTP surface — in-process PHP call only.

## Decisions

### The public surface is exactly two methods, matching the appendix literally

`listTools()` and `invokeTool()` — nothing else. No `registerTool()`
passthrough (registration stays exclusively the `ToolRegistrationEvent` +
`ToolRegistrationListener` path), no direct access to the underlying
`ToolInterface` instances, no `getAllTools()`-shaped metadata-only accessor.
Keeping the surface to two methods keeps the contract auditable in one file and
matches "a small, additive, no behavior change" from proposal.md.

### `listTools()` signature: `array $toolWhitelist = []`, not the appendix's literal `?string`

The appendix's prose (Appendix A, "What Changes") writes the signature as
`listTools(?string $agentToolWhitelist = null): array`. Re-checked against
hydra ADR-035 Decision 4, which is the appendix's own cited source for this
semantics: *"a future OR schema change... adds an `Agent.toolWhitelist:
string[]` field"* — a **list** of `{appId}.{toolName}` strings, not a single
string. A `?string` parameter cannot express "filter by this whitelist of tool
ids" for more than one tool. This is treated as the same class of drift the
hermiq design.md itself called out and corrected against its own source plan
text ("ground-truth-first... rather than trusted from the plan text") — the
appendix is narrated design prose, not a compiled contract, and ADR-035's own
field type is the authoritative shape. This change implements
`array $toolWhitelist = []` (empty = all discovered tools allowed, matching
ADR-035 Decision 4's stated default), and documents the deviation here rather
than reproducing a type mismatch that would not even compile against the
`Agent.toolWhitelist: string[]` field it exists to serve.

### `listTools()` flattens to function-level descriptors, not registry-id-level metadata

`ToolRegistry::getAllTools()` returns one metadata row per **registry id**
(`name`/`description`/`icon`/`app`) — not the LLPhant function descriptors
proposal.md asks for. A registry id can back **multiple** callable functions:
the five built-in tools each expose several (`RegisterTool` alone has
`list_registers`, `get_register`, `create_register`, `update_register`,
`delete_register`), while an `McpProviderBridge` entry (narrowed via
`setOnlyMcpId`) exposes exactly one. `listTools()` therefore:

1. Reads every registry id from `ToolRegistry::getAllTools()` (optionally
   pre-filtered by `$toolWhitelist`, matching on the registry id).
2. Resolves each id back to its `ToolInterface` via `ToolRegistry::getTool()`.
3. Calls `getFunctions()` on each and flattens the result into one list of
   LLPhant-shaped descriptors (`name`, `description`, `parameters`, plus
   `mcpId` when the tool is a bridge), so the returned shape is exactly what a
   tool-loop needs to hand an LLM provider — no second call required.

This mirrors what `ToolManagementHandler::convertToolsToFunctions()` already
does internally for the in-app chat path; the facade does not duplicate new
logic, it exposes the same flattening as a public, stable read.

### `invokeTool()` resolves by function name or dotted mcpId, not by bare registry id

Because a registry id can own several functions, the addressable unit for
invocation is the **function descriptor** the `listTools()` result carries —
matched on either its `name` (the LLPhant-safe underscore form an LLM
tool-call echoes back, e.g. `decidesk_listMeetings`) or its `mcpId` (the
dotted form that doubles as the registry id for bridged tools and is what an
ADR-035 `toolWhitelist` stores, e.g. `decidesk.listMeetings`). `invokeTool()`
walks the same registry, finds the `ToolInterface` instance whose
`getFunctions()` includes a descriptor matching either key, and calls
`->executeFunction($toolId, $arguments)` on it — the owning tool's own
resolver (`McpProviderBridge::resolveMcpId()`) already accepts both forms for
bridged tools. This is the same resolution
`ToolManagementHandler::convertFunctionsToFunctionInfo()` performs today when
matching a function name back to its owning tool instance — the facade exposes
that existing resolution as a callable public method instead of leaving it
buried in a handler used only by `ChatService`.

Return shape mirrors `McpToolsService::invokeTool()`'s existing envelope for
consistency across OR's two MCP-adjacent public surfaces:

```php
['result' => array, 'isError' => bool]
```

- Unknown `$toolId` → `['result' => ['error' => 'Unknown tool: {id}'], 'isError' => true]`
  (no exception — mirrors `McpToolsService::invokeTool()`'s existing
  not-found shape exactly).
- The owning tool's `executeFunction()` throwing → caught (`\Throwable`, not
  `\Exception` — a `TypeError` from a malformed argument must not become an
  uncaught 500, matching the `\Throwable` catches already used in
  `McpProviderBridge::executeFunction()` and `McpToolsService::callTool()`/
  `invokeTool()`) → logged at error level → returned as
  `['result' => ['error' => $message], 'isError' => true]`.
- Success → `['result' => <executeFunction()'s raw return>, 'isError' => false]`.

### No impersonation: the facade never sets a user, agent, or session

`invokeTool()` has no `$userId`/`$actingUser` parameter. It calls
`executeFunction()` in whatever Nextcloud request/session context the calling
code is already running in — identical to how every other in-process OR
service call works. This is the literal reading of hydra ADR-034 Decision 7
("no impersonation, no elevation") and `IMcpToolProvider::invokeTool()`'s own
docblock ("the runtime passes through the current user's session unchanged").
There is nothing to implement here beyond *not adding* an impersonation path —
it is a negative decision, but is called out explicitly so a future PR doesn't
"helpfully" add one.

## Risks / Trade-offs

- **Agent-view-scoped filtering gap for built-in tools** — see Non-Goals. A
  caller invoking `openregister.register`'s functions through this facade
  (rather than through `ChatService`) gets no agent-scoped view filter. Only
  matters if a consumer other than the MCP-bridged per-app tools starts
  depending on this facade for the five OR built-ins; Hermiq's `ToolLoop` (out
  of scope here) is expected to primarily exercise the bridged per-app tools.
- **Function-name collisions across tool groups** — `listTools()`'s flattened
  descriptors key on function `name`. Built-in names are fixed
  (`list_registers`, etc.) and bridge names are `{appId}_{toolName}`
  (underscore-joined); a collision would require a third-party
  `IMcpToolProvider` to coincidentally choose a name identical to a built-in
  function name. `invokeTool()` resolves first-match in registry iteration
  order (built-ins first, per `ToolRegistrationListener::handle()`'s ordering),
  consistent with `McpToolsService::findProviderForTool()`'s existing
  first-wins collision handling for the parallel MCP JSON-RPC surface.
- **Stability contract is documentation, not tooling, until gate-27's forward
  half ships.** Gate-27 (`hydra-gate-no-phantom-cross-app-rpc`) currently
  enforces only a curated denylist of *removed* OR methods; its "forward" half
  (validate all calls against a published contract) is inert until
  `HYDRA_OR_PUBLIC_API_CONTRACT` points at a real artifact. This change makes
  `ToolRegistryFacade` the first candidate surface for that artifact but does
  not itself wire the env var or produce a machine-readable contract file —
  that is Hydra-side follow-up, not in scope for an OpenRegister `kind: code`
  change.

## Migration

None — additive-only, no schema, no route, no config. `git revert` fully
removes the new class with no other side effects.
