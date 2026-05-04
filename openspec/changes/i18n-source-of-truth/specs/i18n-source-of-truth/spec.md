i18n-source-of-truth
---
status: draft
---
# i18n Source of Truth

## Purpose

Implement the source-of-truth half of hydra ADR-025: schemas declare
which language is canonical for a translatable property, the
`openregister_translations` sidecar table tracks the source-language
of every projected translation, edits to source values automatically
flip derived translations to `outdated`, and render responses
optionally expose source-vs-translation metadata. The companion
change `i18n-api-language-negotiation` covers the request/response
contract.

## ADDED Requirements

### Requirement: Schemas MUST be able to declare a `sourceLanguage` per translatable property

Schemas MUST accept `sourceLanguage: <bcp47>` on any property where `translatable: true`. The validator MUST reject `sourceLanguage` on properties without `translatable: true`. If a translatable property omits `sourceLanguage`, OR MUST treat the register's `defaultLanguage` (first element of `Register.languages`, falling back to `nl`) as the source language for that property.

#### Scenario: Schema declares sourceLanguage on a translatable property
- GIVEN a schema body `{"properties": {"title": {"type": "string", "translatable": true, "sourceLanguage": "nl"}}}`
- WHEN the schema is saved
- THEN the persisted schema MUST retain `sourceLanguage: "nl"` on the `title` property

#### Scenario: Schema rejects sourceLanguage on a non-translatable property
- WHEN a schema body `{"properties": {"code": {"type": "string", "sourceLanguage": "nl"}}}` is POSTed
- THEN OR MUST return `400 Bad Request`
- AND the response body MUST include `error.invalidProperty: 'code'` and `error.reason: 'sourceLanguage requires translatable: true'`

#### Scenario: Translatable property without sourceLanguage falls back to register default
- GIVEN a register with `defaultLanguage = "nl"`
- AND a schema with `{"title": {"translatable": true}}` (no `sourceLanguage`)
- WHEN an object is created with `title: {nl: "Hallo", en: "Hello"}`
- THEN the projected Translation rows MUST have `source_language = "nl"` for both rows

### Requirement: Objects MAY override `sourceLanguage` per property via `_translationMeta`

Objects MAY include `_translationMeta.<property>.sourceLanguage = "<bcp47>"` in their body. When present, this overrides the schema default for that single object's property. When absent, the schema/register fallback chain applies.

#### Scenario: Object overrides sourceLanguage for a single property
- GIVEN a schema declaring `title.sourceLanguage = "nl"`
- WHEN an object is POSTed with `{"title": {"nl": "x", "en": "y"}, "_translationMeta": {"title": {"sourceLanguage": "en"}}}`
- THEN the projected Translation rows MUST have `source_language = "en"`
- AND `_translationMeta.title.sourceLanguage = "en"` MUST be persisted on the object body

### Requirement: The `openregister_translations` table MUST include a `source_language` column

The migration MUST add `source_language VARCHAR(16) NOT NULL DEFAULT ''` to `openregister_translations`. A back-fill MUST set every existing row's `source_language` to the register's `default_language` (or `'nl'` when missing). After back-fill, a follow-up migration MUST drop the default value so new rows are forced to set the column explicitly.

#### Scenario: Existing translation rows are back-filled
- GIVEN `openregister_translations` contains 100 rows from before this change, each with empty `source_language`
- AND every row's parent register has `default_language = "nl"`
- WHEN the migration runs
- THEN all 100 rows MUST have `source_language = "nl"` after the back-fill
- AND `php occ openregister:translations:backfill-source-language` re-run MUST be a no-op (returns "0 rows updated")

### Requirement: `TranslationProjectionService` MUST populate `source_language` on every projected row

The projector MUST resolve `source_language` for each property using the lookup chain: `_translationMeta.<prop>.sourceLanguage` (object) → schema `properties.<prop>.sourceLanguage` → `Register.defaultLanguage` → `'nl'`. Every projected Translation row MUST have a non-empty `source_language` after the change ships.

#### Scenario: Projection respects the resolution chain
- GIVEN a register `defaultLanguage = "nl"`
- AND a schema with `title.sourceLanguage = "fr"`
- AND an object body `{"title": {"fr": "Bonjour", "en": "Hello"}, "_translationMeta": {"title": {"sourceLanguage": "en"}}}`
- WHEN `TranslationProjectionService::project()` runs
- THEN both projected rows for `title` MUST have `source_language = "en"` (object-level override wins)

### Requirement: `TranslationStatusService` MUST flip derived rows to `outdated` on source-value change

The system MUST expose `TranslationStatusService::markDerivedTranslationsOutdated(string $objectUuid, string $property, string $sourceLanguage): int` that updates every Translation row WHERE `object_uuid = $objectUuid AND property = $property AND language != $sourceLanguage` AND `status IN ('approved', 'human_reviewed', 'machine_translated')` to `status = 'outdated'`. The method MUST return the count of rows updated. A row already in `outdated` or `draft` status MUST NOT be re-flipped.

