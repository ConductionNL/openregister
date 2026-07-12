---
retrofit: true
status: in-progress
---

# AI MCP — LLPhant Tool Bridge

## Purpose

@e2e exclude MCP adapter/backend bridge — covered by PHPUnit

OpenRegister exposes Model Context Protocol (MCP) tools to LLPhant-driven chat agents through two cooperating mechanisms: (1) an event-driven `ToolRegistry` that lets every installed Nextcloud app contribute LLPhant `ToolInterface` instances to the chat agent's tool-loop, and (2) an `McpProviderBridge` adapter that lifts `IMcpToolProvider` implementations (the per-app MCP plugin contract) into LLPhant function descriptors. The bridge handles the impedance mismatch between MCP's dot-namespaced tool ids and LLPhant/OpenAI/Ollama function-name constraints (no dots), and collapses JSON-Schema nullable types into the scalar strings LLPhant's `Parameter` accepts.

This capability sits between `mcp-discovery` (the JSON-RPC MCP server — see `openspec/specs/mcp-discovery/spec.md`) and `chat-ai` (the chat orchestrator — see `openspec/specs/chat-ai/spec.md` and the in-flight `ai-chat-companion-orchestrator` change). Where `mcp-discovery` exposes tools to external MCP clients over JSON-RPC, `ai-mcp` exposes the same tools to the local chat orchestrator via LLPhant.

## In-flight Changes (ADR-063 — MCP as Platform Abstraction)

Per **ADR-063** (hydra), apps stop shipping their own MCP tool code; OpenRegister
becomes the single MCP registry + server, deriving tools from schema declarations
and PHP attributes. Three chained changes deliver OpenRegister's half:

- `or-mcp-schema-dialect` — **shipped** (see `REQ-DIALECT-001`–`REQ-DIALECT-003`
  below). Defines the declarative `x-openregister-mcp` schema dialect (opt-in,
  coarse CRUD verb template) + its save-time validation. Emits no tools yet.
- `or-mcp-derived-tool-provider` — **in-progress**. `SchemaDerivedToolProvider`
  emits `{appId}.{schema}.{verb}` tools through the existing `IMcpToolProvider`
  ABI, feeding both this bridge and the JSON-RPC surface; hand-written > derived
  precedence; writes via `ObjectService` (RBAC intact); every invocation audited
  (EU AI Act art.12/14).
- `or-mcp-tool-attribute` — **in-progress**. Net-new `#[McpTool]` attribute +
  reflection scanner registering annotated service methods as
  `{appId}.{toolName}`, executed in-process in the owning app (ADR-041, no
  cross-app RPC), same audit + RBAC.
## Requirements
### Requirement: REQ-001 — Event-dispatched cross-app tool registration

`ToolRegistry` MUST be the single in-process registry of LLPhant `ToolInterface` instances available to chat agents. On the first read (`getTool`, `getTools`, or `getAllTools`), `ToolRegistry` MUST dispatch a typed `ToolRegistrationEvent` whose listeners may call `$event->registerTool(...)` to contribute one tool each. The dispatch MUST happen at most once per registry instance (lazy-load via a `$loaded` flag set after the first dispatch). After dispatch, subsequent accessor calls MUST NOT re-dispatch the event.

#### Scenario: Tools are loaded lazily on first access
- **GIVEN** a fresh `ToolRegistry` instance and one external app listening on `ToolRegistrationEvent`
- **WHEN** any caller invokes `getTool($id)`, `getTools($ids)`, or `getAllTools()` for the first time
- **THEN** `ToolRegistry::loadTools()` MUST dispatch a typed `ToolRegistrationEvent`
- **AND** the listener MUST have an opportunity to call `$event->registerTool(...)`
- **AND** the registry MUST log `'[ToolRegistry] Loaded tools'` at info level with the registered tool count and tool ids

#### Scenario: Subsequent accesses do not re-dispatch
- **GIVEN** a `ToolRegistry` instance whose `loadTools()` has already run
- **WHEN** any further accessor (`getTool`, `getTools`, `getAllTools`) is called
- **THEN** the event MUST NOT be dispatched again
- **AND** the result MUST reflect the same set of tools registered during the first dispatch

#### Scenario: Event delegates registration to the registry
- **GIVEN** a listener receives a `ToolRegistrationEvent`
- **WHEN** the listener calls `$event->registerTool($id, $tool, $metadata)`
- **THEN** the event MUST forward the call to the wrapped `ToolRegistry::registerTool` with the same arguments

#### Notes
- The lazy-load gate is required because `ToolRegistry` is registered in the DI container before listening apps have booted. Forcing eager load at construction would race with `Application::boot()` in peer apps.
- The dispatched event MUST be the same instance throughout — the registry passes `$this` into the event constructor so all listeners share one registration target.

