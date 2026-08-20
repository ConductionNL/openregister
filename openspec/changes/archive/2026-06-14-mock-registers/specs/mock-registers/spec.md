---
status: draft
---
# Mock Registers

## Purpose
Provide self-contained mock registers for the five Dutch base registries -- BRP (persons), KVK (businesses), BAG (addresses/buildings), DSO (environmental permits), and ORI (council information) -- so that Procest, Pipelinq, and other consuming apps can develop and demonstrate integrations without external API credentials, government certificates, or network access. Each register ships as a `*_register.json` file in `lib/Settings/` following the OpenAPI 3.0.0 + `x-openregister` extension pattern, with seed data in the `components.objects[]` array using the `@self` envelope format, imported via the `ConfigurationService -> ImportHandler` pipeline.

## ADDED Requirements


### Requirement: KVK Mock Register (Kamer van Koophandel)

The system SHALL provide a mock KVK register with fictional business records aligned to the KVK Handelsregister API data model. Seed data MUST be derived from the official KVK test environment (`https://api.kvk.nl/test/api/`). The register MUST contain at least 15 `maatschappelijke-activiteit` records and at least 8 `vestiging` records covering legal forms BV, NV, Eenmanszaak, Stichting, VOF, and Cooperatie, spanning at least 4 provinces. At least one business MUST have `materieleRegistratie.datumEinde` set (inactive business). Addresses SHOULD link to BAG mock data where possible.

#### Scenario: Load KVK register with two schemas
- **GIVEN** the file `lib/Settings/kvk_register.json` exists
- **WHEN** the register is imported via the ImportHandler
- **THEN** the system SHALL create a register with slug `kvk` containing two schemas: `maatschappelijke-activiteit` and `vestiging`
- **AND** the vestiging objects SHALL reference their parent maatschappelijke-activiteit via `kvkNummer`

#### Scenario: Legal form diversity
- **GIVEN** the KVK register is loaded
- **WHEN** the seed data is queried by `rechtsvorm`
- **THEN** at least the following legal forms MUST be present: Besloten Vennootschap, Naamloze Vennootschap, Eenmanszaak, Stichting, Vennootschap Onder Firma, Cooperatie

#### Scenario: Hoofdvestiging and nevenvestiging relationship
- **GIVEN** a maatschappelijke-activiteit record for Test BV Donald (KVK 68750110)
- **WHEN** the associated vestiging records are queried by `kvkNummer`
- **THEN** exactly one vestiging MUST have `indHoofdvestiging` set to "Ja"
- **AND** any additional vestigingen MUST have `indHoofdvestiging` set to "Nee"

#### Scenario: SBI activity codes present
- **GIVEN** any maatschappelijke-activiteit record in the KVK register
- **WHEN** the `sbiActiviteiten` array is inspected
- **THEN** it MUST contain at least one entry with valid `sbiCode`, `sbiOmschrijving`, and `indHoofdactiviteit` fields
- **AND** exactly one entry per business MUST have `indHoofdactiviteit` set to "Ja"

#### Scenario: KVK addresses link to BAG
- **GIVEN** the KVK and BAG registers are both loaded
- **WHEN** at least 3 vestiging records are inspected
- **THEN** their `adressen[].straatnaam`, `huisnummer`, and `postcode` combinations MUST match corresponding BAG `nummeraanduiding` records


### Requirement: Cross-Register Referencing Integrity

Mock register data MUST be cross-referenced where the same real-world entity appears in multiple registers. BRP person addresses MUST link to BAG via `adresseerbaarObjectIdentificatie` and `nummeraanduidingIdentificatie`. KVK vestiging addresses MUST match BAG nummeraanduiding records by postcode + huisnummer. DSO vergunningaanvraag locations MUST reference BAG municipality codes. At minimum: 5 BRP-BAG links, 3 KVK-BAG links, and 3 DSO-BAG links MUST exist.

#### Scenario: BRP person address resolves in BAG
- **GIVEN** person Suzanne Moulin (BSN 999993653) in the BRP register
- **WHEN** her `verblijfplaats.adresseerbaarObjectIdentificatie` is looked up in the BAG register
- **THEN** a matching `verblijfsobject` record MUST exist
- **AND** the verblijfsobject's associated nummeraanduiding postcode and woonplaats MUST match the BRP person's verblijfplaats.postcode and verblijfplaats.woonplaats

