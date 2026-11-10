## MODIFIED Requirements

### Requirement: REQ-002 — Tool id format and metadata validation

`ToolRegistry::registerTool` MUST enforce a dotted id format with a lowercase
Nextcloud app id as the first segment and one or more mixed-case identifier
segments after it (regex `^[a-z0-9_]+(\.[a-zA-Z0-9_]+)+$`), rejecting ids that
fail the pattern. Two-segment ids (`{appId}.{toolName}`, e.g.
`openbuild.createApp`) and three-segment schema-derived ids
(`{appId}.{schema}.{verb}`, e.g. `pipelinq.lead.search` — ADR-063 chain 2/3)
are both valid. Registrations MUST NOT silently overwrite — re-registering an
already-known id MUST throw `InvalidArgumentException`. The metadata array MUST
contain the four required keys `name`, `description`, `icon`, `app`; missing
any key MUST also throw `InvalidArgumentException`. The same widened pattern
MUST govern `ToolRegistrationListener`'s bridge-side id gate so a derived
provider's tools are not silently dropped from the chat surface.

#### Scenario: Valid dotted id with camelCase right side is accepted
- **GIVEN** the registry has not yet seen tool id `openbuild.createApp`
- **WHEN** a listener calls `registerTool('openbuild.createApp', $tool, $fullMetadata)`
- **THEN** the registry MUST store the tool under that id
- **AND** the registry MUST log `'[ToolRegistry] Tool registered'` at info level with id, name, app fields

#### Scenario: Three-segment schema-derived id is accepted
- **GIVEN** the registry has not yet seen tool id `pipelinq.lead.search`
- **WHEN** a listener calls `registerTool('pipelinq.lead.search', $tool, $fullMetadata)`
- **THEN** the registry MUST store the tool under that id

#### Scenario: Malformed id is rejected
- **GIVEN** an id with no dot (`invalid-format`) or an uppercase app segment (`App.Tool`)
- **WHEN** `registerTool` is called with it
- **THEN** the registry MUST throw `InvalidArgumentException` naming the invalid format

#### Scenario: Duplicate registration is rejected
- **GIVEN** a tool already registered under an id
- **WHEN** `registerTool` is called again with the same id
- **THEN** the registry MUST throw `InvalidArgumentException` (`Tool already registered`)

#### Scenario: Missing metadata key is rejected
- **GIVEN** metadata lacking any of `name`, `description`, `icon`, `app`
- **WHEN** `registerTool` is called
- **THEN** the registry MUST throw `InvalidArgumentException` naming the missing field

## ADDED Requirements

### Requirement: REQ-DERIVED-001 — SchemaDerivedToolProvider emits declarative CRUD tools through the existing ABI

OpenRegister MUST provide a `SchemaDerivedToolProvider` implementing the existing
`IMcpToolProvider` ABI (`lib/Mcp/IMcpToolProvider.php`) that reads every schema's
validated `x-openregister-mcp` block and, for each schema with `enabled:true`,
emits one tool per declared verb with id `{appId}.{schema}.{verb}`. The schema
itself MUST be reused as the tool `inputSchema` (and as the element shape of
`outputSchema`/`structuredContent` for read verbs, MCP 2025-06-18). The ABI MUST
NOT change: derived tools are served through `getTools()` / `invokeTool()` like
any provider. To preserve the ABI's "id prefix MUST equal `getAppId()`"
invariant, one derived provider instance MUST be registered per owning app that
has at least one opted-in schema, its `getAppId()` returning that owning app id.

#### Scenario: Opted-in schema yields one tool per declared verb
- **GIVEN** a schema `lead` in app `pipelinq` with `x-openregister-mcp.enabled:true` and all five verbs declared
- **WHEN** the derived provider's `getTools()` runs
- **THEN** it MUST return descriptors with ids `pipelinq.lead.search`, `pipelinq.lead.get`, `pipelinq.lead.create`, `pipelinq.lead.update`, `pipelinq.lead.delete`
- **AND** each descriptor id MUST satisfy the ABI's `{getAppId()}.` prefix check

#### Scenario: Disabled and absent schemas emit nothing
- **GIVEN** a schema with `x-openregister-mcp.enabled:false` and another schema with no `x-openregister-mcp` block
- **WHEN** `getTools()` runs
- **THEN** neither schema MUST contribute any tool descriptor

#### Scenario: tools subset narrows the emitted verbs
- **GIVEN** a schema whose `x-openregister-mcp.tools` declares only `search` and `get`
- **WHEN** `getTools()` runs
- **THEN** exactly two descriptors (`.search`, `.get`) MUST be emitted for that schema

### Requirement: REQ-DERIVED-002 — Both serving surfaces are fed from one derivation

The derived tools MUST appear on BOTH MCP serving surfaces from a single
provider set: the JSON-RPC `McpToolsService` (`tools/list` / `tools/call`) and
the chat/LLPhant path via `ToolRegistry` + `McpProviderBridge`, readable through
`ToolRegistryFacade::listTools()` / `invokeTool()`. The same invocation path and
the same precedence MUST govern both surfaces.

#### Scenario: A derived tool is listed on the JSON-RPC surface
- **GIVEN** an opted-in schema `pipelinq.lead`
- **WHEN** an MCP client calls `tools/list` via `McpToolsService`
- **THEN** `pipelinq.lead.search` (and the other declared verbs) MUST be present in the catalog

