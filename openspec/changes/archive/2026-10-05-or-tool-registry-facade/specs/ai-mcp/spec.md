## ADDED Requirements

### Requirement: REQ-006 — ToolRegistryFacade is the public read/invoke surface for cross-app tool-loop consumers

`OCA\OpenRegister\Service\Mcp\ToolRegistryFacade` MUST be OpenRegister's
supported public API for another Conduction app's engine to read and invoke the
chat-side tool registry (`ToolRegistry` + `McpProviderBridge`) without
depending on OR's internal wiring directly. The facade MUST expose exactly two
public methods and MUST NOT change `ToolRegistry`, `McpProviderBridge`,
`ToolRegistrationListener`, `McpToolsService`, or the `IMcpToolProvider` ABI.

`listTools(array $toolWhitelist = []): array` MUST return one LLPhant-shaped
function descriptor per callable function across every registered `ToolRegistry`
entry (built-in tools AND MCP-bridged per-app tools), flattening registry-id-level
tools that expose multiple functions. When `$toolWhitelist` is non-empty, only
descriptors whose owning registry id is in the whitelist MUST be returned; an
empty whitelist MUST return every discovered tool's functions (matching hydra
ADR-035 Decision 4's `Agent.toolWhitelist` default-empty semantics).

`invokeTool(string $toolId, array $arguments): array` MUST resolve `$toolId`
against the same flattened index `listTools()` builds, matching either a
descriptor's function `name` (the LLPhant-safe form, e.g.
`decidesk_listMeetings`) or its `mcpId` (the dotted registry-id form, e.g.
`decidesk.listMeetings`) so both the LLM-echoed function name and the
whitelist-stored dotted id route to the same tool. It MUST then call the
owning tool's `executeFunction($toolId, $arguments)` and return
`['result' => <value>, 'isError' => bool]`. An unknown `$toolId` MUST return
`['result' => ['error' => 'Unknown tool: {id}'], 'isError' => true]` without
throwing. A `\Throwable` raised by `executeFunction()` MUST be caught, logged
at error level, and returned as `['result' => ['error' => <message>], 'isError'
=> true]` — never re-thrown past the facade boundary.

Neither method MAY accept a user-impersonation, acting-user, or agent-context
parameter. `invokeTool()` MUST execute in the caller's ambient Nextcloud
request/session context only — no elevation, no substitution of a system or
service account, matching hydra ADR-034 Decision 7 and the `no impersonation`
contract `IMcpToolProvider::invokeTool()` already documents.

#### Scenario: listTools flattens a multi-function built-in tool
- **GIVEN** `ToolRegistry` holds a built-in tool registered under
  `openregister.register` whose `getFunctions()` returns 5 descriptors
- **WHEN** `ToolRegistryFacade::listTools()` is called with no whitelist
- **THEN** the result MUST include all 5 of that tool's function descriptors
  alongside descriptors from every other registered tool

#### Scenario: listTools narrows by whitelist
- **GIVEN** `ToolRegistry` holds two registered tools, `decidesk.listMeetings`
  (bridge, 1 function) and `openregister.register` (built-in, 5 functions)
- **WHEN** `ToolRegistryFacade::listTools(['decidesk.listMeetings'])` is called
- **THEN** the result MUST contain only the function descriptor from
  `decidesk.listMeetings`
- **AND** none of `openregister.register`'s 5 descriptors MUST appear

#### Scenario: listTools with an empty whitelist returns everything
- **GIVEN** `ToolRegistry` holds any number of registered tools
- **WHEN** `ToolRegistryFacade::listTools([])` (or `listTools()` with the
  default) is called
- **THEN** the result MUST include every registered tool's function descriptors
  — empty whitelist means all discovered tools allowed

#### Scenario: invokeTool delegates to the owning tool
- **GIVEN** a registered tool whose `getFunctions()` includes a descriptor
  named `decidesk_listMeetings`
- **WHEN** `ToolRegistryFacade::invokeTool('decidesk_listMeetings', ['limit' =>
  5])` is called
- **THEN** the facade MUST call that tool's `executeFunction('decidesk_listMeetings',
  ['limit' => 5])`
- **AND** MUST return `['result' => <executeFunction's return>, 'isError' =>
  false]`

#### Scenario: invokeTool accepts the dotted mcpId form
- **GIVEN** a registered bridge tool whose descriptor has `name`
  `decidesk_listMeetings` and `mcpId` `decidesk.listMeetings`
- **WHEN** `ToolRegistryFacade::invokeTool('decidesk.listMeetings', ['limit' =>
  5])` is called
- **THEN** the facade MUST resolve the dotted id to the same owning tool
- **AND** MUST call that tool's `executeFunction('decidesk.listMeetings',
  ['limit' => 5])` (the tool's own resolver handles either form)

#### Scenario: invokeTool with an unknown id returns a not-found envelope
- **GIVEN** no registered tool exposes a function named `ghost.tool`
- **WHEN** `ToolRegistryFacade::invokeTool('ghost.tool', [])` is called
- **THEN** the result MUST be `['result' => ['error' => 'Unknown tool:
  ghost.tool'], 'isError' => true]`
- **AND** no tool's `executeFunction()` MUST be called

#### Scenario: invokeTool catches a Throwable from the owning tool
- **GIVEN** a registered tool whose `executeFunction()` throws
  `RuntimeException('boom')` for function name `decidesk_listMeetings`
- **WHEN** `ToolRegistryFacade::invokeTool('decidesk_listMeetings', [])` is
  called
- **THEN** the facade MUST catch the throwable and log at error level
- **AND** MUST return `['result' => ['error' => 'boom'], 'isError' => true]`
- **AND** MUST NOT let the exception propagate past the facade

#### Scenario: No impersonation parameter exists on the public contract
- **GIVEN** the `ToolRegistryFacade` public method signatures
- **WHEN** `invokeTool()` is inspected
- **THEN** its signature MUST be exactly `(string $toolId, array $arguments):
  array` — no `$userId`, `$actingUser`, or agent parameter
- **AND** the call MUST execute using only the ambient Nextcloud request/session
  context, never a substituted identity

#### Notes
- This requirement is the OpenRegister-side half of the cross-repo chain
  narrated in `hermiq/openspec/changes/agent-engine-schemas/design.md`
  Appendix A. The consuming engine (`agent-engine-port`, Hermiq) is a separate,
  not-yet-materialized change in Hermiq's own repo.
- `listTools()`'s parameter type (`array`, not the appendix's literal
  `?string`) is a deliberate ground-truth correction against hydra ADR-035
  Decision 4's `Agent.toolWhitelist: string[]` field — see this change's
  design.md.
- The facade does not set `setAgent()` on the underlying tool instances; see
  design.md's Non-Goals for the accepted trade-off this implies for
  `AbstractTool`-derived built-in tools' view-scoped filtering.
