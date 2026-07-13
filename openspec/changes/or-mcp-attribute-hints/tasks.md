## 1. Attribute

- [ ] 1.1 Add optional `readOnlyHint`/`destructiveHint`/`idempotentHint` (bool) and `scope` (string) constructor params to `lib/Mcp/Attribute/McpTool.php`, default `null`; update class/constructor docblocks + `@spec` tags.

## 2. Scanner

- [ ] 2.1 `AttributeToolScanner::buildDescriptor()` forwards each set hint/scope into the descriptor additively (never defaulted).
- [ ] 2.2 `AttributeToolScanner` rejects (logs + skips) an attributed method whose `scope` is not in `McpAnnotationValidator::SCOPES`.

## 3. Provider / bridge verification

- [ ] 3.1 Confirm `AttributeToolProvider::getTools()` passes the new descriptor keys through unchanged (widen docblock return shape only if needed).
- [ ] 3.2 Verify `McpProviderBridge::getFunctions()` needs no code change (re-confirm at HEAD); note this explicitly in the PR if untouched.

## 4. Tests

- [ ] 4.1 `McpToolTest`: new params accepted/stored, default to null, independently settable.
- [ ] 4.2 `AttributeToolScannerTest`: descriptor forwards each set hint/scope; unannotated tool carries no phantom keys; unknown `scope` is rejected/warned and no tool registered.
- [ ] 4.3 `AttributeToolProviderTest`: `getTools()` carries hint/scope keys through from entries.
- [ ] 4.4 Extend `AttributeToolDualSurfaceTest`: an attribute tool's declared hints/scope are visible on both `McpToolsService::listTools()` and `ToolRegistryFacade::listTools()` (real registry→listener→bridge chain).

## 5. Verify + ship

- [ ] 5.1 Baseline phpunit run (`phpunit-unit-local.xml`, no-coverage) saved before changes; after-change run diffed by error/failure NAME SET (not just counts) — zero new.
- [ ] 5.2 Scoped `phpcs` clean on every touched file; SPDX headers intact.
- [ ] 5.3 Archive the change, sync delta into `openspec/specs/ai-mcp/spec.md`, update `CHANGELOG.md`.
