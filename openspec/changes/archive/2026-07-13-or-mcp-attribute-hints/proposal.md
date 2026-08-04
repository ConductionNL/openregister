## Why

`#[McpTool]` (`lib/Mcp/Attribute/McpTool.php`) accepts exactly `?string $name`
and `?string $description` (REQ-ATTR-001), so attribute-derived SERVICE tools
emit no `readOnlyHint`/`destructiveHint`/`idempotentHint`/`scope` — while
schema-derived tools now do (PR #373). Hermiq's `ToolGrantResolver`
(hermiq PR #50) can only classify write/destructive tools from the 3-segment
`{app}.{schema}.{verb}` id suffix — its own docblock says the verb suffix of
a 3-segment derived id is the only classification signal it has, and that it
should prefer a descriptor's `destructiveHint`/`scope` if one is ever carried.
A 2-segment attribute tool like `pipelinq.createLead` is unclassifiable today,
so a destructive curated service tool cannot trip the default-deny/approval
gate — and service tools are exactly where the genuinely dangerous operations
live. This closes that governance hole (issue #374, follow-up to #369/#373).

## What Changes

- Add OPTIONAL `readOnlyHint`/`destructiveHint`/`idempotentHint` (bool) and
  `scope` (string) constructor params to `#[McpTool]`, reusing the SAME
  vocabulary already canonical in this repo:
  `McpAnnotationValidator::HINT_KEYS` for the hint keys and
  `McpAnnotationValidator::SCOPES` for the scope domain — no parallel
  vocabulary is invented.
- `AttributeToolScanner::buildDescriptor()` forwards each set param
  additively into the tool descriptor (omitted, never defaulted, when the
  author didn't set it — a fabricated `readOnlyHint: true` on an unannotated
  write tool would be a dangerous lie).
- `AttributeToolScanner` validates an explicit `scope` against
  `McpAnnotationValidator::SCOPES` at scan time; an unknown value is rejected
  (logged, tool skipped) the same way the scanner already handles other
  malformed attribute input.
- `AttributeToolProvider::getTools()` carries the new keys through to both
  consuming surfaces unchanged. `McpProviderBridge::getFunctions()` already
  forwards whatever keys a provider's descriptor sets (verified at HEAD) —
  it needs NO code change for this to reach the chat/facade surface.
- Hints/scope stay ADVISORY metadata only: OpenRegister RBAC remains the
  authoritative invoke-time gate, and a service method still owns its own
  authorization — unchanged from ADR-063 and REQ-ATTR-003.

## Capabilities

### New Capabilities

(none)

### Modified Capabilities

- `ai-mcp`: REQ-ATTR-001 (`#[McpTool]` accepted params) is modified to
  document the four new optional params; a new requirement is added stating
  that attribute-declared hints/scope MUST reach both the JSON-RPC
  (`McpToolsService::listTools()`) and chat/facade
  (`ToolRegistryFacade::listTools()` via `McpProviderBridge`) surfaces,
  advisory-only, with RBAC remaining authoritative.

## Impact

- `lib/Mcp/Attribute/McpTool.php` — new optional constructor params.
- `lib/Mcp/AttributeToolScanner.php` — forward + validate the new params in
  `buildDescriptor()`.
- `lib/Mcp/BuiltIn/AttributeToolProvider.php` — descriptor shape widened
  (additive; `getTools()` already passes descriptor keys through).
- `lib/Tool/McpProviderBridge.php` — NO code change (already forwards any
  descriptor key in `HINT_KEYS`/`scope`).
- Tests: `tests/Unit/Mcp/Attribute/McpToolTest.php`,
  `tests/Unit/Mcp/AttributeToolScannerTest.php`,
  `tests/Unit/Mcp/BuiltIn/AttributeToolProviderTest.php`,
  `tests/Unit/Mcp/AttributeToolDualSurfaceTest.php`.
- Downstream consumer unblocked: Hermiq's `ToolGrantResolver` (hermiq PR #50)
  gains a real classification signal for 2-segment attribute tools.
- References: ADR-063 (MCP as Platform Abstraction); issue #374.
