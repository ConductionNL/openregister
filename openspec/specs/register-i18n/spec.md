---
status: done
---

# Register Internationalization

## Purpose

Implement multi-language content management for register objects so that translatable properties store per-language variants, APIs negotiate content language via Accept-Language headers, and the UI provides language-aware editing with completeness tracking. The system MUST support at minimum Dutch (NL, required) and English (EN, optional) to comply with Single Digital Gateway (SDG) Regulation (EU) 2018/1724 for cross-border EU service access, while the architecture MUST allow registers to configure any number of BCP 47 languages including RTL scripts. This spec covers data-level i18n for register object content -- it is distinct from the app UI string translations governed by `i18n-infrastructure`, `i18n-string-extraction`, `i18n-backend-messages`, and `i18n-dutch-translations` specs, which handle Nextcloud `IL10N` / `t()` / `$l->t()` for interface labels.

**Source**: Gap identified in cross-platform analysis; four competitors implement field-level i18n. SDG compliance requires English availability for cross-border services. ADR-005 mandates NL+EN as minimum languages for all Conduction apps.
## Requirements
### Requirement: Schema properties MUST support a translatable flag

Schema property definitions MUST accept a `translatable: true` attribute indicating the field supports multiple language versions. Properties without the flag (or with `translatable: false`) SHALL store a single value regardless of language context. The `translatable` attribute MUST be stored as part of the property definition in the schema's `properties` JSON and MUST be inspectable by `TranslationHandler::getTranslatableProperties()`.

#### Scenario: Define a translatable property

@e2e exclude backend schema property storage — covered by PHPUnit

- **GIVEN** a schema `producten` with property `omschrijving` of type `string`
- **WHEN** the admin sets `translatable: true` on the `omschrijving` property definition
- **THEN** the schema's `properties` JSON SHALL contain `{"omschrijving": {"type": "string", "translatable": true}}`
- **AND** `TranslationHandler::getTranslatableProperties()` SHALL return `["omschrijving"]`

#### Scenario: Non-translatable property remains unaffected

@e2e exclude backend property behavior — covered by PHPUnit

- **GIVEN** property `code` on schema `producten` with `translatable` not set (defaults to `false`)
- **WHEN** an object is created or rendered
- **THEN** the `code` property SHALL have a single value regardless of language
- **AND** `TranslationHandler` SHALL skip this property during normalization and resolution

#### Scenario: Mark multiple properties as translatable

@e2e exclude backend schema property batch — covered by PHPUnit

- **GIVEN** schema `producten` with properties `naam`, `omschrijving`, `categorie`, and `prijs`
- **WHEN** the admin marks `naam` and `omschrijving` as `translatable: true` but leaves `categorie` and `prijs` as non-translatable
- **THEN** `TranslationHandler::getTranslatableProperties()` SHALL return `["naam", "omschrijving"]`
- **AND** `categorie` and `prijs` SHALL behave as single-value properties

#### Scenario: Translatable flag on nested object properties

@e2e exclude backend nested property handling — covered by PHPUnit

- **GIVEN** schema `producten` with a property `details` of type `object` containing sub-properties
- **WHEN** the admin marks `details` as `translatable: true`
- **THEN** the entire `details` object SHALL be stored per language as `{"nl": {...}, "en": {...}}`
- **AND** sub-properties SHALL NOT individually support the `translatable` flag (translation granularity is at the top-level property)

#### Scenario: Translatable flag in schema UI editor
- **GIVEN** the schema property editor in the OpenRegister admin UI
- **WHEN** the admin edits a string-type property
- **THEN** a toggle labeled `t('openregister', 'Translatable')` SHALL be visible
- **AND** toggling it SHALL set `translatable: true` in the property definition

### Requirement: Objects MUST store translations per translatable property as language-keyed JSON @e2e exclude backend storage format — covered by PHPUnit

Each translatable property MUST store its values as a JSON object keyed by BCP 47 language codes (e.g., `{"nl": "Paspoort aanvragen", "en": "Passport application"}`). This structure SHALL be stored within the existing `object` JSON column on the `ObjectEntity`, requiring no database schema changes. The `TranslationHandler::normalizeTranslationsForSave()` method SHALL wrap simple (non-array) values under the register's default language before persisting.

#### Scenario: Create object with multiple translations
- **GIVEN** schema `producten` with translatable property `omschrijving` and register configured with languages `["nl", "en"]`
- **WHEN** a user creates an object via `POST /api/objects/{register}/{schema}` with body `{"omschrijving": {"nl": "Aanvraag omgevingsvergunning", "en": "Environmental permit application"}}`
- **THEN** the stored object JSON SHALL contain `{"omschrijving": {"nl": "Aanvraag omgevingsvergunning", "en": "Environmental permit application"}}`

#### Scenario: Create object with only default language
- **GIVEN** a translatable property `omschrijving` and register default language `nl`
- **WHEN** a user creates an object with `{"omschrijving": "Paspoort aanvragen"}` (simple string value)
- **THEN** `TranslationHandler::normalizeTranslationsForSave()` SHALL wrap the value as `{"omschrijving": {"nl": "Paspoort aanvragen"}}`
- **AND** the object SHALL be created successfully

#### Scenario: Update a single language translation
- **GIVEN** an object with `omschrijving`: `{"nl": "Paspoort aanvragen", "en": "Passport application"}`
- **WHEN** a user sends `PUT /api/objects/{register}/{schema}/{id}` with `{"omschrijving": {"nl": "Paspoort aanvragen", "en": "Apply for passport"}}`
- **THEN** the English translation SHALL be updated to `"Apply for passport"`
- **AND** the Dutch translation SHALL remain `"Paspoort aanvragen"`

