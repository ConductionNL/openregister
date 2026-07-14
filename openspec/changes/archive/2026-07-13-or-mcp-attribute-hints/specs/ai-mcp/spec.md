## MODIFIED Requirements

### Requirement: REQ-ATTR-001 — The `#[McpTool]` service-method attribute

OpenRegister MUST provide a net-new PHP attribute `#[McpTool]` (targeting
methods, php-mcp/server style) that marks a public service method in an installed
app for exposure as an MCP tool. The attribute MUST accept optional `name`
(default: the method name) and optional `description` (default: the method's
docblock summary). The attribute MUST also accept optional
`readOnlyHint`/`destructiveHint`/`idempotentHint` (booleans, the keys in
`McpAnnotationValidator::HINT_KEYS`) and optional `scope` (a string, one of
`McpAnnotationValidator::SCOPES`) — all four default to `null`/omitted and
carry no inferred or fabricated value when the author does not set them. The
tool `inputSchema` MUST be inferred from the method's parameter type hints
and docblock `@param` tags; the `outputSchema` MUST be inferred from the
return type / `@return` where available. The attribute MUST be honoured only
on public methods.

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

#### Scenario: Hint and scope params are optional and independently settable
- **GIVEN** a method `#[McpTool(destructiveHint: true, scope: 'delete')]`
- **WHEN** the attribute is constructed
- **THEN** `destructiveHint` MUST be `true` and `scope` MUST be `'delete'`
- **AND** `readOnlyHint`/`idempotentHint` MUST remain `null`

#### Scenario: An unannotated hint/scope param stays omitted, never defaulted
- **GIVEN** a method `#[McpTool]` with none of the four new params set
- **WHEN** its descriptor is built
- **THEN** the descriptor MUST carry NO `readOnlyHint`/`destructiveHint`/`idempotentHint`/`scope` key
- **AND** no value MUST be inferred or fabricated for any of them

## ADDED Requirements

### Requirement: REQ-ATTR-005 — Attribute-declared hints/scope reach both MCP surfaces

`AttributeToolScanner` MUST forward any `readOnlyHint`, `destructiveHint`,
`idempotentHint`, and/or `scope` declared on `#[McpTool]`, additively and
unmodified, into the tool descriptor it builds, and
`AttributeToolProvider::getTools()` MUST carry those keys through
unchanged. The same descriptor keys MUST be visible on BOTH the JSON-RPC
surface (`McpToolsService::listTools()`) and the chat/facade surface
(`ToolRegistryFacade::listTools()`, reached through the existing
`McpProviderBridge`, which already forwards any descriptor key present in
`McpAnnotationValidator::HINT_KEYS`/`SCOPES` regardless of which provider
produced it). An explicit `scope` value not present in
`McpAnnotationValidator::SCOPES` MUST be rejected at scan time (logged, the
offending tool skipped) rather than registered with an invalid value.
These fields remain ADVISORY UX metadata only — OpenRegister RBAC and the
owning service method's own authorization (REQ-ATTR-003) remain the sole
authoritative invoke-time gate; no hint or scope value MUST alter
invocation behavior.

#### Scenario: Declared hints/scope appear in the descriptor
- **GIVEN** app `pipelinq` exposes `#[McpTool(readOnlyHint: false, destructiveHint: true, scope: 'delete')] deleteLead(string $id)`
- **WHEN** `AttributeToolScanner` builds the descriptor
- **THEN** the descriptor MUST include `readOnlyHint: false`, `destructiveHint: true`, and `scope: 'delete'`

#### Scenario: Hints/scope reach the JSON-RPC surface
- **GIVEN** the descriptor from the previous scenario is registered via `AttributeToolProvider`
- **WHEN** `McpToolsService::listTools()` is called
- **THEN** the `pipelinq.deleteLead` entry MUST carry the same `destructiveHint` and `scope` values

#### Scenario: Hints/scope reach the chat/facade surface
- **GIVEN** the descriptor from the first scenario is registered via `AttributeToolProvider`
- **WHEN** `ToolRegistryFacade::listTools()` is called (through `McpProviderBridge`)
- **THEN** the `pipelinq.deleteLead` function entry MUST carry the same `destructiveHint` and `scope` values

#### Scenario: Unknown scope value is rejected at scan time
- **GIVEN** a method `#[McpTool(scope: 'wipe-everything')]`
- **WHEN** `AttributeToolScanner` scans the declaring class
- **THEN** no tool MUST be registered for that method
- **AND** a warning MUST be logged naming the invalid `scope` value

#### Scenario: Hints remain advisory, not a gate
- **GIVEN** a tool descriptor carries `readOnlyHint: true`
- **WHEN** the tool is invoked and the owning service method itself denies authorization
- **THEN** the invocation MUST still fail with the service method's authorization error
- **AND** the `readOnlyHint` value MUST NOT bypass, weaken, or otherwise affect that outcome