#### Scenario: The same derived tool is visible to the chat facade
- **GIVEN** the same opted-in schema
- **WHEN** a consumer calls `ToolRegistryFacade::listTools()`
- **THEN** the derived tool MUST be present (bridged via `McpProviderBridge`, dotted id and `_`-alias forms both resolving to the same tool)

### Requirement: REQ-DERIVED-003 — Hand-written provider tools take precedence over derived tools

On a tool-id collision, a hand-written per-app `IMcpToolProvider` tool MUST win
over the derived tool, on both surfaces, so apps migrate schema-by-schema without
breakage. The derived provider MUST be consulted after per-app providers AND MUST
self-suppress any derived tool whose id a hand-written provider already exposes,
so the derived duplicate is absent from `tools/list` rather than merely shadowed.

#### Scenario: Hand-written tool wins on collision
- **GIVEN** app `pipelinq` ships a hand-written provider exposing `pipelinq.lead.search` AND its `lead` schema is opted into the dialect
- **WHEN** the catalog is built on either surface
- **THEN** the hand-written `pipelinq.lead.search` MUST be the one served
- **AND** the derived `pipelinq.lead.search` MUST NOT appear as a duplicate

#### Scenario: Non-colliding derived verbs still emit
- **GIVEN** the same app hand-writes only `pipelinq.lead.search` while opting all five verbs into the dialect
- **WHEN** the catalog is built
- **THEN** `pipelinq.lead.get/create/update/delete` MUST be served as derived tools
- **AND** only `pipelinq.lead.search` comes from the hand-written provider

### Requirement: REQ-DERIVED-004 — Search verb: filters, pagination, projection, truncation

The derived `search` tool MUST accept only the query filters declared in
`x-openregister-mcp.tools.search.filters`, MUST support pagination with a bounded
default and maximum page size, MUST support optional field projection, and MUST
apply truncation defaults so a search cannot return an unbounded, token-exploding
payload. The response MUST carry enough paging metadata (total count / has-more)
for an agent to page deliberately.

#### Scenario: Only declared filters are honoured
- **GIVEN** a `search` verb whose `filters` are `["status","assignee"]`
- **WHEN** an agent calls the tool with `{ "status": "open", "unknownField": "x" }`
- **THEN** the `status` filter MUST be applied
- **AND** the undeclared `unknownField` MUST NOT silently filter results (rejected with a tool error, per design)

#### Scenario: Pagination is bounded
- **GIVEN** a `search` call with no `pageSize`
- **WHEN** the tool executes
- **THEN** a bounded default page size MUST be applied
- **AND** a request for a page size above the hard maximum MUST be clamped to the maximum

#### Scenario: Results are truncated to keep token cost sane
- **GIVEN** a matching set larger than one page
- **WHEN** the tool returns
- **THEN** the payload MUST be limited to the page size
- **AND** the response MUST indicate more results exist (has-more / total count)

### Requirement: REQ-DERIVED-005 — Writes go through ObjectService with RBAC intact

The derived `create` / `update` / `delete` tools MUST perform their writes
through `ObjectService` in the caller's ambient Nextcloud session — no system or
service account, no impersonation, no IDOR bypass. The dialect `scope` and the
MCP hints are advisory only; the authoritative authorization gate is
`ObjectService`'s RBAC enforcement, identical to the REST/UI path.

#### Scenario: Write is authorized exactly as the REST path
- **GIVEN** an acting identity permitted to create `pipelinq.lead` objects
- **WHEN** the `pipelinq.lead.create` tool is invoked
- **THEN** the create MUST be performed via `ObjectService`
- **AND** the object MUST be created with the same RBAC/ownership semantics as a REST create

#### Scenario: Unauthorized write fails, no bypass
- **GIVEN** an acting identity NOT permitted to delete a given `pipelinq.lead` object
- **WHEN** `pipelinq.lead.delete` is invoked on that object id
- **THEN** the invocation MUST fail with the same authorization error as the REST path
- **AND** the error MUST be returned in the tool's `isError` envelope, not silently succeed

### Requirement: REQ-DERIVED-006 — Every invocation is audited (EU AI Act art.12/14)

EVERY tool invocation routed through the derived provider MUST write exactly one
immutable audit record capturing: the acting identity (the agent's registered
non-human identity when present, else the NC user id + username), the full
`toolId`, a digest of the parameters (not raw argument values), and a result
summary (object count / affected ids / `isError` + error class), plus a
timestamp. The record MUST be written through OpenRegister's existing immutable,
hash-chained audit-trail abstraction (`AuditTrail` / `AuditHashService`, ADR-022)
so it is tamper-evident and consumable by an oversight surface.

#### Scenario: A read invocation is audited
- **GIVEN** an agent invokes `pipelinq.lead.search`
- **WHEN** the tool returns
- **THEN** exactly one audit record MUST be written with the agent identity, `toolId` `pipelinq.lead.search`, a params digest, and a result summary
- **AND** the record MUST be chained into the tamper-evident audit trail

#### Scenario: A params digest, not raw params, is stored
- **GIVEN** a `create` invocation whose arguments contain personal data
- **WHEN** the audit record is written
- **THEN** the stored parameter field MUST be a digest/summary
- **AND** the raw argument values MUST NOT be persisted verbatim in the audit record

#### Scenario: A failed invocation is still audited
- **GIVEN** an invocation that fails authorization
- **WHEN** the `isError` envelope is returned
- **THEN** an audit record MUST still be written recording the attempt, the acting identity, the `toolId`, and the `isError` result summary
