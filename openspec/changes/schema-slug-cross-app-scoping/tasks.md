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
- [ ] CI: full PHPUnit suite green (update any test asserting the old global-find
      import behaviour to the app-scoped behaviour).
