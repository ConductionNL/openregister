## ADDED Requirements

### Requirement: Attributed service tools are served over the JSON-RPC MCP surface

The JSON-RPC MCP server (`POST /api/mcp`, `tools/list` + `tools/call` via `McpToolsService`) MUST serve tools declared by the `#[McpTool]` attribute, namespaced `{appId}.{toolName}`, alongside built-in, hand-written, and schema-derived tools. `tools/call` on an attributed tool MUST route to an
in-process invocation of the owning app's method (ADR-041 — no cross-app RPC) and
MUST be audited per the `ai-mcp` invocation-audit requirement. No change to the
JSON-RPC envelope or method contracts is required.

#### Scenario: tools/list includes attributed tools
- **GIVEN** an installed app exposes a `#[McpTool]`-annotated service method
- **WHEN** an MCP client calls `tools/list`
- **THEN** the attributed `{appId}.{toolName}` tool MUST appear in the catalog

#### Scenario: tools/call invokes the owning app's method in-process
- **GIVEN** an attributed tool `pipelinq.createLead` in the catalog
- **WHEN** an MCP client calls `tools/call` with name `pipelinq.createLead`
- **THEN** the call MUST resolve and invoke pipelinq's own service method in-process
- **AND** the invocation MUST be audited
