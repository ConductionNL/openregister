## 1. Attribute

- [x] 1.1 Add optional `readOnlyHint`/`destructiveHint`/`idempotentHint` (bool) and `scope` (string) constructor params to `lib/Mcp/Attribute/McpTool.php`, default `null`; update class/constructor docblocks + `@spec` tags.

## 2. Scanner

- [x] 2.1 `AttributeToolScanner::buildDescriptor()` forwards each set hint/scope into the descriptor additively (never defaulted).
- [x] 2.2 `AttributeToolScanner` rejects (logs + skips) an attributed method whose `scope` is not in `McpAnnotationValidator::SCOPES`.

## 3. Provider / bridge verification

- [x] 3.1 Confirm `AttributeToolProvider::getTools()` passes the new descriptor keys through unchanged (widen docblock return shape only if needed). (Found `getTools()` explicitly whitelisted descriptor keys and would have SILENTLY DROPPED the new fields — fixed to forward `HINT_KEYS`/`scope` additively, mirroring `SchemaDerivedToolProvider`.)
- [x] 3.2 Verify `McpProviderBridge::getFunctions()` needs no code change (re-confirm at HEAD); note this explicitly in the PR if untouched. (Confirmed: it already loops `McpAnnotationValidator::HINT_KEYS` and checks `scope` generically on any descriptor. Untouched.)

## 4. Tests

- [x] 4.1 `McpToolTest`: new params accepted/stored, default to null, independently settable.
- [x] 4.2 `AttributeToolScannerTest`: descriptor forwards each set hint/scope; unannotated tool carries no phantom keys; unknown `scope` is rejected/warned and no tool registered.
- [x] 4.3 `AttributeToolProviderTest`: `getTools()` carries hint/scope keys through from entries.
- [x] 4.4 Extend `AttributeToolDualSurfaceTest`: an attribute tool's declared hints/scope are visible on both `McpToolsService::listTools()` and `ToolRegistryFacade::listTools()` (real registry→listener→bridge chain).

## 5. Verify + ship

- [x] 5.1 Baseline phpunit run (`phpunit-unit-local.xml`, no-coverage) saved before changes; after-change run diffed by error/failure NAME SET (not just counts) — zero new. (Baseline 14282 tests/1203 errors/10 failures; after 14294 tests/1203 errors/10 failures — the +12 are new passing tests; `diff` of the two sorted unique error+failure name sets is empty.)
- [x] 5.2 Scoped `phpcs` clean on every touched file; SPDX headers intact. (0 errors on all 3 touched `lib/` files; only pre-existing warnings, byte-identical to the pre-change versions.)
- [x] 5.3 Archive the change, sync delta into `openspec/specs/ai-mcp/spec.md`, update `CHANGELOG.md`.