#### Scenario: Default language value is required
- **GIVEN** a translatable property `omschrijving` and register default language `nl`
- **WHEN** a user creates an object with `{"omschrijving": {"en": "Passport application"}}` (missing Dutch)
- **THEN** `TranslationHandler::normalizeTranslationsForSave()` SHALL log a warning via `LoggerInterface`
- **AND** the object SHALL still be saved (non-blocking) but the missing default language SHALL be flagged

#### Scenario: Non-translatable property ignores language keys
- **GIVEN** property `code` with `translatable: false`
- **WHEN** a user sends `{"code": {"nl": "ABC123"}}` in the request body
- **THEN** the value SHALL be stored as-is (treated as a regular object, not a translation map)
- **AND** `TranslationHandler` SHALL not modify this property

### Requirement: The API MUST support language negotiation via Accept-Language header @e2e exclude REST API language negotiation — covered by Newman

API responses MUST return translatable property values in the language requested via the `Accept-Language` header (RFC 9110, Section 12.5.4). The `LanguageMiddleware` SHALL parse the header before any controller action and store the resolved language in the request-scoped `LanguageService`. The response SHALL include a `Content-Language` header indicating the served language. If the requested language is unavailable, the system SHALL follow the fallback chain and add an `X-Content-Language-Fallback: true` header.

#### Scenario: Request content in English
- **GIVEN** an object with `omschrijving`: `{"nl": "Paspoort aanvragen", "en": "Passport application"}`
- **WHEN** the API receives `GET /api/objects/{register}/{schema}/{id}` with header `Accept-Language: en`
- **THEN** `LanguageMiddleware::beforeController()` SHALL parse the header and set `en` as the preferred language in `LanguageService`
- **AND** `TranslationHandler::resolveTranslationsForRender()` SHALL return `{"omschrijving": "Passport application"}`
- **AND** the response SHALL include header `Content-Language: en`

#### Scenario: Fallback to Dutch when translation missing
- **GIVEN** an object with `omschrijving`: `{"nl": "Paspoort aanvragen"}` (no English translation)
- **WHEN** the API receives a request with `Accept-Language: en`
- **THEN** the response SHALL return `{"omschrijving": "Paspoort aanvragen"}` (Dutch fallback)
- **AND** the response SHALL include headers `Content-Language: nl` and `X-Content-Language-Fallback: true`
- **AND** `LanguageService::isFallbackUsed()` SHALL return `true`

#### Scenario: Request all translations via query parameter
- **GIVEN** an object with `omschrijving`: `{"nl": "Paspoort aanvragen", "en": "Passport application"}`
- **WHEN** the API receives `GET /api/objects/{register}/{schema}/{id}?_translations=all`
- **THEN** `LanguageService::shouldReturnAllTranslations()` SHALL return `true`
- **AND** `TranslationHandler::resolveTranslationsForRender()` SHALL return the full language-keyed object: `{"omschrijving": {"nl": "Paspoort aanvragen", "en": "Passport application"}}`

#### Scenario: Accept-Language with quality factors
- **GIVEN** an object with `omschrijving`: `{"nl": "Paspoort aanvragen", "de": "Reisepass beantragen"}`
- **WHEN** the API receives `Accept-Language: en-US,en;q=0.9,de;q=0.8,nl;q=0.7`
- **THEN** `LanguageService::parseAcceptLanguageHeader()` SHALL return `["en-US", "en", "de", "nl"]` sorted by quality
- **AND** `LanguageService::resolveLanguageForRegister()` SHALL match `de` (first available language in priority order)
- **AND** the response SHALL return `{"omschrijving": "Reisepass beantragen"}` with `Content-Language: de`

#### Scenario: List endpoint respects Accept-Language
- **GIVEN** multiple objects with translatable properties
- **WHEN** the API receives `GET /api/objects/{register}/{schema}` with `Accept-Language: en`
- **THEN** every object in the response array SHALL have its translatable properties resolved to English (or fallback)
- **AND** the `Content-Language` header SHALL reflect the primary language served

### Requirement: Fallback language chain MUST be configurable per register @e2e exclude backend language fallback chain — covered by PHPUnit

Each register MUST define an ordered fallback chain for language resolution. When the requested language is unavailable for a property, the system SHALL try each language in the chain until a value is found. The default chain SHALL be: requested language, then register default language (`nl`), then first available translation. The register's `languages` array determines the available languages and the first element is the default.

#### Scenario: Configure register languages
- **GIVEN** register `producten` being created via `POST /api/registers`
- **WHEN** the admin sets `{"languages": ["nl", "en", "de"], ...}`
- **THEN** `Register::getLanguages()` SHALL return `["nl", "en", "de"]`
- **AND** `Register::getDefaultLanguage()` SHALL return `"nl"` (first element)
- **AND** only these three languages SHALL be available for translations in this register

#### Scenario: Fallback chain resolution order
- **GIVEN** register with languages `["nl", "en", "de"]` and an object where property `naam` has `{"de": "Reisepass"}`
- **WHEN** a request arrives with `Accept-Language: en`
- **THEN** the system SHALL try `en` (not found), then `nl` (not found, register default), then `de` (found)
- **AND** the response SHALL return `"Reisepass"` with `X-Content-Language-Fallback: true`

