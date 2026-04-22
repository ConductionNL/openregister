# Tasks: Cleanup — Remove LinkedEntityService::TYPE_COLUMN_MAP

## Pre-removal verification

- [ ] Grep ConductionNL org for `TYPE_COLUMN_MAP` and `VALID_LINKED_TYPES` — list any remaining references
- [ ] For each remaining reference, open a migration issue pointing to `IntegrationRegistry::listIds()`
- [ ] Wait for migration issues to close before proceeding with the removal commit
- [ ] Confirm all 5 built-in providers (files, notes, tasks, tags, audit-trail) are in production and stable

## Removal

- [ ] Remove `const TYPE_COLUMN_MAP` from `lib/Service/LinkedEntityService.php`
- [ ] Remove `const VALID_LINKED_TYPES` from `lib/Db/Schema.php` (if present)
- [ ] Update any `@deprecated` docblocks to reflect removal
- [ ] Search-and-remove any import / reference patterns that the constants previously satisfied

## Verification

- [ ] PHPCS / PHPMD / PHPStan / Psalm strict pass
- [ ] Backwards-compat snapshot tests on `CnObjectSidebar` pass
- [ ] `Schema::validateLinkedTypesValue()` tests still pass (registry path)
- [ ] No regression in integration discovery via `/api/integrations` or OCS capabilities

## Documentation

- [ ] Update developer docs / READMEs mentioning either constant
- [ ] CHANGELOG note: "BREAKING for anyone reading private API constants; no public API change"