#### Scenario: KVK business address resolves in BAG
- **GIVEN** a KVK vestiging record with a bezoekadres
- **WHEN** the address (straatnaam + huisnummer + postcode) is searched in the BAG register's nummeraanduiding records
- **THEN** a matching nummeraanduiding record MUST exist
- **AND** the nummeraanduiding's openbareRuimteNaam MUST match the vestiging's straatnaam

#### Scenario: Cross-register import order independence
- **GIVEN** the BAG register has NOT yet been imported
- **WHEN** the BRP register is imported first (containing BAG cross-references)
- **THEN** the import SHALL succeed without errors
- **AND** BAG reference fields SHALL be stored as-is (dangling references are acceptable until BAG is imported)
- **AND** once BAG is subsequently imported, the references SHALL become resolvable


### Requirement: Data Realism and Quality

Seed data MUST be realistic enough for meaningful demonstrations and integration testing. Person names MUST include typical Dutch naming patterns (voorvoegsel like "de", "van der", "van den"). Business names MUST use recognizable formats. Addresses MUST use real Dutch street names, valid postcodes (format ####XX), and correct municipality assignments. Dates MUST be temporally consistent (birth dates before marriage dates, registration dates in logical order). No field that would be non-null in production SHALL be left empty in seed data without an explicit reason documented in the spec.

#### Scenario: Dutch naming conventions in BRP data
- **GIVEN** the BRP seed data is loaded
- **WHEN** person names are inspected
- **THEN** at least 3 persons MUST have a `voorvoegsel` value (e.g. "de", "van", "van der")
- **AND** at least 1 person MUST demonstrate `aanduidingNaamgebruik` other than "E" (eigen geslachtsnaam)

#### Scenario: Valid Dutch postcodes
- **GIVEN** any address in BRP, KVK, or BAG seed data
- **WHEN** the `postcode` field is inspected
- **THEN** it MUST match the pattern `[1-9][0-9]{3}[A-Z]{2}` (four digits starting with non-zero, two uppercase letters)

#### Scenario: Temporal consistency of dates
- **GIVEN** a BRP person record with geboorte, partners (with verbintenis date), and kinderen
- **WHEN** the dates are compared
- **THEN** the person's geboortedatum MUST precede any partner verbintenis date
- **AND** the person's geboortedatum MUST precede any child's geboortedatum
- **AND** if overlijden is present, overlijden.datum MUST be after geboortedatum


### Requirement: Performance with Mock Data Loaded

The system MUST maintain acceptable performance with all five mock registers loaded simultaneously. The total seed data volume (approximately 250+ objects across 5 registers and 15+ schemas) MUST NOT degrade normal CRUD operations. Object listing with pagination (`_limit=20`, `_offset=0`) on a register with 35+ objects SHALL respond within 500ms. The SchemaMapper and RegisterMapper lookups used during import SHALL be cached by the ObjectService to avoid repeated database queries.

#### Scenario: Object listing performance with loaded mock data
- **GIVEN** all five mock registers are loaded (approximately 250+ objects total)
- **WHEN** a paginated list request is made: `GET /api/objects/{brp_register_id}/{person_schema_id}?_limit=20&_offset=0`
- **THEN** the response SHALL be returned within 500ms
- **AND** the response SHALL include correct pagination metadata (total count, page info)

#### Scenario: Search performance across mock data
- **GIVEN** all five mock registers are loaded
- **WHEN** a full-text search is performed: `GET /api/objects/{brp_register_id}/{person_schema_id}?_search=Rotterdam`
- **THEN** the response SHALL be returned within 1000ms
- **AND** results SHALL include all persons with Rotterdam in their verblijfplaats

#### Scenario: Import performance for largest register
- **GIVEN** the ORI register file contains approximately 115 seed objects across 6 schemas
- **WHEN** the register is imported via `occ openregister:load-register`
- **THEN** the full import (register + schemas + objects) SHALL complete within 60 seconds
- **AND** no PHP memory limit errors SHALL occur with the default 512MB memory limit


### Requirement: Mock Register Reset and Refresh

The system MUST support resetting mock registers to their original state. Administrators MUST be able to delete all data from a specific mock register and re-import it from the JSON file. The reset operation MUST remove all objects, then re-import from the source file. The system SHOULD support selective reset (single register) and bulk reset (all mock registers).

#### Scenario: Reset single mock register
- **GIVEN** the BRP mock register has been loaded and some objects have been modified or deleted by users
- **WHEN** the administrator runs `occ openregister:load-register --force /var/www/html/custom_apps/openregister/lib/Settings/brp_register.json`
- **THEN** all modified objects SHALL be restored to their original seed data state
- **AND** the object count SHALL match the original JSON file's object count

#### Scenario: Reset does not affect non-mock registers
- **GIVEN** the system contains both mock registers (type: "mock") and production registers
- **WHEN** a mock register reset operation is performed
- **THEN** only objects in the targeted mock register SHALL be affected
- **AND** all production registers and their objects SHALL remain untouched

#### Scenario: Reset via API endpoint
- **GIVEN** an authenticated administrator session
- **WHEN** a POST request is made to `/api/registers/import` with the mock register JSON body
- **THEN** the import SHALL succeed with the same result as the OCC command
- **AND** the response SHALL include counts of created, updated, and skipped records


### Requirement: I18n of Mock Register Content

Mock register metadata (register title, description, schema descriptions) MUST support Dutch and English per ADR-005. User-facing labels in the register and schema definitions SHALL use Nextcloud's `t()` translation system where displayed in the UI. The seed data content itself (person names, business names, addresses) MUST remain in Dutch as it represents Dutch government base registry data, but schema property descriptions SHOULD be bilingual. See also: `register-i18n` spec for the full i18n data model.

#### Scenario: Register title displayed in user's locale
- **GIVEN** the BRP register has title "BRP (Basisregistratie Personen)"
- **WHEN** a user with locale `en` views the register list in the OpenRegister UI
- **THEN** the register title SHOULD be displayed as "BRP (Personal Records Database)" or the Dutch title with an English subtitle
- **AND** the register description SHOULD be available in both nl and en

#### Scenario: Schema property descriptions bilingual
- **GIVEN** the `ingeschreven-persoon` schema has property `burgerservicenummer`
- **WHEN** the schema is rendered in the UI
- **THEN** the property description SHOULD be available in Dutch ("Burgerservicenummer, voldoet aan 11-proef") and English ("Citizen Service Number, passes 11-check validation")

#### Scenario: Seed data content remains in Dutch
- **GIVEN** a BRP person record for Marianne de Jong
- **WHEN** the object is displayed to a user with locale `en`
- **THEN** the person's name, address, and municipality name SHALL remain in Dutch (these are proper nouns / official registry values)
- **AND** only UI labels, column headers, and navigation elements SHALL be translated


### Requirement: Mock Data Distinguishability

The system MUST provide a mechanism for consuming apps and administrators to distinguish mock/demo data from production data. The `x-openregister.type` field set to `"mock"` on register JSON files MUST be persisted as register metadata. Consuming apps (Pipelinq, Procest) SHOULD be able to query registers by type to filter out mock data in production deployments. The system SHOULD display a visual indicator in the UI when viewing mock register data.

#### Scenario: Filter registers by type via API
- **GIVEN** both mock registers and production registers exist in the system
- **WHEN** a consuming app queries `GET /api/registers?type=mock`
- **THEN** only registers with `x-openregister.type: "mock"` SHALL be returned

#### Scenario: Visual indicator in register list
- **GIVEN** the BRP mock register is loaded
- **WHEN** an administrator views the register list in the OpenRegister admin UI
- **THEN** mock registers SHOULD display a badge or label indicating "Demo" or "Mock"
- **AND** the badge SHOULD be visually distinct (e.g. orange/yellow color) from production registers

#### Scenario: Mock data exclusion in production
- **GIVEN** an administrator has set `mock_registers_enabled` to `false` in IAppConfig
- **WHEN** the app performs its installation/upgrade repair steps
- **THEN** no mock register JSON files SHALL be auto-imported
- **AND** previously imported mock data SHALL NOT be deleted (explicit reset required)


### Requirement: Schema Compliance with ADR-006

All mock register schemas MUST comply with ADR-006 (OpenRegister Schema Standards). Each schema MUST have a unique descriptive name, explicit property types (string, integer, boolean, datetime, array, object), and required property markings. Cross-entity references MUST use OpenRegister's relation mechanism rather than storing foreign keys as plain strings. Where applicable, schemas SHOULD align with schema.org vocabulary (e.g. BRP person maps to schema:Person concepts, KVK business maps to schema:Organization concepts) with a Dutch API mapping layer per ADR-006.

#### Scenario: Property types explicitly defined
- **GIVEN** the `ingeschreven-persoon` schema definition in `brp_register.json`
- **WHEN** the schema's `properties` block is inspected
- **THEN** every property MUST have an explicit `type` (string, integer, boolean, array, object)
- **AND** string properties with restricted values MUST define an `enum` constraint

#### Scenario: Required properties marked
- **GIVEN** the `maatschappelijke-activiteit` schema in `kvk_register.json`
- **WHEN** the schema's `required` array is inspected
- **THEN** it MUST include at minimum: `kvkNummer`, `naam`, `rechtsvorm`

#### Scenario: Schema descriptions present
- **GIVEN** any schema in any mock register JSON file
- **WHEN** the schema definition is inspected
- **THEN** it MUST include a `description` field explaining the entity's purpose
- **AND** the description MUST be at least 20 characters long


### Requirement: Consuming App Discovery

Mock registers MUST be discoverable by consuming apps (Pipelinq, Procest, OpenConnector) without hardcoding register or schema IDs. Consuming apps SHALL look up registers by slug (e.g. `brp`, `kvk`, `bag`) and schemas by slug (e.g. `ingeschreven-persoon`, `maatschappelijke-activiteit`) using the ObjectService or API. The register and schema slugs defined in the mock register JSON files MUST be stable across versions and SHALL NOT change without a major version bump.

#### Scenario: Pipelinq discovers BRP register by slug
- **GIVEN** the BRP mock register is loaded with slug `brp`
- **WHEN** Pipelinq's klantbeeld-360 feature calls `store.getters.getRegisterBySlug('brp')`
- **THEN** the BRP register entity SHALL be returned with its database ID
- **AND** `store.getters.getSchemaBySlug('ingeschreven-persoon')` SHALL return the person schema

#### Scenario: API-based register discovery
- **GIVEN** all mock registers are loaded
- **WHEN** a consuming app queries `GET /api/registers?slug=kvk`
- **THEN** the response SHALL contain exactly one register with slug `kvk`
- **AND** the register's schemas SHALL be accessible via the returned register ID

#### Scenario: Slug stability across versions
- **GIVEN** mock register JSON files at version 1.0.0 define slugs `brp`, `kvk`, `bag`, `dso`, `ori`
- **WHEN** version 1.1.0 of the files is released
- **THEN** the same slugs MUST be preserved
- **AND** any slug change MUST be accompanied by a major version bump and migration documentation


### Requirement: Data Import/Export Integration

Mock register data MUST be compatible with the data-import-export spec's batch import and export capabilities. Seed data loaded from mock register JSON files MUST be exportable via the standard export pipeline (CSV, Excel, JSON formats). Exported mock data MUST be re-importable without data loss. This ensures mock registers serve as both demo data and as templates for creating production registers with similar structures.

#### Scenario: Export mock register to CSV
- **GIVEN** the BRP mock register is loaded with 35 person records
- **WHEN** an administrator exports the register via `GET /api/objects/{register_id}/{schema_id}?_format=csv`
- **THEN** the response SHALL be a valid CSV file with 35 data rows plus a header row
- **AND** all schema properties SHALL appear as column headers

#### Scenario: Round-trip import/export
- **GIVEN** the KVK mock register is loaded
- **WHEN** the maatschappelijke-activiteit objects are exported to JSON and then re-imported into a new register
- **THEN** the re-imported objects SHALL contain identical data to the originals
- **AND** no field values SHALL be lost or truncated during the round-trip

#### Scenario: Mock register as production template
- **GIVEN** an administrator wants to create a production BRP-like register with real data
- **WHEN** they export the BRP mock register's schema definitions (without seed objects)
- **THEN** the exported schema SHALL be usable as a template for creating a new empty register with the same structure
