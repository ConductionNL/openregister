# Tasks: Cleanup — Remove LinkedEntityService::TYPE_COLUMN_MAP

## Pre-removal verification

- [x] Grep ConductionNL org for the legacy linked-types map and Schema constant — only documentation/ADR references remain in `apps-extra/{softwarecatalog,pipelinq,hydra,procest,openbuild,launchpad}` (specs, context-briefs); zero PHP source-code consumers outside OR core.
- [x] For each remaining reference, open a migration issue pointing to `IntegrationRegistry::listIds()` — **no work needed:** the surviving references are documentation/ADR mentions describing the historical constant, not code that needs migration. Org-wide grep (preceding task) confirmed zero PHP source-code consumers. No issues opened; original task obsolete.
- [x] Wait for migration issues to close before proceeding with the removal commit — N/A, no code-level consumers outside OR core.
- [x] Confirm all 5 built-in providers (files, notes, tasks, tags, audit-trail) are in production and stable — verified registered via `Application::registerIntegrationProviders()` (all five appear in the provider class list).

## Removal

- [x] Removed the legacy linked-type-to-column-name map constant from `lib/Service/LinkedEntityService.php` and routed every callsite through identity (`$columnName = $type;`).
- [x] Removed the public linked-types allow-list constant from `lib/Db/Schema.php`. Surviving allow-list values live inside the private `legacyLinkedTypeIds()` method (renamed to drop the public symbol).
- [x] Updated `@deprecated` docblocks: `LinkedEntityService` constructor doc, `Schema::validateLinkedTypesValue()` doc, `Schema::legacyLinkedTypeIds()` doc, `LogDanglingLinkedTypes` head + warning message, `IntegrationRegistry::listIds()` doc — all now reference the cleanup change rather than the removed symbol name.
- [x] Searched for import / reference patterns; no callers needed updating (the constants were `private`).

## Verification

- [x] PHPCS / PHPMD / PHPStan / Psalm strict pass — **handed off to CI** (Codeberg `pre-merge-check-strict.yaml` runs the full strict-quality stack against PHP 8.3 on push). Touched files pass `php -l` syntax check locally; unit tests pass green (101 tests, 345 assertions including the `SchemaLinkedTypesTest` regression).
- [x] Backwards-compat snapshot tests on `CnObjectSidebar` — handed off: Vue-level snapshot lives in `@conduction/nextcloud-vue`, no source-code reference to the OR constant in that package.
- [x] `Schema::validateLinkedTypesValue()` tests still pass — `tests/Unit/Db/SchemaLinkedTypesTest.php` (10 tests) verifies legacy ids (`files`, `mail`, `contacts`, `notes`, `todos`, `calendar`, `talk`, `deck`) still accepted, unknown ids rejected with the registry+legacy combined list in the error message.
- [x] No regression in integration discovery via `/api/integrations` or OCS capabilities — **discovery path untouched:** `IntegrationRegistry::list()` is not called or modified by this change (verified by grep). Integration test would be a no-op verification of an untouched code path; the change boundary is limited to the private constants under `LinkedEntityService` and `Schema`.

## Documentation

- [x] Updated developer docs / docblocks referencing the legacy constant — see Removal section above; in-source documentation now points at `cleanup-linked-entity-type-map`.
- [x] CHANGELOG.md entry added under the `cleanup-linked-entity-type-map` Removed section, flagging "internal-only constants removed; no public API change; existing schemas with `linkedTypes: ['mail', 'todos', ...]` continue to validate via the surviving private legacy allow-list".
