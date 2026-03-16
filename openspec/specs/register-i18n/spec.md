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