#### Scenario: Add a language to an existing register
- **GIVEN** register `producten` with languages `["nl", "en"]`
- **WHEN** the admin updates the register with `{"languages": ["nl", "en", "fr"]}`
- **THEN** French SHALL become available for translations
- **AND** existing objects SHALL NOT be modified (French values simply do not exist yet)

#### Scenario: Register with no languages configured falls back to Dutch
- **GIVEN** a register with `languages` set to `null` or `[]`
- **WHEN** `Register::getDefaultLanguage()` is called
- **THEN** it SHALL return `"nl"` as the hardcoded fallback
- **AND** all translatable properties SHALL be stored under `"nl"` by `TranslationHandler`

#### Scenario: Validate language codes
- **GIVEN** a register update request with `{"languages": ["nl", "invalid!!"]}`
- **WHEN** the system validates the language array
- **THEN** each language code MUST conform to BCP 47 / RFC 5646 pattern (`/^[a-z]{2,3}(-[a-zA-Z0-9]{2,8})*$/`)
- **AND** invalid codes SHALL be rejected with a `400 Bad Request` response

### Requirement: Nextcloud IL10N integration MUST translate app UI independently from object content @e2e exclude Nextcloud IL10N backend integration — covered by PHPUnit

The app UI (labels, buttons, error messages, navigation) MUST use Nextcloud's `IL10N` / `@nextcloud/l10n` translation system per ADR-005. This is entirely separate from data-level i18n. UI strings follow the user's Nextcloud locale preference; object content follows the `Accept-Language` header or the language selected in the object editor.

#### Scenario: UI labels use IL10N
- **GIVEN** a PHP controller returning a success message
- **WHEN** the message is constructed
- **THEN** it SHALL use `$this->l10n->t('Object saved successfully')` (Nextcloud IL10N)
- **AND** the `l10n/nl.json` file SHALL contain `"Object saved successfully": "Object succesvol opgeslagen"`
- **AND** the UI label language depends on the Nextcloud user's locale, NOT the register's configured languages

#### Scenario: Schema property display names use IL10N
- **GIVEN** a schema with property `omschrijving` displayed in the object edit form
- **WHEN** the property label is rendered in the UI
- **THEN** the label SHALL use `t('openregister', 'Description')` for the UI label
- **AND** the property's data content SHALL follow the register's language configuration (separate concern)

#### Scenario: Admin UI for register language configuration
- **GIVEN** the register settings form in the admin panel
- **WHEN** the admin views the language configuration section
- **THEN** all UI labels (e.g., "Default language", "Available languages", "Add language") SHALL use `t()` and be available in NL and EN
- **AND** the language codes themselves (nl, en, de) SHALL be displayed with their native names (Nederlands, English, Deutsch)

#### Scenario: Error messages in API responses follow user locale
- **GIVEN** a Dutch-locale user performing an invalid operation via the UI
- **WHEN** the controller returns an error
- **THEN** the error message SHALL be in Dutch via `$this->l10n->t()`
- **AND** this is independent of the object's content language

### Requirement: The UI MUST provide a language-aware object editor with translation status

The object edit form MUST display language tabs for translatable properties, allowing users to switch between languages. Non-translatable properties SHALL remain visible regardless of the selected language tab. The editor MUST indicate translation completeness per language.

#### Scenario: Edit translations via language tabs
- **GIVEN** an object with schema having translatable properties `naam` and `omschrijving`, and register languages `["nl", "en"]`
- **WHEN** the user opens the object edit form
- **THEN** language tabs labeled "NL" and "EN" SHALL be displayed above the translatable fields
- **AND** switching tabs SHALL show/edit the translation for that language
- **AND** non-translatable fields (e.g., `code`, `prijs`) SHALL remain visible and editable regardless of selected tab

#### Scenario: Indicate missing translations with badge
- **GIVEN** an object with Dutch content for all translatable properties but no English translations
- **WHEN** the user views the language tabs
- **THEN** the "EN" tab SHALL show a warning badge (e.g., orange dot or count indicator)
- **AND** hovering the badge SHALL show a tooltip: `t('openregister', '%n field needs translation', '%n fields need translation', count)`

#### Scenario: Side-by-side translation editing
- **GIVEN** an object with translatable property `omschrijving` and register languages `["nl", "en"]`
- **WHEN** the user activates "side-by-side" mode in the language editor
- **THEN** the Dutch value SHALL be displayed read-only on the left
- **AND** the English input field SHALL be displayed on the right for editing
- **AND** this layout SHALL help translators see the source text while entering translations

#### Scenario: Create object defaults to default language tab
- **GIVEN** a new object form for a schema with translatable properties and register default language `nl`
- **WHEN** the form loads
- **THEN** the `NL` tab SHALL be selected by default
- **AND** the user SHALL be able to fill in other language tabs before saving

#### Scenario: Language tab order matches register configuration
- **GIVEN** register languages configured as `["nl", "en", "de", "fr"]`
- **WHEN** the language tabs are rendered
- **THEN** they SHALL appear in the order: NL, EN, DE, FR
- **AND** the order SHALL match `Register::getLanguages()`

### Requirement: Translation workflow MUST support status tracking per property per language

Each translatable property per language MUST support a translation status to enable review workflows. Statuses SHALL be: `draft`, `needs_review`, `approved`, `outdated`. When the source (default language) text changes, all other language statuses SHALL automatically transition to `outdated`.

