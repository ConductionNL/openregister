# A curated #[McpTool] declares its reach

## Why

`ToolReachResolver::resolve()` reads a declared `reach` off the tool descriptor and, for a curated two-segment id such as `dossiq.getWorkload`, falls back to `external` when none is declared. Array-descriptor providers (hermiq, the flow tools) can set `reach`, and `McpProviderBridge` already forwards it. Attribute-scanned apps cannot: `#[McpTool]` has no `reach` parameter, `AttributeToolScanner` forwards none, and `AttributeToolProvider::getTools()` rebuilds the descriptor from a fixed key list. So every curated attribute tool resolves to `external`, a plain read like dossiq's `getKpiOverview` always needs an explicit grant, and a read cannot be told apart from a write that mails a resident.

Asked by dossiq (change hermiq-ai-tooling, REQ-MCP-205), which wants to declare `user` on its reads and the honest reach on its writes.

## What changes

- `#[McpTool]` gains an optional `reach` parameter (`self`, `user`, `instance`, `external`).
- `AttributeToolScanner` validates it against `ToolReachResolver::ORDER` at scan time (an unrecognised value skips the tool with a warning, the same as an unrecognised `scope`) and forwards it under `ToolReachResolver::REACH_KEY` only when declared.
- `AttributeToolProvider::getTools()` carries `reach` through.

Nothing else moves: an undeclared reach still fails closed to `external`, and reach still only ever adds tools to the gated set (`ToolGrantResolver::requiresGrant()` is a union).

## Impact

- Affected spec: `ai-mcp` (REQ-ATTR-006 added).
- Code: `lib/Mcp/Attribute/McpTool.php`, `lib/Mcp/AttributeToolScanner.php`, `lib/Mcp/BuiltIn/AttributeToolProvider.php`.
- No schema, register or migration change.
