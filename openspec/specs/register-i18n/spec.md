---
status: partial
---

# register-i18n Specification

## Purpose
Implement multi-language content management for register objects. Schema properties MUST support per-field translations with language negotiation via Accept-Language headers. The system MUST support at minimum Dutch (NL, required) and English (EN, optional) to comply with Single Digital Gateway (SDG) requirements for cross-border EU service access.

**Source**: Gap identified in cross-platform analysis; four platforms implement i18n. SDG compliance requires English availability.

## ADDED Requirements

### Requirement: Schema properties MUST support a translatable flag
Schema property definitions MUST accept a `translatable: true` attribute indicating the field supports multiple language versions.

#### Scenario: Define a translatable property
- GIVEN a schema `producten`
- WHEN the admin marks property `omschrijving` as `translatable: true`
- THEN the system MUST store translations per language for that property
- AND the default language MUST be `nl` (Dutch)

#### Scenario: Non-translatable property
- GIVEN property `code` with `translatable: false` (default)
- THEN the property MUST have a single value regardless of language

### Requirement: Objects MUST store translations per translatable property
Each translatable property MUST store a value per configured language.

#### Scenario: Create object with translations
- GIVEN schema `producten` with translatable property `omschrijving`
- WHEN a user creates an object with:
  - `omschrijving.nl`: `Aanvraag omgevingsvergunning`
  - `omschrijving.en`: `Environmental permit application`
- THEN both translations MUST be stored on the object
- AND the Dutch value MUST be the primary (required) translation

#### Scenario: Create object with only Dutch
- GIVEN a translatable property `omschrijving`
- WHEN a user creates an object with only `omschrijving.nl`: `Paspoort aanvragen`
- THEN the object MUST be created successfully
- AND accessing the English translation MUST return null or the Dutch fallback

### Requirement: The API MUST support language negotiation
API responses MUST return content in the language requested via Accept-Language header, with Dutch as fallback.

#### Scenario: Request English content
- GIVEN an object with `omschrijving.nl` = `Paspoort aanvragen` and `omschrijving.en` = `Passport application`
- WHEN the API receives a request with header `Accept-Language: en`
- THEN the response MUST return `omschrijving` as `Passport application`

#### Scenario: Fallback to Dutch when translation missing
- GIVEN an object with `omschrijving.nl` = `Paspoort aanvragen` and no English translation
- WHEN the API receives a request with header `Accept-Language: en`
- THEN the response MUST return the Dutch value `Paspoort aanvragen`
- AND the response SHOULD include a header indicating fallback was used

#### Scenario: Request all translations
- GIVEN an API request with query parameter `_translations=all`
- WHEN the response is generated
- THEN all translations MUST be included: `{"omschrijving": {"nl": "...", "en": "..."}}`

### Requirement: The UI MUST support editing translations
The object edit form MUST provide a language switcher for translatable fields.

#### Scenario: Edit translations via language tabs
- GIVEN an object with translatable properties
- WHEN the user opens the edit form
- THEN language tabs (NL, EN) MUST be displayed above translatable fields
- AND switching tabs MUST show/edit the translation for that language
- AND non-translatable fields MUST remain visible regardless of selected language

#### Scenario: Indicate missing translations
- GIVEN an object with Dutch content but no English translation
- WHEN the user views the language tabs
- THEN the EN tab MUST show a warning indicator (badge or icon) for missing translations

### Requirement: Search MUST support language-specific indexing
Full-text search MUST use language-appropriate analyzers for each language.

#### Scenario: Search in specific language
- GIVEN objects with Dutch and English descriptions
- WHEN the user searches for `vergunning` with language filter `nl`
- THEN only Dutch content MUST be searched
- AND Dutch stemming/analysis MUST be applied

### Requirement: Languages MUST be configurable per register
Each register MUST define which languages are available and which is the default.

#### Scenario: Configure register languages
- GIVEN register `producten`
- WHEN the admin configures languages: `nl` (default, required), `en` (optional)
- THEN only these languages MUST be available for translation in this register
- AND adding a third language (e.g., `de`) MUST be possible via configuration

### Current Implementation Status

**Not implemented.** No i18n/multi-language content management exists in OpenRegister:

- No `translatable` flag on schema properties
- No per-field translation storage mechanism
- No `Accept-Language` header negotiation in API responses
- No language switcher in the object edit UI
- No language-specific search indexing
- No per-register language configuration

The codebase does use Nextcloud's `IL10N` for UI string translations (app labels, button text), but this is separate from data-level i18n for register object content.

### Standards & References
- EU Single Digital Gateway (SDG) Regulation (EU) 2018/1724 -- requires cross-border service information in at least one EU language beyond the national language
- W3C Internationalization best practices (https://www.w3.org/International/)
- HTTP `Accept-Language` header (RFC 9110, Section 12.5.4)
- HTTP `Content-Language` header (RFC 9110, Section 8.5)
- BCP 47 / RFC 5646 language tags (e.g., `nl`, `en`, `de`)
- JSON-LD `@language` context for multilingual linked data
- Common Ground API design rules (NL GOV) -- recommend language negotiation via Accept-Language

### Specificity Assessment
- **Specific enough to implement?** Partially -- the API and storage behavior is well-defined, but the data model is underspecified.
- **Missing/ambiguous:**
  - No specification for how translations are stored in the database (separate columns? JSON sub-object? separate table?)
  - No specification for how translations interact with `$ref` properties (are references language-independent?)
  - No specification for how translations interact with faceting (facet by Dutch values, English values, or both?)
  - No specification for how translations are handled in CSV/JSON export/import
  - No specification for translation workflow (e.g., mark fields as "needs translation")
  - No specification for how translations interact with RBAC (can a user have write access to NL but not EN?)
- **Open questions:**
  - Should translations be stored as a JSON sub-object per property (e.g., `{"nl": "...", "en": "..."}`) or as separate object versions?
  - How should the MagicMapper (magic tables) handle translatable columns?
  - What is the priority: SDG compliance (NL+EN minimum) or full multi-language support?

## Nextcloud Integration Analysis

**Status**: PARTIALLY IMPLEMENTED

**What Exists**: Multi-organization support exists with flexible schema metadata, meaning the data model can already accommodate additional per-field metadata. Nextcloud's `IL10N` service is used for UI string translations (app labels, button text). The object storage model uses a flexible JSON `object` column that could store per-field translation variants without schema changes. The API layer (`ObjectController`) already processes request headers and could be extended for `Accept-Language` negotiation.

**Gap Analysis**: No `translatable` flag exists on schema properties. No per-field translation storage mechanism is implemented -- objects store single-language values. No `Accept-Language` header negotiation occurs in API responses. No language switcher exists in the object edit UI. No language-specific search indexing or per-register language configuration is available. The gap between UI translations (IL10N) and data-level i18n is complete -- these are entirely separate concerns.

**Nextcloud Core Integration Points**:
- **IL10N / IL10NFactory**: Use `\OCP\IL10N\IFactory::get('openregister', $lang)` for UI-level translations of field labels and schema names. This is already partially used but should be extended to translate schema property display names per language.
- **IRequest / AppFramework Middleware**: Create a custom middleware that reads `Accept-Language` from `\OCP\IRequest::getHeader('Accept-Language')` and parses it per RFC 9110. Store the resolved language in a request-scoped service for use by `RenderObject` when selecting which translation variant to return.
- **IConfig (per-register settings)**: Store available languages and default language per register using `\OCP\IConfig::setAppValue()` with register-scoped keys (e.g., `register_{id}_languages`), or add a `languages` JSON field to the Register entity.
- **Nextcloud Search / ISearchProvider**: When implementing language-specific search indexing, use the existing `ISearchProvider` integration to pass language context to Solr/Elasticsearch analyzers, selecting the appropriate stemmer per language.

**Recommendation**: Implement translations as a JSON sub-object per translatable property (e.g., `{"nl": "Paspoort aanvragen", "en": "Passport application"}`), stored within the existing object JSON column. This avoids database schema changes and works with the current magic table approach by storing the default language value in the indexed column. Add `Accept-Language` middleware in AppFramework to resolve the requested language early in the request lifecycle. Start with NL+EN to satisfy SDG requirements, with the architecture supporting additional languages via register configuration. The UI language switcher can use Vue tabs above translatable fields, similar to the existing NL Design tab pattern.