#### Scenario: New translation starts as draft

@e2e exclude backend status initialization — covered by PHPUnit

- **GIVEN** an object with translatable property `omschrijving` and a user adding an English translation
- **WHEN** the English value is saved for the first time
- **THEN** the translation status for `omschrijving.en` SHALL be set to `draft`

#### Scenario: Source text change marks translations as outdated

@e2e exclude backend status transition — covered by PHPUnit

- **GIVEN** an object with `omschrijving`: `{"nl": "Paspoort aanvragen", "en": "Passport application"}` and English status `approved`
- **WHEN** the Dutch (source) text is updated to `"Nieuw paspoort aanvragen"`
- **THEN** the English translation status SHALL automatically change to `outdated`
- **AND** the UI SHALL display a visual indicator on the English tab showing the translation needs updating

#### Scenario: Mark translation as approved

@e2e exclude REST API status update — covered by Newman

- **GIVEN** a user with translation review permissions
- **WHEN** they review the English translation and click "Approve"
- **THEN** the translation status for `omschrijving.en` SHALL change to `approved`

#### Scenario: Filter objects by translation status

@e2e exclude REST API filter — covered by Newman

- **GIVEN** a register with 100 objects with translatable properties
- **WHEN** a user filters the object list by `_translationStatus=outdated&_translationLanguage=en`
- **THEN** only objects with at least one English property marked `outdated` SHALL be returned

#### Scenario: Translation status stored in object metadata
@e2e exclude backend persistence format — covered by PHPUnit
- **GIVEN** an object with translatable properties
- **WHEN** the object is persisted
- **THEN** translation statuses SHALL be stored in the object JSON under a `_translationMeta` key: `{"_translationMeta": {"omschrijving": {"en": {"status": "approved", "updatedAt": "2026-03-19T10:00:00Z"}}}}`
- **AND** the `_translationMeta` key SHALL NOT appear in regular API responses unless `_translations=all` is requested

### Requirement: Bulk translation operations MUST be supported @e2e exclude REST API bulk translation — covered by Newman

The system MUST support translating multiple objects or multiple properties in a single operation, enabling efficient batch workflows for translators.

#### Scenario: Bulk update translations for a language
- **GIVEN** 50 objects in schema `producten` with translatable property `naam`
- **WHEN** a user sends `PATCH /api/objects/{register}/{schema}/bulk` with `{"_bulkLanguage": "en", "objects": [{"id": "uuid-1", "naam": "Widget A"}, {"id": "uuid-2", "naam": "Widget B"}]}`
- **THEN** the system SHALL update only the English translation of `naam` for each specified object
- **AND** existing Dutch values SHALL remain unchanged

#### Scenario: Bulk export untranslated objects
- **GIVEN** a register with 200 objects, 50 of which lack English translations
- **WHEN** a user requests `GET /api/objects/{register}/{schema}?_translationStatus=missing&_translationLanguage=en&_format=csv`
- **THEN** the response SHALL contain only the 50 objects missing English translations
- **AND** the CSV SHALL include columns for both Dutch source text and empty English columns for each translatable property

#### Scenario: Bulk mark translations as approved
- **GIVEN** 20 objects with English translations in `needs_review` status
- **WHEN** a user sends `PATCH /api/objects/{register}/{schema}/bulk` with `{"_bulkAction": "approveTranslations", "language": "en", "ids": ["uuid-1", "uuid-2", ...]}`
- **THEN** all 20 objects SHALL have their English translation statuses set to `approved`

### Requirement: Import and export MUST preserve translations @e2e exclude backend import/export translation preservation — covered by PHPUnit

Data import and export operations (CSV, Excel, JSON, XML) MUST handle translatable properties correctly, preserving language variants. This cross-references the `data-import-export` spec.

#### Scenario: JSON export includes all translations
- **GIVEN** an object with `omschrijving`: `{"nl": "Paspoort aanvragen", "en": "Passport application"}`
- **WHEN** the user exports to JSON format
- **THEN** the exported JSON SHALL preserve the language-keyed structure: `{"omschrijving": {"nl": "Paspoort aanvragen", "en": "Passport application"}}`

#### Scenario: CSV export flattens translations to columns
- **GIVEN** an object with translatable property `omschrijving` and register languages `["nl", "en"]`
- **WHEN** the user exports to CSV format
- **THEN** the CSV SHALL contain separate columns: `omschrijving_nl`, `omschrijving_en`
- **AND** each column SHALL contain the respective language's value

#### Scenario: JSON import with translations
- **GIVEN** a JSON file containing `[{"omschrijving": {"nl": "Paspoort aanvragen", "en": "Passport application"}}]`
- **WHEN** the user imports this file into a schema with `omschrijving` marked as `translatable: true`
- **THEN** the system SHALL store both language variants correctly
- **AND** `TranslationHandler::normalizeTranslationsForSave()` SHALL validate the language keys against the register's configured languages

#### Scenario: CSV import with language columns
- **GIVEN** a CSV file with columns `naam_nl`, `naam_en`, `code`
- **WHEN** the user imports this file into a schema where `naam` is translatable
- **THEN** the importer SHALL detect the `_nl` and `_en` suffixes and construct the language-keyed object `{"naam": {"nl": "...", "en": "..."}}`
- **AND** `code` (non-translatable) SHALL be imported as a simple value

