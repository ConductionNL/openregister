## ADDED Requirements

### Requirement: A translation sidecar MUST be projected from the authoritative object JSONB

The authoritative store for translatable property values MUST remain the language-keyed JSONB on the object. The system MUST maintain a derived `openregister_translations` sidecar — one row per `(object uuid, property, language)` — kept in sync by `TranslationProjectionService`, optimized for per-language search, completeness queries, and workflow status tracking. The projection MUST NOT become a second source of truth.

#### Scenario: Project translatable properties into the sidecar

- **GIVEN** an object with a translatable property `omschrijving` holding `{"nl": "Paspoort", "en": "Passport"}`
- **WHEN** `TranslationProjectionService::project()` runs (on object create or update)
- **THEN** it MUST upsert one sidecar row per non-empty language value via `TranslationMapper::upsert()`
- **AND** the upsert MUST pass `status: null` so the mapper preserves an existing status or defaults a new slot to `draft`
- **AND** the translator MUST be set from the active session UID when available

#### Scenario: Legacy single-language value is credited to the default language

- **GIVEN** a translatable property whose value is a plain string (legacy, not language-keyed)
- **WHEN** the projection runs
- **THEN** the value MUST be projected under the register default language `nl`

#### Scenario: Stale rows are removed when a value disappears

- **GIVEN** an object that previously had an `en` translation now removed from its JSONB
- **WHEN** the projection runs
- **THEN** any sidecar row whose `(property, language)` no longer has a desired value MUST be deleted (best-effort)
- **AND** when the schema declares no translatable properties, any pre-existing rows for properties no longer translatable MUST be deleted

#### Scenario: Object with no uuid or unresolvable schema is skipped

- **GIVEN** an object with a null/empty uuid, or whose schema reference cannot be resolved
- **WHEN** the projection runs
- **THEN** it MUST return without writing, and any thrown error MUST be caught and logged as a warning rather than propagated

#### Scenario: Purge drops every translation row for an object

- **GIVEN** an object being deleted
- **WHEN** `TranslationProjectionService::purge()` is called
- **THEN** it MUST delete all sidecar rows for the object uuid via `TranslationMapper::deleteByObject()`
- **AND** a failure MUST be caught and logged as a warning, never blocking the deletion

### Requirement: Translation workflow status and completeness MUST be queryable through the sidecar

`TranslationStatusService` MUST expose the public API over the translation sidecar: promoting a slot's workflow status, computing per-object completeness, searching translation rows, and discovering objects that lack a given language.

#### Scenario: Promote a translation slot's status

- **GIVEN** an existing translation slot for `(object, property, language)`
- **WHEN** `setStatus()` is called with a status in `Translation::ALL_STATUSES`
- **THEN** the slot's status MUST be updated via `TranslationMapper::upsert()`, preserving the existing value and attributing the active session UID as translator

#### Scenario: Invalid status or missing slot is rejected

- **GIVEN** a `setStatus()` call with a status not in `Translation::ALL_STATUSES`, or for a `(object, property, language)` slot that does not yet exist
- **WHEN** the method executes
- **THEN** it MUST throw `InvalidArgumentException` with a message naming the invalid status or the missing slot — a value MUST be set before its status can be promoted

#### Scenario: Per-object completeness ratio per language

- **GIVEN** an object and its schema with N translatable properties
- **WHEN** `completenessForObject()` is called
- **THEN** it MUST return `[language => {translated, total, ratio}]` where `total` is N, `translated` is the count of filled slots for that language, and `ratio` is `translated / total` rounded to two decimals
- **AND** when the schema has no translatable properties it MUST return an empty array

#### Scenario: Search and missing-language discovery

- **GIVEN** translation rows in the sidecar
- **WHEN** `search()` is called with optional query, language, status, and object filters and a limit
- **THEN** it MUST return the matching rows as `jsonSerialize()` arrays
- **AND** `findObjectsMissingLanguage()` MUST return the subset of candidate uuids missing at least one translatable-property value in the requested language

### Requirement: Machine translation MUST fill empty slots through a pluggable provider

`BulkTranslationService` MUST translate an object's translatable properties from a source to a target language using a configured `TranslationProviderInterface`, filling only target-language slots that are currently empty. A default `IdentityTranslationProvider` MUST ship so the flow is testable without external API keys.

#### Scenario: Translate only empty target slots

- **GIVEN** an object with a source value in `fromLang` and no value in `toLang` for a translatable property
- **WHEN** `translateObject()` runs
- **THEN** it MUST call `provider->translate()`, record the result in the returned `translated` map, and immediately upsert the sidecar slot with status `Translation::STATUS_MACHINE_TRANSLATED` and translator `provider:{identifier}`
- **AND** a property whose target slot is already non-empty MUST be skipped with reason `target-slot-already-filled`

#### Scenario: No-op and skip conditions

- **GIVEN** a `translateObject()` call where `fromLang === toLang`, the schema is unresolvable, or the schema has no translatable properties
- **WHEN** the method executes
- **THEN** it MUST return an empty `translated` map with a `_global` skip reason
- **AND** a property with no usable source value MUST be skipped with reason `no-source-value`
- **AND** a provider that throws or returns an empty string MUST be skipped (`provider-error: ...` / `provider-returned-empty`) without aborting the remaining properties

#### Scenario: Provider strategy contract

- **GIVEN** any `TranslationProviderInterface` implementation
- **WHEN** `translate(text, fromLang, toLang)` is called
- **THEN** it MUST return the translated string or `null` on a miss/error (callers MUST treat `null` as a skip, never persisting it)
- **AND** `getIdentifier()` MUST return a stable slug used for `provider:{identifier}` status attribution
- **AND** `IdentityTranslationProvider::translate()` MUST return the source text verbatim and `getIdentifier()` MUST return `identity`

### Requirement: CSV import/export MUST round-trip translations via field-language columns

`TranslationCsvCodec` MUST convert between the nested `{lang: value}` JSON shape and the flat `field_lang` column shape used by CSV/Excel, preserving language variants in both directions.

#### Scenario: Flatten language-keyed values to columns

- **GIVEN** an object row with a translatable property `title` holding `{"nl": "...", "en": "..."}`
- **WHEN** `flattenForCsv()` runs
- **THEN** it MUST emit one `title_nl` / `title_en` column per language present
- **AND** a translatable property holding a plain legacy string MUST be emitted under `field_und` (BCP 47 undetermined) to avoid guessing the language
- **AND** untranslatable properties MUST pass through unchanged, with non-scalar values JSON-encoded to keep one cell per column

#### Scenario: Unflatten field-language columns back to nested shape

- **GIVEN** a flat CSV row with columns `title_nl`, `title_en`, and unrelated columns
- **WHEN** `unflattenFromCsv()` runs
- **THEN** columns matching `<translatable-property>_<lang>` (where `<lang>` matches the language-code pattern) MUST be reassembled into `{property: {lang: value}}`
- **AND** an empty cell MUST NOT create a slot (the projection treats it as untranslated)
- **AND** unrecognized or untranslatable columns MUST pass through as-is
