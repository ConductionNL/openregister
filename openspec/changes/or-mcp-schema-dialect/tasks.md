# Tasks — or-mcp-schema-dialect

- [ ] Add `'x-openregister-mcp'` to `Schema::ANNOTATION_VOCABULARY` in `lib/Db/Schema.php`
- [ ] Verify the generic `x-openregister-*` fold in `Schema.php` now retains the key into `configuration` (no new fold code)
- [ ] Create `lib/Service/Mcp/McpAnnotationValidator.php` with SPDX docblock, mirroring `CalculationAnnotationValidator` structure
- [ ] Implement `validate(array $shape): array` returning human-readable error strings (empty = valid)
- [ ] Validate `enabled` is present and boolean when the block exists
- [ ] Validate `tools` is an object and every key is in `{search,get,create,update,delete}`
- [ ] Validate per-verb `description` (string), `scope` (enum `read|create|update|delete`), and boolean hints `readOnlyHint`/`destructiveHint`/`idempotentHint`
- [ ] Validate `filters` only on `search`, is a list of strings, and each entry names a real schema property
- [ ] Report unknown keys inside a verb config (typo-safety)
- [ ] Add `validateMcpAnnotation()` to `SchemaMapper::cleanObject()` after `validateHandoffAnnotation()`, throwing one aggregated `Exception` on errors
- [ ] Add a test fixture schema with a valid full `x-openregister-mcp` block plus one invalid variant (test input only, not a shipped register)
- [ ] Unit test: valid block passes; malformed `enabled`, unknown verb, unknown filter property, misplaced `filters`, bad `scope`, non-boolean hint each fail with a clear message
- [ ] Unit test: `enabled:false` and absent-block both save successfully and expose nothing
- [ ] Unit test: valid block round-trips through `configuration` unchanged
- [ ] Confirm no MCP tool is emitted and no serving surface changes (regression: existing `McpToolsService::listTools()` output unchanged for an opted-in schema)
- [ ] Update `ai-mcp` capability spec + dialect docs: shape reference, default-OFF policy, untrusted-hint / authoritative-RBAC distinction, coarse-CRUD rationale
- [ ] Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and fix any pre-existing issues touched