#### Scenario: Export in single language
- **GIVEN** an export request with header `Accept-Language: en`
- **WHEN** the user exports to CSV without `_translations=all`
- **THEN** the CSV SHALL contain a single `omschrijving` column with the English value (or Dutch fallback)
- **AND** the export behavior SHALL be consistent with the API language negotiation

### Requirement: Search MUST support cross-language and language-specific queries @e2e exclude backend search language filtering — covered by PHPUnit

Full-text search MUST be able to search across all language variants of translatable properties, or within a specific language. The search index MUST use language-appropriate analyzers (stemmers, tokenizers) per language.

#### Scenario: Search across all languages (default)
- **GIVEN** objects with `omschrijving.nl` = `"omgevingsvergunning"` and `omschrijving.en` = `"environmental permit"`
- **WHEN** the user searches for `"permit"` without specifying a language filter
- **THEN** the search SHALL match the English translation
- **AND** the search SHALL also match if the user searches for `"omgevingsvergunning"`

#### Scenario: Search in specific language
- **GIVEN** objects with Dutch and English descriptions
- **WHEN** the user searches with query `vergunning` and parameter `_searchLanguage=nl`
- **THEN** only Dutch content SHALL be searched
- **AND** Dutch stemming/analysis MUST be applied (e.g., `vergunning` matches `vergunningen`)

#### Scenario: Search results include language metadata
- **GIVEN** a search query that matches an English translation
- **WHEN** the results are returned
- **THEN** each result SHALL indicate which language(s) matched
- **AND** the matched snippet SHALL be from the matching language

#### Scenario: Magic table indexing for translatable properties
- **GIVEN** a schema with translatable property `naam` and register default language `nl`
- **WHEN** the magic table column for `naam` is populated by `SchemaMapper`
- **THEN** the indexed column value SHALL contain the default language value for sorting and filtering
- **AND** a supplementary index entry SHALL be created for each additional language to support cross-language search

#### Scenario: Faceting on translatable properties
- **GIVEN** a faceted search request on translatable property `categorie` with register languages `["nl", "en"]`
- **WHEN** facet values are aggregated
- **THEN** facets SHALL use the language matching the `Accept-Language` header
- **AND** facet counts SHALL aggregate across all language variants (a single object with `categorie.nl` and `categorie.en` counts once)

### Requirement: RTL language support MUST be handled in the UI

When a register includes RTL (right-to-left) languages such as Arabic (`ar`) or Hebrew (`he`), the UI MUST render those language tabs and input fields with appropriate text direction.

#### Scenario: Arabic language tab renders RTL
- **GIVEN** register languages `["nl", "ar"]` and a translatable property `omschrijving`
- **WHEN** the user switches to the "AR" language tab
- **THEN** the text input field SHALL have `dir="rtl"` and `lang="ar"` attributes
- **AND** the text SHALL be right-aligned

#### Scenario: Mixed LTR/RTL in side-by-side mode
- **GIVEN** side-by-side translation mode with Dutch (LTR) on the left and Arabic (RTL) on the right
- **WHEN** both panels are displayed
- **THEN** the Dutch panel SHALL render LTR and the Arabic panel SHALL render RTL
- **AND** each panel SHALL correctly handle its text direction independently

#### Scenario: RTL detection based on language code
- **GIVEN** a register with various language codes
- **WHEN** the UI renders language tabs
- **THEN** the system SHALL detect RTL languages from a known list (ar, he, fa, ur, etc.)
- **AND** apply `dir="rtl"` automatically without manual configuration

### Requirement: Translation completeness tracking MUST be available per object and per register

The system MUST track and expose translation completeness metrics at both the object level and the register level, enabling administrators to monitor translation progress.

#### Scenario: Object-level translation completeness

@e2e exclude backend computation — covered by PHPUnit

- **GIVEN** an object with 4 translatable properties and register languages `["nl", "en", "de"]`
- **AND** all 4 properties have Dutch values, 3 have English values, and 1 has a German value
- **WHEN** the object completeness is calculated
- **THEN** the completeness SHALL be: `{"nl": 100, "en": 75, "de": 25}` (percentages)

#### Scenario: Register-level translation dashboard
- **GIVEN** register `producten` with 100 objects, each with 3 translatable properties, and languages `["nl", "en"]`
- **WHEN** the admin views the register translation dashboard
- **THEN** the dashboard SHALL show aggregate completeness: e.g., "EN: 240/300 fields translated (80%)"
- **AND** the dashboard SHALL list the objects with the most missing translations first

#### Scenario: Translation completeness in object list view
- **GIVEN** the object list view in the admin UI
- **WHEN** the admin enables the "Translation status" column
- **THEN** each row SHALL show translation completeness indicators (e.g., color-coded badges per language)
- **AND** the list SHALL be sortable by translation completeness

#### Scenario: API endpoint for translation statistics

@e2e exclude REST API statistics — covered by Newman

- **GIVEN** register `producten` with schema `producten`
- **WHEN** the admin calls `GET /api/registers/{id}/translation-stats`
- **THEN** the response SHALL include `{"languages": {"nl": {"total": 300, "translated": 300, "percentage": 100}, "en": {"total": 300, "translated": 240, "percentage": 80}}}`

#### Scenario: Completeness excludes non-translatable properties

@e2e exclude backend computation rule — covered by PHPUnit

- **GIVEN** a schema with 5 properties, 3 of which are translatable
- **WHEN** completeness is calculated
- **THEN** only the 3 translatable properties SHALL be counted in the metric
- **AND** non-translatable properties SHALL be ignored

