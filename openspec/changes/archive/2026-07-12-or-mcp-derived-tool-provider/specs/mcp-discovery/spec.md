## ADDED Requirements

### Requirement: Derived schema tools are served over the JSON-RPC MCP surface

The JSON-RPC MCP server (`POST /api/mcp`, `tools/list` + `tools/call` via `McpToolsService`) MUST serve the tools derived from the `x-openregister-mcp` schema dialect, in addition to the built-in and hand-written per-app provider tools. Derived tools MUST be namespaced `{appId}.{schema}.{verb}` and MUST obey
the hand-written-over-derived precedence rule on tool-name collision (first-wins,
with derived providers ordered after hand-written providers and self-suppressing
colliding ids). No change to the JSON-RPC envelope, session handling, or the
`tools/list` / `tools/call` method contracts is required.

#### Scenario: tools/list includes derived tools
- **GIVEN** at least one schema opted into `x-openregister-mcp`
- **WHEN** an MCP client calls `tools/list`
- **THEN** the derived `{appId}.{schema}.{verb}` tools MUST appear in the returned catalog alongside built-in tools

#### Scenario: tools/call routes to the derived provider
- **GIVEN** a derived tool `pipelinq.lead.get` in the catalog
- **WHEN** an MCP client calls `tools/call` with name `pipelinq.lead.get`
- **THEN** the call MUST route to the derived provider's `invokeTool()`
- **AND** the invocation MUST be audited per the `ai-mcp` invocation-audit requirement