### Requirement: REQ-002 — Tool id format and metadata validation

`ToolRegistry::registerTool` MUST enforce a two-part dotted id format with a lowercase Nextcloud app id on the left and a mixed-case identifier on the right (regex `^[a-z0-9_]+\.[a-zA-Z0-9_]+$`), rejecting ids that fail the pattern. Registrations MUST NOT silently overwrite — re-registering an already-known id MUST throw `InvalidArgumentException`. The metadata array MUST contain the four required keys `name`, `description`, `icon`, `app`; missing any key MUST also throw `InvalidArgumentException`.

#### Scenario: Valid dotted id with camelCase right side is accepted
- **GIVEN** the registry has not yet seen tool id `openbuild.createApp`
- **WHEN** a listener calls `registerTool('openbuild.createApp', $tool, $fullMetadata)`
- **THEN** the registry MUST store the tool under that id
- **AND** the registry MUST log `'[ToolRegistry] Tool registered'` at info level with id, name, app fields

#### Scenario: Id without a dot is rejected
- **GIVEN** any registration attempt
- **WHEN** the id supplied is `mytool` (no dot)
- **THEN** `registerTool` MUST throw `InvalidArgumentException` with message `"Invalid tool ID format: mytool. Must be 'app_name.tool_name'"`

#### Scenario: Uppercase left-side app id is rejected
- **GIVEN** any registration attempt
- **WHEN** the id supplied is `MyApp.tool` (uppercase before the dot)
- **THEN** `registerTool` MUST throw `InvalidArgumentException` — the left side MUST be lowercase since it maps to a Nextcloud app id

#### Scenario: Duplicate registration is rejected
- **GIVEN** the registry already holds tool id `decidesk.listMeetings`
- **WHEN** a second listener attempts to register the same id
- **THEN** `registerTool` MUST throw `InvalidArgumentException` with message `"Tool already registered: decidesk.listMeetings"`

#### Scenario: Missing metadata keys are rejected
- **GIVEN** a registration attempt with valid id and tool instance
- **WHEN** the metadata array is missing any of `name`, `description`, `icon`, `app`
- **THEN** `registerTool` MUST throw `InvalidArgumentException` with message `"Missing required metadata field: {fieldName}"` for the first missing field detected

#### Notes
- The right-hand side of the dotted id accepts both camelCase and snake_case to match the MCP convention used by per-app providers (e.g., `decidesk.listRecentMeetings`, `openbuild.create_app`).
- The validation order is: id format → duplicate check → metadata fields. The duplicate check uses `null`-coalescence rather than `isset()` to treat explicit `null` entries the same as missing entries.

### Requirement: REQ-003 — Id-keyed agent tool selection with warn-and-skip

`ToolRegistry::getTools(array $ids)` MUST return an associative map `[id => ToolInterface]` containing only the tools whose ids are known to the registry. For any requested id that is not registered, the registry MUST log a `'[ToolRegistry] Tool not found'` warning with the missing id and MUST omit the entry from the result (no exception). `getTool($id)` MUST return `null` when the id is unknown (no exception). `getAllTools()` MUST return the full metadata map keyed by id without including the `ToolInterface` instances themselves.

#### Scenario: Multi-id selection returns the known intersection
- **GIVEN** the registry has tools `decidesk.listMeetings` and `openbuild.createApp` registered
- **WHEN** an agent configuration calls `getTools(['decidesk.listMeetings', 'openbuild.createApp', 'ghost.gone'])`
- **THEN** the result MUST contain exactly two entries: the two known tools
- **AND** the registry MUST log `'[ToolRegistry] Tool not found'` at warning level with `id: 'ghost.gone'`
- **AND** no exception MUST be thrown

#### Scenario: Unknown single-id lookup returns null
- **GIVEN** the registry does NOT hold tool id `unknown.thing`
- **WHEN** a caller invokes `getTool('unknown.thing')`
- **THEN** the result MUST be `null` (no exception)

#### Scenario: All-tools listing returns metadata-only
- **GIVEN** the registry holds two tools
- **WHEN** `getAllTools()` is called
- **THEN** the result MUST be a map keyed by id where each value is the registered metadata array
- **AND** the result MUST NOT contain any `ToolInterface` instance

#### Notes
- Graceful degradation is intentional: agent configurations may reference tools whose owning app was disabled between conversations. The chat agent MUST still respond, minus the missing tool.
- `getAllTools()` is the surface used by the agent-configuration UI to render the tool picker; the `ToolInterface` instances themselves are not serialisable.