### Requirement: Content-Language vs UI language MUST be clearly distinguished

The system MUST maintain a clear separation between the user's Nextcloud interface language (controlled by Nextcloud user settings and `IL10N`) and the object content language (controlled by `Accept-Language` header and register configuration). These two language contexts MUST NOT interfere with each other.

#### Scenario: Dutch user editing English content
- **GIVEN** a Nextcloud user with locale set to `nl` (Dutch UI)
- **WHEN** the user edits an object and selects the "EN" language tab for content
- **THEN** all UI labels (buttons, form labels, navigation) SHALL remain in Dutch
- **AND** the object content fields SHALL accept and display English text
- **AND** the "Save" button text SHALL be `"Opslaan"` (Dutch UI) regardless of the content language

#### Scenario: API response separates concerns
@e2e exclude REST API language separation — covered by Newman
- **GIVEN** a request with `Accept-Language: en` from a user with Nextcloud locale `nl`
- **WHEN** the API returns an object with a validation error
- **THEN** the object's translatable properties SHALL be resolved to English (content language)
- **AND** the error message SHALL be in Dutch (UI language via IL10N and user locale)

#### Scenario: Language selection persists per-session
- **GIVEN** a user editing objects in the "EN" content language tab
- **WHEN** the user navigates to a different object in the same register
- **THEN** the "EN" tab SHALL remain selected (content language preference persists in the session)
- **AND** the Nextcloud UI language SHALL remain unchanged

### Requirement: Admin UI MUST provide register language management

The register settings page MUST include a language configuration section where administrators can add, remove, and reorder languages for a register.

#### Scenario: Add a language to a register
- **GIVEN** the register settings page for register `producten` with current languages `["nl", "en"]`
- **WHEN** the admin clicks "Add language" and selects "Deutsch (de)"
- **THEN** the register's languages SHALL update to `["nl", "en", "de"]`
- **AND** existing objects SHALL NOT be modified

#### Scenario: Remove a language from a register
- **GIVEN** register `producten` with languages `["nl", "en", "de"]` and 50 objects with German translations
- **WHEN** the admin removes "de" from the language list
- **THEN** the register's languages SHALL update to `["nl", "en"]`
- **AND** existing German translations in objects SHALL be preserved in storage (soft removal)
- **AND** a confirmation dialog SHALL warn: `t('openregister', 'Removing a language does not delete existing translations. They will be hidden but preserved.')`

#### Scenario: Cannot remove the default language
- **GIVEN** register `producten` with languages `["nl", "en"]` where `nl` is the default (first in list)
- **WHEN** the admin attempts to remove `nl`
- **THEN** the action SHALL be blocked with message: `t('openregister', 'The default language cannot be removed. Change the default language first.')`

#### Scenario: Reorder languages to change default
- **GIVEN** register `producten` with languages `["nl", "en"]`
- **WHEN** the admin reorders to `["en", "nl"]`
- **THEN** `Register::getDefaultLanguage()` SHALL now return `"en"`
- **AND** new objects created without explicit language keys SHALL have their simple values stored under `"en"`

#### Scenario: Language selector shows native names
- **GIVEN** the language configuration UI
- **WHEN** the admin browses available languages
- **THEN** each language SHALL be displayed with its native name and code: "Nederlands (nl)", "English (en)", "Deutsch (de)", "Francais (fr)"
- **AND** the list SHALL include all ISO 639-1 languages

### Requirement: GraphQL API MUST support language negotiation @e2e exclude GraphQL backend — covered by PHPUnit

The GraphQL endpoint MUST support the same language negotiation as the REST API, using either the `Accept-Language` header or a `language` query argument on translatable fields.

#### Scenario: GraphQL query with Accept-Language header
- **GIVEN** a GraphQL query `{ objects(register: "producten", schema: "producten") { naam omschrijving } }`
- **WHEN** the request includes `Accept-Language: en`
- **THEN** `naam` and `omschrijving` SHALL be resolved to their English values (or fallback)

#### Scenario: GraphQL field-level language argument
- **GIVEN** a GraphQL query `{ objects(register: "producten", schema: "producten") { naam(language: "en") omschrijving(language: "nl") } }`
- **WHEN** the query is executed
- **THEN** `naam` SHALL be resolved to English and `omschrijving` SHALL be resolved to Dutch
- **AND** field-level language arguments SHALL override the `Accept-Language` header

#### Scenario: GraphQL all translations query
- **GIVEN** a GraphQL query `{ objects(register: "producten", schema: "producten") { naam(translations: ALL) } }`
- **WHEN** the query is executed
- **THEN** `naam` SHALL return the full language-keyed object: `{"nl": "...", "en": "..."}`

### Requirement: Translations MUST interact correctly with $ref properties and relations @e2e exclude backend $ref/relation handling — covered by PHPUnit

Properties that use `$ref` to reference other objects SHALL NOT be translatable themselves (the reference ID is language-independent). However, when a referenced object is resolved inline, its translatable properties SHALL be resolved according to the current language context.

#### Scenario: Reference property is language-independent
- **GIVEN** property `eigenaar` with `$ref: "#/schemas/personen"` on schema `producten`
- **WHEN** the admin attempts to mark `eigenaar` as `translatable: true`
- **THEN** the system SHALL reject this with an error: `t('openregister', 'Reference properties cannot be translatable')`

