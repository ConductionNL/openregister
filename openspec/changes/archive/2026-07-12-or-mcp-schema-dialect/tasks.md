# Tasks — or-mcp-schema-dialect

- [x] Add `'x-openregister-mcp'` to `Schema::ANNOTATION_VOCABULARY` in `lib/Db/Schema.php`
- [x] Verify the generic `x-openregister-*` fold in `Schema.php` now retains the key into `configuration` (no new fold code)
- [x] Create `lib/Service/Mcp/McpAnnotationValidator.php` with SPDX docblock, mirroring `CalculationAnnotationValidator` structure
- [x] Implement `validate(array $shape): array` returning human-readable error strings (empty = valid)
- [x] Validate `enabled` is present and boolean when the block exists
- [x] Validate `tools` is an object and every key is in `{search,get,create,update,delete}`
- [x] Validate per-verb `description` (string), `scope` (enum `read|create|update|delete`), and boolean hints `readOnlyHint`/`destructiveHint`/`idempotentHint`
- [x] Validate `filters` only on `search`, is a list of strings, and each entry names a real schema property
- [x] Report unknown keys inside a verb config (typo-safety)
- [x] Add `validateMcpAnnotation()` to `SchemaMapper::cleanObject()` after `validateHandoffAnnotation()`, throwing one aggregated `Exception` on errors
- [x] Add a test fixture schema with a valid full `x-openregister-mcp` block plus one invalid variant (test input only, not a shipped register)
- [x] Unit test: valid block passes; malformed `enabled`, unknown verb, unknown filter property, misplaced `filters`, bad `scope`, non-boolean hint each fail with a clear message
- [x] Unit test: `enabled:false` and absent-block both save successfully and expose nothing
- [x] Unit test: valid block round-trips through `configuration` unchanged
- [x] Confirm no MCP tool is emitted and no serving surface changes (regression: existing `McpToolsService::listTools()` output unchanged for an opted-in schema)
- [x] Update `ai-mcp` capability spec + dialect docs: shape reference, default-OFF policy, untrusted-hint / authoritative-RBAC distinction, coarse-CRUD rationale
- [x] Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and fix any pre-existing issues touched