### Requirement: REQ-004 — IMcpToolProvider to LLPhant ToolInterface adaptation

`McpProviderBridge` MUST wrap a single `IMcpToolProvider` and expose it through the LLPhant `ToolInterface`. `getName()` MUST return the provider's `getAppId()` so all MCP tools cluster under one tool-group name. `getDescription()` MUST return a fixed-format string `"MCP-bridged tools from the {appId} app."`. `getFunctions()` MUST iterate `$provider->getTools()` and produce one LLPhant function descriptor per MCP descriptor, dropping descriptors with empty `id`. When the bridge has been narrowed via `setOnlyMcpId(...)`, `getFunctions()` MUST return at most one descriptor — the one matching the whitelisted MCP id. JSON-Schema nullable types (`['type' => ['string', 'null']]`) MUST be collapsed to a scalar string type via `sanitiseSchema` + `collapseType` before being passed through to LLPhant.

#### Scenario: Bridge surfaces all provider tools as LLPhant functions
- **GIVEN** an `IMcpToolProvider` whose `getAppId()` returns `decidesk` and whose `getTools()` returns 3 descriptors
- **WHEN** an LLPhant tool-loop calls `bridge->getFunctions()`
- **THEN** the result MUST contain 3 function descriptors, one per source MCP descriptor
- **AND** each LLPhant descriptor MUST contain `name`, `mcpId`, `description`, `parameters` keys
- **AND** `mcpId` MUST preserve the raw dotted MCP id verbatim

#### Scenario: Bridge can be narrowed to a single MCP id
- **GIVEN** a bridge wrapping a provider with 3 tool descriptors
- **WHEN** `bridge->setOnlyMcpId('decidesk.listMeetings')` has been called, then `getFunctions()` invoked
- **THEN** the result MUST contain exactly 1 function descriptor whose `mcpId` equals `'decidesk.listMeetings'`
- **AND** the other 2 descriptors MUST be omitted from the result

#### Scenario: Descriptors with empty id are skipped
- **GIVEN** a provider whose `getTools()` includes one descriptor with `id` equal to `''` or unset
- **WHEN** `getFunctions()` is called
- **THEN** the empty-id descriptor MUST be omitted from the result with no exception

#### Scenario: Name and description follow the fixed format
- **GIVEN** a provider whose `getAppId()` returns `opencatalogi`
- **WHEN** `bridge->getName()` and `bridge->getDescription()` are called
- **THEN** `getName()` MUST return `'opencatalogi'`
- **AND** `getDescription()` MUST return `'MCP-bridged tools from the opencatalogi app.'`

#### Scenario: Nullable JSON-Schema type is collapsed
- **GIVEN** a provider whose `inputSchema` declares `properties.name.type = ['string', 'null']`
- **WHEN** `bridge->getFunctions()` builds the LLPhant descriptor
- **THEN** `sanitiseSchema` MUST replace the array with the scalar string `'string'`
- **AND** the LLPhant `Parameter` constructor MUST receive a scalar type (no TypeError)

#### Notes
- The `setOnlyMcpId` narrowing exists because `ToolRegistry` enforces a two-part `{app}.{tool}` id format that cannot accept the bare app id. The registration listener therefore creates one bridge instance per `(provider, function)` pair and narrows each instance with `setOnlyMcpId` so the dotted MCP id can be registered under the registry's id format.
- `setAgent` attaches an optional `Agent` context to the bridge but does NOT alter invocation behavior in the current implementation — it is exposed for future per-agent permission scoping. Observed-but-currently-unused: flagged for future tightening.
- LLPhant's `Parameter` constructor requires a scalar string `type`. JSON-Schema `nullable` types (e.g., `['string', 'null']`) MUST be collapsed via `sanitiseSchema` → `collapseType` before being passed through, picking the first non-`null` string type and falling back to `'string'`. This MUST be applied recursively to `properties[].type` arrays.

### Requirement: REQ-005 — Dotted-id to safe-name round-trip and invocation forwarding

The bridge MUST expose each MCP tool under two function names: the raw dotted MCP id (e.g., `decidesk.createMeeting`) AND a safe alias produced by replacing every `.` with `_` (e.g., `decidesk_createMeeting`). LLM tool-call invocations using either form MUST route back to the same `IMcpToolProvider::invokeTool($mcpId, $arguments)` call. The bridge's `__call(functionName, args)` magic method MUST be the dispatch entry from LLPhant's `$toolInstance->{$functionName}(...$args)` call site; it MUST flatten single-array argument lists to the MCP arguments object shape and forward to `executeFunction`. When the provider throws, the bridge MUST log at error level and return a structured envelope `['isError' => true, 'error' => 'internal_error', 'message' => $exceptionMessage]`. When the function name cannot be resolved, the bridge MUST return `['isError' => true, 'error' => 'unknown_function', 'message' => 'No MCP tool registered for function: {name}']`.

