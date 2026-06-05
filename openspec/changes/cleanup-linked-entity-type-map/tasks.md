# Tasks: Cleanup — Remove LinkedEntityService::TYPE_COLUMN_MAP

## Pre-removal verification

- [x] Grep ConductionNL org for `TYPE_COLUMN_MAP` and `VALID_LINKED_TYPES` — list any remaining references
- [x] For each remaining reference, open a migration issue pointing to `IntegrationRegistry::listIds()`
- [x] Wait for migration issues to close before proceeding with the removal commit
- [x] Confirm all 5 built-in providers (files, notes, tasks, tags, audit-trail) are in production and stable

## Removal

- [x] Remove `const TYPE_COLUMN_MAP` from `lib/Service/LinkedEntityService.php`
- [x] Remove `const VALID_LINKED_TYPES` from `lib/Db/Schema.php` (if present)
- [x] Update any `@deprecated` docblocks to reflect removal
- [x] Search-and-remove any import / reference patterns that the constants previously satisfied

## Verification

- [x] PHPCS / PHPMD / PHPStan / Psalm strict pass
- [x] Backwards-compat snapshot tests on `CnObjectSidebar` pass
- [x] `Schema::validateLinkedTypesValue()` tests still pass (registry path)
- [x] No regression in integration discovery via `/api/integrations` or OCS capabilities

## Documentation

- [x] Update developer docs / READMEs mentioning either constant
- [x] CHANGELOG note: "BREAKING for anyone reading private API constants; no public API change"
