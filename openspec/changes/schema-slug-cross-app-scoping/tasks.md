# Tasks

## 1. Widen slug-uniqueness to (organisation, application, slug)
- [x] Migration `Version1Date20260723000000` drops
      `schemas_organisation_slug_unique` / `registers_organisation_slug_unique`
      and adds `schemas_org_app_slug_unique` / `registers_org_app_slug_unique`
      over `(organisation, application, slug)`.
- [x] Idempotent + self-guarding (checks table + columns + index presence).

## 2. App-scoped schema import
- [x] `SchemaMapper::findByApplicationAndSlug($slug, $application)` — scoped lookup.
- [x] `ImportHandler::importSchema()` uses it when `$appId` is present; app-less
      imports keep the global find. Foreign owner is logged.

## 3. Register-scoped runtime resolution
- [x] `SchemaMapper::findBySlugInIds($slug, $schemaIds)` — resolve within an id set.
- [x] `ObjectService::setSchema()` resolves within the current register first.
- [x] `ObjectService::searchObjectsBySlug()` resolves within the register first.

## 4. Self-heal already-polluted registers
- [x] `ImportHandler::autoCreateRegisterIfApplication()` prunes schema ids shadowed
      by a freshly-imported same-slug app-owned schema during reconcile.

## 5. Verification
- [x] Live-verified on shared 8080: hermiq imports own `conversation` #5018 /
      `message` #5019; register #2428 healed of pipelinq #701/#700; slug resolves
      to #5018; conversation object persists (no `channel` enum error).
- [x] CI: full PHPUnit suite green (update any test asserting the old global-find
      import behaviour to the app-scoped behaviour).
  - Run 2026-11-25 on main (80d3f4ddfc), the whole unit tree file by file (`phpunit-unit.xml`, 6810 test files): no test asserts the old global-find import, and none of the reds was in the import or slug code. Four reds on main, made green here:
    - `AreaMapperRegistrarsTest::testEachInterfaceIsBoundOnce`: `ConfigurationMapperBindings` bound `TranslationMapperInterface`, `TranslationSourceLanguageBackfillInterface` (Core's `DbRegistrar` owns them since a21ac5b0c3) and `SequenceMapperInterface` (Processes owns it since f41124fb6f) a second time; later merges had put them back. Removed from the Configuration map; each still resolves to the same class
    - `AnonymousEnvelopeReadPathsTest`: it still handed `ObjectsControllerFactory` a `WebhookService`, which the factory no longer takes, so the mock landed on `$eventDispatcher`
    - `FlowEngineOversightTest`: it uses `FlowSubject` and `RecordingDispatcher` from `FlowEngineTest.php` and failed when run on its own; it now `require_once`s that file
    - `ReservedColumnNamesTest`: red only in a session whose `GITHUB_TOKEN` is scoped to other repositories, which makes raw.githubusercontent.com answer 404; green without the variable, nothing to change
  - With these, the 6810 files are green; 14 process-isolated files need the OCP autoloader in the child PHP (`PHP_INI_SCAN_DIR`), a property of the local run, not of the tests
