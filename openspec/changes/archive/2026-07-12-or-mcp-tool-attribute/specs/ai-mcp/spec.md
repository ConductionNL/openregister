## ADDED Requirements

### Requirement: REQ-ATTR-001 — The `#[McpTool]` service-method attribute

OpenRegister MUST provide a net-new PHP attribute `#[McpTool]` (targeting
methods, php-mcp/server style) that marks a public service method in an installed
app for exposure as an MCP tool. The attribute MUST accept optional `name`
(default: the method name) and optional `description` (default: the method's
docblock summary). The tool `inputSchema` MUST be inferred from the method's
parameter type hints and docblock `@param` tags; the `outputSchema` MUST be
inferred from the return type / `@return` where available. The attribute MUST be
honoured only on public methods.

#### Scenario: Attribute with defaults infers name and description
- **GIVEN** a public method `createLead(string $email)` annotated `#[McpTool]` with a docblock summary
- **WHEN** the method is discovered
- **THEN** the tool name MUST default to `createLead`
- **AND** the description MUST default to the docblock summary line

#### Scenario: inputSchema is inferred from type hints and @param
- **GIVEN** a method `#[McpTool] logContactmoment(string $subject, ?string $note = null)`
- **WHEN** its descriptor is built
- **THEN** the inferred `inputSchema` MUST declare `subject` (string, required) and `note` (string, optional/nullable)

#### Scenario: Non-public attributed method is ignored
- **GIVEN** a `protected` or `private` method carrying `#[McpTool]`
- **WHEN** discovery runs
- **THEN** no tool MUST be registered for it
- **AND** a warning MUST be logged

### Requirement: REQ-ATTR-002 — Reflection scanner registers attributed tools in the same catalog

OpenRegister's MCP discovery MUST include a reflection scanner that, for each
installed app's declared scannable service classes, finds public methods carrying
`#[McpTool]` and registers one tool per attributed method with id
`{appId}.{toolName}` into the SAME catalog as schema-derived tools — served on
BOTH surfaces (the JSON-RPC `McpToolsService` and the chat/`ToolRegistry`/facade
path) via the existing `IMcpToolProvider`-shaped registration and
`McpProviderBridge`. No new serving surface is introduced.

#### Scenario: Attributed method becomes a catalog tool on both surfaces
- **GIVEN** app `pipelinq` exposes a scannable service method `#[McpTool] createLead(...)`
- **WHEN** MCP discovery runs
- **THEN** `pipelinq.createLead` MUST appear in `McpToolsService::listTools()`
- **AND** `pipelinq.createLead` MUST appear in `ToolRegistryFacade::listTools()`

#### Scenario: Attributed ids are disjoint from derived ids
- **GIVEN** app `pipelinq` has both a derived `pipelinq.lead.search` and an attributed `pipelinq.createLead`
- **WHEN** the catalog is built
- **THEN** both tools MUST coexist without collision (three-part derived id vs two-part attributed id)

#### Scenario: Attributed↔derived id collision is a discovery-time error
- **GIVEN** a developer names an attributed tool such that its id equals a derived tool id
- **WHEN** discovery runs
- **THEN** the ambiguous attributed tool MUST be rejected/skipped with a logged error
- **AND** the developer-facing message MUST indicate the id clashes with a derived tool

### Requirement: REQ-ATTR-003 — Attributed methods execute in-process in the owning app (ADR-041, no cross-app RPC)

Invoking an attributed tool `{appId}.{toolName}` MUST resolve the owning app's
service from that app's own DI container and call the attributed method
in-process, in the owning app's runtime, in the caller's ambient Nextcloud
session. There MUST be NO HTTP call, message bus, or OpenRegister-side
re-implementation of the method. OpenRegister is the registry/catalog and the
blessed inbound door (`ToolRegistryFacade`); the behaviour executes inside the
app that owns it. OpenRegister MUST NOT impersonate or elevate the acting
principal.

#### Scenario: Invocation is a direct in-process method call
- **GIVEN** an attributed tool `pipelinq.createLead` in the catalog
- **WHEN** it is invoked via `tools/call` or the facade
- **THEN** OpenRegister MUST resolve pipelinq's owning service and call `createLead(...)` in-process
- **AND** MUST NOT perform any cross-app HTTP/RPC request to reach the method

#### Scenario: The owning app's method owns its authorization
- **GIVEN** an attributed method that performs a privileged action
- **WHEN** it is invoked by an unauthorized principal
- **THEN** the owning app's own authorization/IDOR check (e.g. via `ObjectService`) MUST reject it
- **AND** OpenRegister MUST NOT have bypassed, impersonated, or elevated the principal

### Requirement: REQ-ATTR-004 — Attributed-tool invocations obey the same audit + RBAC rules as derived tools

Every attributed-tool invocation MUST write exactly one immutable audit record —
acting identity (agent non-human id when present, else NC user id + username),
`toolId` `{appId}.{toolName}`, a params digest (not raw arguments), and a result
summary — through the same immutable, hash-chained audit-trail abstraction
(`AuditTrail`/`AuditHashService`) the derived provider uses. Read, write, and
failed invocations MUST all be audited. RBAC/IDOR enforcement is the owning
method's responsibility in the ambient session, identical to the derived
provider's "no impersonation, no elevation" contract.

#### Scenario: Attributed invocation is audited identically to a derived invocation
- **GIVEN** an agent invokes `pipelinq.createLead`
- **WHEN** the tool returns
- **THEN** exactly one audit record MUST be written with the agent identity, `toolId` `pipelinq.createLead`, a params digest, and a result summary
- **AND** the record MUST be chained into the same tamper-evident audit trail as derived-tool invocations

#### Scenario: A failed attributed invocation is still audited
- **GIVEN** an attributed invocation that throws or is rejected by the owning method's authorization
- **WHEN** the `isError` envelope is returned
- **THEN** an audit record MUST still be written recording the attempt, acting identity, `toolId`, and the `isError` result summary