#### Scenario: Raw MCP id routes back to the provider
- **GIVEN** a bridge wrapping a provider with descriptor id `decidesk.listMeetings`
- **WHEN** LLPhant invokes `bridge('decidesk.listMeetings', ['limit' => 5])` via `__call` or `executeFunction`
- **THEN** the bridge MUST resolve the MCP id back to `'decidesk.listMeetings'`
- **AND** MUST call `$provider->invokeTool('decidesk.listMeetings', ['limit' => 5])`
- **AND** MUST return the provider's result verbatim

#### Scenario: Safe alias routes back to the same MCP id
- **GIVEN** the same bridge with descriptor id `decidesk.listMeetings`
- **WHEN** LLPhant invokes `bridge('decidesk_listMeetings', ['limit' => 5])`
- **THEN** `resolveMcpId('decidesk_listMeetings')` MUST return `'decidesk.listMeetings'`
- **AND** the call MUST forward to `$provider->invokeTool('decidesk.listMeetings', ['limit' => 5])`

#### Scenario: Provider throwable becomes a structured isError envelope
- **GIVEN** a provider whose `invokeTool` throws `RuntimeException('boom')`
- **WHEN** `bridge->executeFunction('decidesk.listMeetings', $args)` is called
- **THEN** the bridge MUST catch the throwable
- **AND** MUST log at error level with message `'[McpProviderBridge] Provider invocation failed'` and context containing `function`, `mcpId`, `error`
- **AND** MUST return `['isError' => true, 'error' => 'internal_error', 'message' => 'boom']`
- **AND** MUST NOT re-throw

#### Scenario: Unresolvable function name returns unknown_function envelope
- **GIVEN** a bridge wrapping a provider with NO descriptor matching `'ghost.tool'`
- **WHEN** `bridge->executeFunction('ghost.tool', [])` is called
- **THEN** the result MUST be `['isError' => true, 'error' => 'unknown_function', 'message' => 'No MCP tool registered for function: ghost.tool']`
- **AND** the provider's `invokeTool` MUST NOT be called

#### Scenario: __call flattens single-array argument list
- **GIVEN** a bridge wrapping any provider
- **WHEN** LLPhant invokes the bridge via `$bridge->decidesk_listMeetings(['limit' => 5])` (one argument, an associative array)
- **THEN** the `__call` magic method MUST detect the single-array shape
- **AND** MUST pass the inner array as the MCP `$arguments` map (not a positional list)

#### Notes
- The safe-alias indirection exists because OpenAI and Ollama function-name validators reject `.` (and several Ollama models reject `:` too). LLPhant inherits those validators, so even though MCP ids are dotted by spec, the chat path must round-trip through an underscore alias. The mapping is bidirectional and lossless when the raw id contains no underscores; ambiguity is acceptable because the bridge is per-provider and providers don't typically use both `.` and `_` in the same id.
- The `isError` envelope shape mirrors `McpToolsService::callTool`'s soft-error shape so downstream consumers (the SSE streaming wrapper, the chat orchestrator, the LLM follow-up message) can detect failures uniformly.
- Observed-but-suspicious: the `'unknown_function'` and `'internal_error'` envelopes use different shapes from `McpToolsService::callTool` (which wraps errors in a `content` array). This is an inconsistency that should be reconciled in a future spec — the bridge envelope is currently consumed by LLPhant's tool result path, not by an MCP client, so the divergence is not user-visible.

### Requirement: REQ-DIALECT-001 — The `x-openregister-mcp` schema dialect

OpenRegister MUST recognise a top-level `x-openregister-mcp` annotation key on
each schema, a member of the `x-openregister-*` dialect family (ADR-031), used to
declare — per schema, opt-in — which coarse CRUD MCP tools that schema exposes.
The key MUST be added to `Schema::ANNOTATION_VOCABULARY` so it is folded into the
schema's `configuration` on import rather than dropped as an unknown key. This
change defines and validates the declaration only; it MUST NOT emit any MCP tool
or alter any serving surface (that is the `or-mcp-derived-tool-provider` change).

The dialect object shape is:

- `enabled` (boolean, REQUIRED when the block is present) — the opt-in gate.
- `tools` (object, OPTIONAL) — keys MUST be a subset of the fixed verb set
  `{search, get, create, update, delete}`. Per-verb value is an object with
  optional `description` (string), `scope` (enum `read|create|update|delete`),
  and boolean MCP annotation hints `readOnlyHint` / `destructiveHint` /
  `idempotentHint`. The `search` verb additionally accepts `filters` (a list of
  strings, each naming an existing property on the schema).