#### Scenario: Source value change flips approved derived rows to outdated
- GIVEN an object with `title.sourceLanguage = "nl"` and an approved English translation
- WHEN `markDerivedTranslationsOutdated('obj-uuid', 'title', 'nl')` is called
- THEN the English translation row MUST have `status = "outdated"`
- AND the Dutch source row MUST be unchanged
- AND the method MUST return `1` (one row flipped)

#### Scenario: Already-outdated rows are not re-flipped
- GIVEN an English translation row already in `status = "outdated"`
- WHEN `markDerivedTranslationsOutdated('obj-uuid', 'title', 'nl')` is called
- THEN the row MUST remain `outdated`
- AND the method MUST NOT include this row in the returned count

### Requirement: `SaveObject` MUST trigger the outdated flip on source-value change

When persisting an object update, `SaveObject` MUST detect changes to a translatable property's source-language value (compare old vs new for the resolved source language) and MUST call `markDerivedTranslationsOutdated($uuid, $property, $sourceLang)` for each such change. Edits to non-source-language values MUST NOT trigger the flip.

#### Scenario: Editing the Dutch source flips English translation to outdated
- GIVEN an object with `title = {"nl": "Hallo", "en": "Hello"}`, `sourceLanguage = "nl"`, English row `status = "approved"`
- WHEN the object is PATCHed with `title.nl = "Welkom"`
- THEN the English translation row MUST have `status = "outdated"` after persist
- AND a `TranslationStatusChanged` event MUST have been emitted

#### Scenario: Editing the English translation does NOT flip status
- GIVEN the same starting state
- WHEN the object is PATCHed with `title.en = "Welcome"`
- THEN the English row MUST stay `status = "approved"` (or be the row being edited)
- AND no rows MUST have been flipped to outdated

### Requirement: `TranslationController` MUST expose source-language query filters

The translation search endpoint MUST accept three new query parameters: `?sourceLanguage=<bcp47>` (filter to rows whose source language matches), `?isOutOfDate=true` (filter to `status = "outdated"`), `?compareToSource=true` (return both source and translation values per matched row, side-by-side).

#### Scenario: Filter translations by source language
- GIVEN 5 Translation rows: 3 with `source_language = "nl"`, 2 with `source_language = "en"`
- WHEN `GET /api/translations/search?sourceLanguage=nl` is called
- THEN the response MUST contain exactly 3 rows
- AND every row's `source_language` MUST equal `"nl"`

#### Scenario: Filter translations to outdated only
- GIVEN 10 rows, 3 of which have `status = "outdated"`
- WHEN `GET /api/translations/search?isOutOfDate=true` is called
- THEN the response MUST contain exactly 3 rows
- AND every row's `status` MUST equal `"outdated"`

#### Scenario: Compare to source returns side-by-side values
- GIVEN an object with `title.nl = "Welkom"` and `title.en = "Hello"` (out of date)
- WHEN `GET /api/translations/search?compareToSource=true&objectUuid=...` is called
- THEN each returned row MUST include both `value` (the row's value, e.g. "Hello") and `sourceValue` (the source-language value, e.g. "Welkom")

### Requirement: `RenderObject` MUST attach `_meta.languageMeta` when `?_translationMeta=true` is passed

When the request includes `?_translationMeta=true`, `RenderObject` MUST add `_meta.languageMeta.<property>` to the response body for every translatable property that was rendered. The envelope MUST contain: `served` (the language actually returned), `sourceLanguage` (the canonical source for the property), `isSource` (boolean — true iff `served === sourceLanguage`), `status` (the translation row's status, or `"source"` for the source-language row).

#### Scenario: Render with translation metadata
- GIVEN an object with `title = {"nl": "Welkom", "en": "Hello"}`, `sourceLanguage = "nl"`
- WHEN `GET /api/objects/{r}/{s}/{id}?_lang=en&_translationMeta=true` is called
- THEN the response body MUST include `_meta.languageMeta.title = {served: "en", sourceLanguage: "nl", isSource: false, status: "approved"}`

#### Scenario: Render without `_translationMeta` omits the envelope
- GIVEN the same object
- WHEN `GET /api/objects/{r}/{s}/{id}?_lang=en` is called (no `_translationMeta`)
- THEN the response body MUST NOT contain `_meta.languageMeta`

### Requirement: Responses MUST set `X-Source-Language` header

For any endpoint returning object content, OR MUST set the `X-Source-Language` response header to the canonical source language for the object's primary translatable property (or, when an object has multiple translatable properties with different source languages, the most common one across the object). The header is informational; clients use it for at-a-glance "is this the original?" detection.

#### Scenario: Single-source object exposes source language
- GIVEN an object whose translatable properties all declare `sourceLanguage = "nl"`
- WHEN any endpoint returning that object is called
- THEN the response MUST include header `X-Source-Language: nl`

#### Scenario: Mixed-source object exposes the dominant language
- GIVEN an object with 2 translatable properties: `title.sourceLanguage = "nl"`, `description.sourceLanguage = "nl"`, `notes.sourceLanguage = "en"`
- WHEN any endpoint returning that object is called
- THEN the response MUST include header `X-Source-Language: nl` (2 of 3)