#### Scenario: Resolved reference inherits language context
- **GIVEN** an object in schema `producten` referencing a `personen` object with translatable property `naam`
- **WHEN** the `producten` object is rendered with `_extend[]=eigenaar` and `Accept-Language: en`
- **THEN** the resolved `personen` object's `naam` SHALL be in English (or fallback)
- **AND** the language resolution SHALL apply recursively to all extended references

#### Scenario: Reference list with mixed translation completeness
- **GIVEN** a `producten` object referencing 3 `categorie` objects, 2 with English translations and 1 without
- **WHEN** the list is rendered with `Accept-Language: en`
- **THEN** the 2 translated categories SHALL show English names
- **AND** the 1 untranslated category SHALL show the Dutch fallback name

### Requirement: The translations sidecar MUST expose a REST surface for search, per-object retrieval, status promotion, and bulk machine-translation @e2e exclude backend REST sidecar surface — covered by Newman/PHPUnit

`TranslationController` MUST provide the HTTP surface over the translation sidecar (the `register-i18n` storage layer covers the data model; this requirement covers the API).

- `search` MUST accept optional `query` (full-text), `language`, `status`, `objectUuid`, and `limit` (clamped to 1..1000, default 100), delegate to `TranslationStatusService::search()`, and return `{results, count}`.
- `showByObject` MUST return every translation slot for one object via `TranslationMapper::findByObject($uuid)`, plus a `completeness` block computed against the schema's translatable-property total when a `schema` ref (id/uuid/slug) is supplied and resolvable; the response MUST be `{translations, completeness}`. An unresolvable or absent schema MUST yield an empty `completeness` (non-fatal).
- `setStatus` MUST promote / change the workflow status of one `(uuid, property, language)` slot via `TranslationStatusService::setStatus()`, returning HTTP 400 when `status` is missing or when the service throws `InvalidArgumentException`, otherwise the updated row.
- `bulkTranslate` MUST require `from` and `to` language codes (HTTP 400 otherwise), load the object by uuid (HTTP 404 when not found), delegate to `BulkTranslationService::translateObject()` with the optional `properties` whitelist, and return `{uuid, from, to, translated, skipped}`.

#### Scenario: Search clamps the limit
- **GIVEN** a `search` request with `limit=99999`
- **WHEN** the controller builds the query
- **THEN** the effective limit MUST be clamped to 1000 before calling `TranslationStatusService::search()`
- **AND** the response MUST be `{results, count}`

#### Scenario: Per-object slots with completeness
- **GIVEN** an object with translation slots and a resolvable `schema` ref
- **WHEN** `showByObject` is called with that `schema`
- **THEN** the response MUST include `translations` (serialized slots) and a non-empty `completeness` block
- **AND** when `schema` is omitted or unresolvable, `completeness` MUST be empty and the call MUST still succeed

#### Scenario: Status promotion validates input
- **GIVEN** a `setStatus` request with no `status`
- **THEN** the response MUST be HTTP 400 with `{error: "status is required"}`
- **AND** an `InvalidArgumentException` from the service MUST also map to HTTP 400 with the exception message

#### Scenario: Bulk translate requires from and to and an existing object
- **GIVEN** a `bulkTranslate` request missing `from` or `to`
- **THEN** the response MUST be HTTP 400 with `{error: "from and to are required"}`
- **AND** a request for a non-existent object uuid MUST return HTTP 404 with `{error: "object not found", uuid}`
- **AND** a valid request MUST return `{uuid, from, to, translated, skipped}`

### Requirement: The system MUST project translatable content into the sidecar on object lifecycle events @e2e exclude backend lifecycle projection (create/update/transition/delete listeners) — covered by PHPUnit

The system MUST keep the `openregister_translations` sidecar in sync with translatable object content by reacting to object lifecycle events. `TranslationProjectionListener` MUST subscribe to `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`, and `ObjectTransitionedEvent`. On create, update, and transition it MUST project the object's translatable content into the sidecar via `TranslationProjectionService::project($object)`; on delete it MUST remove the object's sidecar rows via `TranslationProjectionService::purge($object)`.

#### Scenario: Project on object creation
- **GIVEN** an `ObjectCreatedEvent` carrying an `ObjectEntity`
- **WHEN** `TranslationProjectionListener::handle()` processes it
- **THEN** it MUST call `TranslationProjectionService::project($object)`

#### Scenario: Re-project on update using the new object state
- **GIVEN** an `ObjectUpdatedEvent`
- **WHEN** `handle()` processes it
- **THEN** it MUST read the new state via `getNewObject()`
- **AND** call `TranslationProjectionService::project($object)`

#### Scenario: Re-project on transition
- **GIVEN** an `ObjectTransitionedEvent` carrying an `ObjectEntity`
- **WHEN** `handle()` processes it
- **THEN** it MUST call `TranslationProjectionService::project($object)`

#### Scenario: Purge on deletion
- **GIVEN** an `ObjectDeletedEvent` carrying an `ObjectEntity`
- **WHEN** `handle()` processes it
- **THEN** it MUST call `TranslationProjectionService::purge($object)` to remove the object's sidecar translation rows

#### Scenario: Non-ObjectEntity payload is ignored
- **GIVEN** a lifecycle event whose payload is not an `ObjectEntity`
- **WHEN** `handle()` processes it
- **THEN** neither `project()` nor `purge()` MUST be called (the `instanceof ObjectEntity` guard short-circuits)

#### Notes
- The projection sidecar (`openregister_translations`) and `TranslationProjectionService` are introduced by the in-flight `i18n-source-of-truth` / `i18n-api-language-negotiation` changes; this listener is the reactive write-side that those changes rely on.

