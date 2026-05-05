# Tasks: i18n Source of Truth

## Phase 1 — Schema + entity + migration

- [ ] Add `sourceLanguage: string` to the JSON-Schema validator for
      property definitions in `lib/Db/Schema.php` (translatable
      properties may declare it; non-translatable MUST NOT).
- [ ] Add migration `lib/Migration/Version1Date20260520120000.php` —
      `ALTER TABLE openregister_translations ADD COLUMN source_language
      VARCHAR(16) NOT NULL DEFAULT '';` then a back-fill UPDATE that
      sets `source_language = (SELECT default_language FROM
      openregister_register WHERE id = …)` for every row.
- [ ] Update `lib/Db/Translation.php` entity — add `sourceLanguage`
      column property, getter/setter via QBMapper.
- [ ] Update `lib/Db/TranslationMapper.php` to include
      `source_language` in find/insert/update queries.
- [ ] Add a back-fill `php occ openregister:translations:backfill-source-language`
      idempotent command (re-runs are no-ops once every row has a
      non-empty `source_language`).

## Phase 2 — Projection + status service

- [ ] Update `lib/Service/TranslationProjectionService.php` to populate
      `source_language` from: object-level `_translationMeta.<prop>.sourceLanguage`
      → schema property `sourceLanguage` → register `defaultLanguage`.
- [ ] Add `lib/Service/TranslationStatusService::markDerivedTranslationsOutdated(string $objectUuid, string $property, string $sourceLanguage): int`
      that flips every non-source-language Translation row to status
      `outdated`. Returns count of rows flipped.
- [ ] Wire the source-change trigger in
      `lib/Service/Object/SaveObject.php`: when a translatable
      property's source-language value changes (compare old vs new),
      call `markDerivedTranslationsOutdated` for that object/property.
      Include the trigger in the existing event-emission path so
      consumers can subscribe.

## Phase 3 — Controller + render

- [ ] Extend `lib/Controller/TranslationController::search` to honour
      `?sourceLanguage=<bcp47>`, `?isOutOfDate=true`,
      `?compareToSource=true` query filters.
- [ ] Update `lib/Service/Object/RenderObject.php` to attach
      `_meta.languageMeta.<property> = { served, sourceLanguage,
      isSource, status }` when `?_translationMeta=true` is requested.
- [ ] Set `X-Source-Language` response header in
      `lib/Middleware/LanguageMiddleware::afterController()` based on
      the resolved source language for the response.

## Phase 4 — Spec + tests

- [ ] Write `specs/i18n-source-of-truth/spec.md` with one Requirement
      per public surface (schema property, DB column, projection,
      status service, controller filters, render metadata, response
      header).
- [ ] Update `openregister/openspec/specs/register-i18n/spec.md` to
      remove the "Not yet implemented" line about source-of-truth and
      automatic outdated status; cross-reference this change.
- [ ] Add `tests/Unit/Service/TranslationProjectionServiceSourceLanguageTest.php`
      covering: object-level override, schema-default inheritance,
      register-default fallback.
- [ ] Add `tests/Unit/Service/TranslationStatusServiceOutdatedTest.php`
      covering: source-change → derived languages flipped to outdated;
      non-source-change does NOT flip; missing `sourceLanguage` falls
      back to register default.
- [ ] Add `tests/Unit/Service/Object/RenderObjectLanguageMetaTest.php`
      covering: `_meta.languageMeta` envelope present when
      `?_translationMeta=true`, absent otherwise.
- [ ] Add Newman collection
      `tests/integration/openregister-i18n-source-of-truth.postman_collection.json`
      hitting: object create with `sourceLanguage: nl`, source value
      edit → outdated flip on derived row, controller filter
      `?isOutOfDate=true` returns the flipped row, render with
      `?_translationMeta=true` returns the envelope.
- [ ] Wire the new Newman collection into
      `tests/newman/run-all.sh::DOMAIN_ORDER` after `relations`.

## Phase 5 — Documentation

- [ ] Update `docs/i18n.md` (or create) describing the source-of-truth
      model and the `sourceLanguage` schema-property contract.
- [ ] Cross-reference this change from
      `hydra/openspec/architecture/adr-025-i18n-source-of-truth.md`'s
      "Implementation reference" section once shipped.