The default posture MUST be OFF: a schema with no `x-openregister-mcp` block, or
with `enabled:false`, exposes no MCP tools.

#### Scenario: Dialect key is retained into configuration on import
- **GIVEN** a register seed schema carrying a top-level `x-openregister-mcp` block
- **WHEN** the schema is imported
- **THEN** `x-openregister-mcp` MUST be folded into the schema's `configuration`
- **AND** it MUST NOT appear in the dropped-unknown-key warning emitted by `SchemaMapper::logDroppedAnnotationKeys()`

#### Scenario: Default OFF — absent block exposes nothing
- **GIVEN** a schema with no `x-openregister-mcp` key
- **WHEN** the schema is saved
- **THEN** the save MUST succeed
- **AND** the schema's `configuration` MUST NOT contain an `x-openregister-mcp` entry

#### Scenario: enabled:false is a valid opt-out
- **GIVEN** a schema whose `x-openregister-mcp` block is `{ "enabled": false }`
- **WHEN** the schema is saved
- **THEN** the save MUST succeed
- **AND** the block MUST be stored verbatim in `configuration`

### Requirement: REQ-DIALECT-002 — Save-time validation of the dialect shape

OpenRegister MUST validate the `x-openregister-mcp` block at schema-save time via
a dedicated `McpAnnotationValidator`, invoked from `SchemaMapper::cleanObject()`
alongside the sibling dialect validators. A malformed block MUST fail the schema
save with a single aggregated, human-readable error message, consistent with the
existing `x-openregister-*` validators. The validator MUST check *types and
shape only* — it MUST NOT treat any MCP hint value as a security decision.

#### Scenario: enabled must be boolean
- **GIVEN** a schema whose `x-openregister-mcp` block sets `"enabled": "yes"`
- **WHEN** the schema is saved
- **THEN** the save MUST fail with an error naming the schema and the `enabled` type violation

#### Scenario: Unknown verb key is rejected
- **GIVEN** a `tools` object containing a key `list` (not in `{search,get,create,update,delete}`)
- **WHEN** the schema is saved
- **THEN** the save MUST fail with an error identifying the unrecognised verb `list`

#### Scenario: search filter must reference an existing property
- **GIVEN** a `search` verb whose `filters` lists `assignee`, but the schema has no `assignee` property
- **WHEN** the schema is saved
- **THEN** the save MUST fail with an error naming the unknown filter property `assignee`

#### Scenario: filters are permitted only on the search verb
- **GIVEN** a `create` verb config that includes a `filters` array
- **WHEN** the schema is saved
- **THEN** the save MUST fail with an error stating `filters` is valid on `search` only

#### Scenario: scope must be a known enum value
- **GIVEN** a verb config whose `scope` is `"admin"`
- **WHEN** the schema is saved
- **THEN** the save MUST fail with an error naming the invalid `scope` value

#### Scenario: MCP hints are validated by type, not trusted by value
- **GIVEN** a `delete` verb declaring `"destructiveHint": false`
- **WHEN** the schema is saved
- **THEN** the save MUST succeed (the boolean type is valid)
- **AND** the specification MUST record that the authoritative destructiveness gate at invoke time is OpenRegister RBAC, not this hint

#### Scenario: A well-formed full block saves and round-trips
- **GIVEN** a schema with `enabled:true` and all five verbs configured with valid `description`, `scope`, hints, and (for `search`) `filters` referencing real properties
- **WHEN** the schema is saved and re-read
- **THEN** the save MUST succeed
- **AND** the `x-openregister-mcp` block MUST be returned unchanged from `configuration`

### Requirement: REQ-DIALECT-003 — Coarse CRUD template, not per-endpoint

The dialect MUST express only a fixed, coarse five-verb CRUD template per schema
(`search`, `get`, `create`, `update`, `delete`) reusing the schema itself as the
tool input/output schema. It MUST NOT provide a mechanism to declare arbitrary
per-REST-endpoint tools. Non-CRUD, behaviour-specific tools are out of scope for
this declarative dialect and are the domain of the `#[McpTool]` service attribute
(`or-mcp-tool-attribute`).

#### Scenario: The verb set is closed
- **GIVEN** any `x-openregister-mcp.tools` object
- **WHEN** it is validated
- **THEN** only keys within `{search, get, create, update, delete}` MUST be accepted
- **AND** there MUST be no supported syntax for declaring a custom-named CRUD tool in this change

