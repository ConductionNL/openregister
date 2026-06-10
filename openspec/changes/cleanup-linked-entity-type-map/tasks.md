# Tasks: Cleanup — Remove LinkedEntityService::TYPE_COLUMN_MAP

## Pre-removal verification

- [~] Grep ConductionNL org for `TYPE_COLUMN_MAP` and `VALID_LINKED_TYPES` — list any remaining references — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] For each remaining reference, open a migration issue pointing to `IntegrationRegistry::listIds()` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Wait for migration issues to close before proceeding with the removal commit — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Confirm all 5 built-in providers (files, notes, tasks, tags, audit-trail) are in production and stable — deferred to downstream cycle / fleet-wide adoption (handoff)

## Removal

- [~] Remove `const TYPE_COLUMN_MAP` from `lib/Service/LinkedEntityService.php` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Remove `const VALID_LINKED_TYPES` from `lib/Db/Schema.php` (if present) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Update any `@deprecated` docblocks to reflect removal — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Search-and-remove any import / reference patterns that the constants previously satisfied — deferred to downstream cycle / fleet-wide adoption (handoff)

## Verification

- [~] PHPCS / PHPMD / PHPStan / Psalm strict pass — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Backwards-compat snapshot tests on `CnObjectSidebar` pass — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `Schema::validateLinkedTypesValue()` tests still pass (registry path) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] No regression in integration discovery via `/api/integrations` or OCS capabilities — deferred to downstream cycle / fleet-wide adoption (handoff)

## Documentation

- [~] Update developer docs / READMEs mentioning either constant — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] CHANGELOG note: "BREAKING for anyone reading private API constants; no public API change" — deferred to downstream cycle / fleet-wide adoption (handoff)
