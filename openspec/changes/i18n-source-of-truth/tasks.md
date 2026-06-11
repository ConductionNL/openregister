# Tasks: i18n Source of Truth

## Phase 1 — Schema + entity + migration

- [x] Add `sourceLanguage: string` to the JSON-Schema validator for
      property definitions in `lib/Db/Schema.php` (translatable
      properties may declare it; non-translatable MUST NOT). Implemented
      in `lib/Service/Schemas/PropertyValidatorHandler::validateProperty`
      (called via `Schema::validateProperties`); rejects when
      `translatable` is missing or the tag is not BCP-47.
- [x] Add migration `lib/Migration/Version1Date20260520120000.php` —
      `ALTER TABLE openregister_translations ADD COLUMN source_language
      VARCHAR(16) NOT NULL DEFAULT '';` plus an advisory message
      pointing at the back-fill command for large installs.
- [x] Update `lib/Db/Translation.php` entity — add `sourceLanguage`
      column property, getter/setter via QBMapper. Embeds
      `sourceLanguage` + `isSource` in `jsonSerialize()` so API
      responses surface the field without extra wiring.
- [x] Update `lib/Db/TranslationMapper.php` to include `source_language`
      in `upsert()` and the new helper methods
      `markDerivedOutdated()`, `backfillSourceLanguage()`,
      `countMissingSourceLanguage()`, `getDominantSourceLanguage()`,
      plus the extended `search()` signature
      (`sourceLanguage`, `isOutOfDate`).
- [x] Add a back-fill `php occ openregister:translations:backfill-source-language`
      idempotent command in
      `lib/Command/BackfillTranslationSourceLanguageCommand.php`
      (re-runs are no-ops once every row has a non-empty
      `source_language`). Registered in `appinfo/info.xml`.

## Phase 2 — Projection + status service

- [x] Update `lib/Service/TranslationProjectionService.php` to populate
      `source_language` from: object-level `_translationMeta.<prop>.sourceLanguage`
      → schema property `sourceLanguage` → register `defaultLanguage`.
      Exposes the resolver via the new public method
      `resolveSourceLanguage()` so `SaveObject` reuses the same chain.
- [x] Add `lib/Service/TranslationStatusService::markDerivedTranslationsOutdated(string $objectUuid, string $property, string $sourceLanguage): int`
      that flips every non-source-language Translation row to status
      `outdated`. Returns count of rows flipped via
      `TranslationMapper::markDerivedOutdated`.
- [x] Wire the source-change trigger in
      `lib/Service/Object/SaveObject.php`: when a translatable
      property's source-language value changes (compare old vs new),
      call `markDerivedTranslationsOutdated` for that object/property.
      Triggered from `updateObject()` after the row is persisted; the
      helper `flagOutdatedDerivedTranslations()` keeps the contract
      conservative (source-only). Existing event-emission path is
      untouched — derived-row updates carry their own `Updated`
      timestamp through the mapper.

## Phase 3 — Controller + render

- [x] Extend `lib/Controller/TranslationController::search` to honour
      `?sourceLanguage=<bcp47>`, `?isOutOfDate=true`,
      `?compareToSource=true` query filters. `compareToSource=true`
      delegates to `TranslationStatusService::searchWithSourceValues`
      which attaches `sourceValue` per row.
- [x] Update `lib/Service/Object/RenderObject.php` to attach
      `_meta.languageMeta.<property> = { served, sourceLanguage,
      isSource, status }` when `?_translationMeta=true` is requested.
      Helpers: `shouldAttachLanguageMeta`, `attachLanguageMeta`,
      `resolveSourceLanguageForProperty`. Injects
      `TranslationMapper` + `LanguageService` for status / served-language
      lookups; envelope is additive and OFF by default.
- [x] Set `X-Source-Language` response header in
      `lib/Middleware/LanguageMiddleware::afterController()` based on
      the resolved source language for the response. Uses
      `TranslationMapper::getDominantSourceLanguage($uuid)` when the
      request path carries a uuid.

## Phase 4 — Spec + tests

- [x] Write `specs/i18n-source-of-truth/spec.md` with one Requirement
      per public surface (schema property, DB column, projection,
      status service, controller filters, render metadata, response
      header). (Authored as part of the change proposal; refined to
      reflect the implemented entry points.)
- [x] Update `openregister/openspec/specs/register-i18n/spec.md` to
      remove the "Not yet implemented" line about source-of-truth and
      automatic outdated status; cross-reference this change.
- [x] Add `tests/Unit/Service/TranslationProjectionServiceSourceLanguageTest.php`
      covering: object-level override, schema-default inheritance,
      register-default fallback, hardcoded `'nl'` fallback.
- [x] Add `tests/Unit/Service/TranslationStatusServiceOutdatedTest.php`
      covering: source-change → derived languages flipped to outdated;
      non-source-change does NOT flip; missing `sourceLanguage` falls
      back to no-op.
- [x] Add `tests/Unit/Service/Object/RenderObjectLanguageMetaTest.php`
      covering: `_meta.languageMeta` envelope present when
      `?_translationMeta=true`, absent otherwise; additive merging
      with pre-existing `_meta` keys.
- [x] Add Newman collection
      `tests/integration/openregister-i18n-source-of-truth.postman_collection.json`
      hitting: object create with `sourceLanguage: nl`, validator
      rejection of `sourceLanguage` on a non-translatable property,
      `?_translationMeta=true` envelope, controller filter
      `?isOutOfDate=true`, `X-Source-Language` header.
- [x] Wire the new Newman collection into
      `tests/newman/run-all.sh::DOMAIN_ORDER` after `relations`.

## Phase 5 — Documentation

- [x] Update `docs/i18n.md` describing the source-of-truth
      model and the `sourceLanguage` schema-property contract.
- [x] Cross-reference this change from
      `hydra/openspec/architecture/adr-025-i18n-source-of-truth.md`'s
      "Implementation reference" section once shipped. **Done
      2026-06-11:** ADR-025 now carries an "Implementation reference"
      section pointing at this change as the canonical implementation
      of Gap 1 (source-of-truth contract) + companion change
      `i18n-api-language-negotiation` as Gap 2.