### Requirement: A translation sidecar MUST be projected from the authoritative object JSONB @e2e exclude backend sidecar projection from JSONB — covered by PHPUnit

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

### Requirement: Translation workflow status and completeness MUST be queryable through the sidecar @e2e exclude backend sidecar status/completeness queries — covered by Newman/PHPUnit

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

### Requirement: Machine translation MUST fill empty slots through a pluggable provider @e2e exclude backend pluggable MT provider strategy — covered by PHPUnit

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

### Requirement: CSV import/export MUST round-trip translations via field-language columns @e2e exclude backend CSV flatten/unflatten round-trip — covered by PHPUnit

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

## Current Implementation Status

**Partially implemented.** Core infrastructure for register-level i18n exists:

- `LanguageService` (request-scoped singleton) stores preferred language, accepted languages, fallback state, and `_translations=all` flag. Includes `parseAcceptLanguageHeader()` per RFC 9110 and `resolveLanguageForRegister()` with fallback chain.
- `LanguageMiddleware` intercepts all requests to parse `Accept-Language` header and `_translations` query parameter. Adds `Content-Language` and `X-Content-Language-Fallback` response headers.
- `TranslationHandler` provides `getTranslatableProperties()` (reads `translatable: true` from schema properties), `resolveTranslationsForRender()` (resolves language-keyed objects to single values for rendering), and `normalizeTranslationsForSave()` (wraps simple values under default language).
- `Register` entity has `getLanguages()`, `setLanguages()`, `getDefaultLanguage()`, and `hasLanguage()` methods. The `languages` column stores an array of BCP 47 codes with the first element as the default.
- `RenderObject` calls `TranslationHandler::resolveTranslationsForRender()` during object rendering.
- `SaveObject` calls `TranslationHandler::normalizeTranslationsForSave()` during object persistence.
- `Application` registers `LanguageService` as a singleton and `LanguageMiddleware` as middleware.

**Capabilities shipped via companion changes** (`i18n-source-of-truth` + `i18n-api-language-negotiation`):

- Schema-property `sourceLanguage` modifier + object-level `_translationMeta.<prop>.sourceLanguage` override (per `i18n-source-of-truth`).
- `openregister_translations.source_language` column + back-fill migration + `occ openregister:translations:backfill-source-language` command (per `i18n-source-of-truth`).
- Automatic `outdated` flip on source-value change via `TranslationStatusService::markDerivedTranslationsOutdated` wired into `SaveObject` (per `i18n-source-of-truth`).
- Translation query filters `?sourceLanguage=`, `?isOutOfDate=true`, `?compareToSource=true` on `GET /api/translations/search` (per `i18n-source-of-truth`).
- `?_translationMeta=true` opt-in `_meta.languageMeta` render envelope on object responses (per `i18n-source-of-truth`).
- `X-Source-Language` response header on object content (per `i18n-source-of-truth`).
- `?_lang=<bcp47>` + `?language=<bcp47>` query parameters with priority over `Accept-Language` (per `i18n-api-language-negotiation`).
- Write-side `X-Translation-Target-Language` header for scalar-body PATCH/PUT/POST (per `i18n-api-language-negotiation`).
- `TranslationTargetConflictException` → `400` on conflicting language-keyed body + target-language header (per `i18n-api-language-negotiation`).

**Not yet implemented:**
- UI language tabs and translation editor in the object edit form
- Translation completeness tracking dashboard
- Bulk translation operations
- Import/export with translation-aware column handling (CSV `_nl` / `_en` suffixes)
- Language-specific search indexing and cross-language search
- RTL language support in the UI
- GraphQL language arguments on translatable fields
- Admin UI for register language management (the data model supports it, but no UI exists)
- Validation that `$ref` properties cannot be translatable

## Standards & References

- EU Single Digital Gateway (SDG) Regulation (EU) 2018/1724 -- requires cross-border service information in at least one EU language beyond the national language
- ADR-005: Internationalization -- Dutch and English Required (company-wide decision, `openspec/architecture/adr-005-i18n-requirement.md`)
- HTTP `Accept-Language` header (RFC 9110, Section 12.5.4)
- HTTP `Content-Language` header (RFC 9110, Section 8.5)
- BCP 47 / RFC 5646 language tags (e.g., `nl`, `en`, `de`, `ar`)
- JSON-LD `@language` context for multilingual linked data
- Common Ground API design rules (NL GOV) -- recommend language negotiation via Accept-Language
- W3C Internationalization best practices (https://www.w3.org/International/)
- Nextcloud `IL10N` / `IFactory` -- `\OCP\IL10N\IFactory::get('openregister', $lang)` for UI string translations
- Nextcloud `@nextcloud/l10n` -- `translate as t`, `translatePlural as n` for Vue frontend (see `i18n-infrastructure` spec)
- Unicode CLDR for language native names and RTL detection

## Cross-References

- `i18n-infrastructure` -- Vue frontend l10n setup (mixin, imports, directory structure)
- `i18n-string-extraction` -- Rules for wrapping translatable UI strings with `t()` / `$l->t()`
- `i18n-backend-messages` -- PHP controller/service message translation via `IL10N`
- `i18n-dutch-translations` -- Dutch translation completeness and terminology consistency
- `data-import-export` -- Import/export pipeline must handle translatable property columns
- `row-field-level-security` -- Property-level RBAC may restrict translation editing per language
