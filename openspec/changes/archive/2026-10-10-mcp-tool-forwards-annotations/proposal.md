# A curated #[McpTool] forwards free-form annotations

## Why

hermiq's conversational intake may only call a tool that its owning app marks `citizenIntake`. An array-descriptor provider can put an `annotations` map on its descriptor, but an attribute-scanned app cannot: `#[McpTool]` has no way to carry a mark, `AttributeToolScanner` builds none, `AttributeToolProvider::getTools()` rebuilds the descriptor from a fixed key list and `McpProviderBridge` forwards a fixed key list too. So no curated tool can ever reach hermiq's intake surface.

Asked by dossiq (change ai-features-on-the-case-consume-hermiq, tasks 5.1 and 5.2) under decision 177: OpenRegister forwards a free-form `annotations` map from `#[McpTool]`, at the same copy points as `reach`.

## What changes

- `#[McpTool]` gains an optional `annotations` map (string key to scalar).
- `AttributeToolScanner` refuses a malformed map at scan time (an integer or empty key, or a non-scalar value, skips the tool with a warning, like an unrecognised `scope` or `reach`) and forwards a non-empty map under `annotations`.
- `AttributeToolProvider::getTools()` and `McpProviderBridge::getFunctions()` carry `annotations` through.

OpenRegister reads none of the marks. A mark is a claim, not a grant: grant resolution, reach and RBAC are untouched.

## Impact

- Affected spec: `ai-mcp` (REQ-ATTR-007 added).
- Code: `lib/Mcp/Attribute/McpTool.php`, `lib/Mcp/AttributeToolScanner.php`, `lib/Mcp/BuiltIn/AttributeToolProvider.php`, `lib/Tool/McpProviderBridge.php`.
- No schema, register or migration change. Stacked on #4546 (reach), which touches the same lines.
