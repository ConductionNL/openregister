# Capability: schema-metadata-i18n

## Purpose

Make schema **metadata** — the `title` and `description` of a schema and of each
of its properties — translatable, so the labels consuming apps render as column
headers and form labels follow the user's language instead of whichever language
the schema author happened to type. Content i18n (`register-i18n`) already does
this for property *values*; this capability extends the same guarantee to the
labels, reusing the existing `Accept-Language` negotiation and the register's
declared `languages`.

## ADDED Requirements

### Requirement: Schema and property metadata MUST accept a per-language map (REQ-ORSMI-001)

Schema metadata MUST accept either a plain string or a per-language map keyed by
BCP 47 language code — this applies to a schema's `title` and `description` and
to the `title` and `description` of every property. A plain string MUST continue
to be accepted unchanged and MUST be treated as authored in the register's
default language, so no existing schema requires migration.

The register's default language is the first entry of `Register.languages`,
falling back to `nl` when that field is empty — the same rule `register-i18n`
already applies to content.

#### Scenario: A per-language title is accepted and stored

- **GIVEN** a register whose `languages` is `["nl", "en"]`
- **WHEN** a schema declares a property `name` with `"title": {"nl": "Naam", "en": "Name"}`
- **THEN** the schema MUST persist both language variants
- **AND** re-reading the schema definition MUST return both, not just one
- **@e2e** exclude backend schema metadata storage — asserted by PHPUnit over the Schema entity

#### Scenario: A plain-string title keeps working

- **GIVEN** an existing schema whose property `code` has `"title": "Code"`
- **WHEN** the schema is read after this change ships
- **THEN** the title MUST resolve to `Code` for every requested language
- **AND** the stored definition MUST NOT be rewritten into a map implicitly
- **@e2e** exclude backwards-compatibility path — asserted by PHPUnit

### Requirement: Metadata MUST resolve to the requested language on read (REQ-ORSMI-002)

Reading a schema MUST resolve every metadata map to a single string using the
language negotiated for the request, applying this fallback chain in order:
requested language, its base language (`nl` for `nl-NL`), the register's default
language, then the first non-empty entry in the map. Resolution MUST reuse the
existing `Accept-Language` negotiation rather than introducing a second mechanism.

#### Scenario: An English client gets English labels

- **GIVEN** a property whose title is `{"nl": "Niveau", "en": "Level"}`
- **WHEN** a client requests the schema with `Accept-Language: en`
- **THEN** the property title MUST be returned as `Level`
- **@e2e** exclude API-level negotiation — asserted by PHPUnit against the schema read path

#### Scenario: A regional code falls back to its base language

- **GIVEN** the same property and a client requesting `Accept-Language: nl-NL`
- **WHEN** the map has an `nl` entry but no `nl-NL` entry
- **THEN** the property title MUST be returned as `Niveau`
- **@e2e** exclude fallback-chain logic — asserted by PHPUnit

#### Scenario: An unavailable language falls back deterministically

- **GIVEN** the same property and a client requesting `Accept-Language: de`
- **WHEN** the map has no `de` entry and the register default is `nl`
- **THEN** the property title MUST be returned as `Niveau`, never an empty string and never the raw map
- **@e2e** exclude fallback-chain logic — asserted by PHPUnit

### Requirement: The scalar title column MUST always hold the resolved default (REQ-ORSMI-003)

Persisting a schema whose title is a map MUST write the resolved default-language
string into the scalar `oc_openregister_schemas.title` column, and MUST NOT write
null, a JSON blob, or an empty string. That column is `NOT NULL` and carries the
`register_schemas_title_index` btree, so existing listing, sorting and lookup
paths keep working untouched.

#### Scenario: Saving a map-titled schema keeps the column populated

- **GIVEN** a schema saved with `"title": {"nl": "Sjabloon", "en": "Template"}` in a register defaulting to `nl`
- **WHEN** the schema is persisted
- **THEN** the `title` column MUST contain `Sjabloon`
- **AND** listing schemas MUST return that schema with a non-empty title
- **@e2e** exclude persistence invariant — asserted by PHPUnit

### Requirement: Callers MUST be able to read the unresolved map (REQ-ORSMI-004)

A schema read MUST be able to return metadata unresolved, as the full
per-language map, when the caller asks for it (`_metaLanguages=all`). Schema
editors and import/export MUST use this so a round-trip preserves every language
instead of collapsing the schema to the reader's language.

#### Scenario: Export round-trips every language

- **GIVEN** a schema whose property titles carry `nl` and `en` variants
- **WHEN** it is read with `_metaLanguages=all` and re-imported
- **THEN** both variants MUST survive unchanged
- **@e2e** exclude import/export round-trip — asserted by PHPUnit

#### Scenario: Default read stays resolved

- **GIVEN** the same schema
- **WHEN** it is read without `_metaLanguages`
- **THEN** each title MUST be a plain string, so existing consumers never receive an object where they expect text
- **@e2e** exclude API shape — asserted by PHPUnit

### Requirement: Invalid language keys MUST be rejected on write (REQ-ORSMI-005)

Validation MUST fail, naming the offending key, when persisted metadata contains
a language key that is not a well-formed BCP 47 tag, or that is not among the
register's declared `languages` when that list is non-empty. This mirrors how
content i18n validates translatable values and stops silent typos (`"eng"`,
`"NL_nl"`) from producing labels no reader can ever negotiate.

#### Scenario: A malformed language key is refused

- **GIVEN** a register with `languages` `["nl", "en"]`
- **WHEN** a schema is saved with `"title": {"nl": "Naam", "eng": "Name"}`
- **THEN** validation MUST fail and the error MUST name `eng`
- **AND** the schema MUST NOT be persisted
- **@e2e** exclude validation path — asserted by PHPUnit

#### Scenario: A language outside the register's set is refused

- **GIVEN** the same register
- **WHEN** a schema is saved with a `de` variant
- **THEN** validation MUST fail naming `de`, because no reader of that register can negotiate it
- **@e2e** exclude validation path — asserted by PHPUnit
