# Mock Registers

## Purpose

Provide self-contained mock registers for the five Dutch base registries -- BRP (persons), KVK (businesses), BAG (addresses/buildings), DSO (environmental permits), and ORI (council information) -- so that Procest, Pipelinq, and other consuming apps can develop and demonstrate integrations without external API credentials, government certificates, or network access. Each register ships as a `*_register.json` file in `lib/Settings/` following the OpenAPI 3.0.0 + `x-openregister` extension pattern, with seed data in the `components.objects[]` array using the `@self` envelope format, imported via the `ConfigurationService -> ImportHandler` pipeline.

This capability is a key competitive differentiator: competitor products (KISS, Dimpact ZAC, Open Formulieren) all require extensive external infrastructure to run locally. Our mock registers make the entire suite self-contained from `docker compose up`.

## Requirements

### Requirement: BRP Mock Register (Basisregistratie Personen)

The system SHALL provide a mock BRP register with fictional person records aligned to the Haal Centraal BRP Personen Bevragen API v2 data model. Seed data MUST be derived from the official RVIG (Rijksdienst voor Identiteitsgegevens) test dataset. The register MUST contain at least 30 person records selected from the RVIG test dataset, covering at least 5 complete family units with consistent cross-references, spanning at least 6 municipalities (Amsterdam 0363, Rotterdam 0599, Den Haag 0518, Utrecht 0344, Groningen 0014, Almere 0034). All BSNs MUST pass 11-proef validation. The schema `ingeschreven-persoon` MUST include fields for burgerservicenummer, naam (voornamen, voorletters, voorvoegsel, geslachtsnaam, aanduidingNaamgebruik), geslachtsaanduiding, geboorte, nationaliteit, verblijfplaats (with BAG linking fields adresseerbaarObjectIdentificatie and nummeraanduidingIdentificatie), gemeenteVanInschrijving, immigratie, overlijden, partners, ouders, and kinderen.

#### Scenario: Load BRP register from JSON file
- **GIVEN** the file `lib/Settings/brp_register.json` exists with valid OpenAPI 3.0.0 + x-openregister format
- **WHEN** an administrator runs `occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/brp_register.json`
- **THEN** the system SHALL create a register with slug `brp`, a schema `ingeschreven-persoon`, and at least 30 person object records
- **AND** the ConfigurationService ImportHandler SHALL process the `components.objects[]` array using the `@self` envelope to resolve register and schema references

#### Scenario: BSN validation on all seed persons
- **GIVEN** the BRP register has been loaded with seed data
- **WHEN** any person record's `burgerservicenummer` is extracted
- **THEN** the value MUST pass the Dutch 11-proef validation algorithm (weighted sum of digits mod 11 equals 0)
- **AND** the BSN MUST be exactly 9 digits long

#### Scenario: Family unit cross-referencing
- **GIVEN** the BRP register contains the family unit of Stephan Janssen (BSN 999990627)
- **WHEN** the system resolves his `kinderen` array references
- **THEN** each child BSN (999997580, 999995145) MUST correspond to an existing person record in the same register
- **AND** each child's `ouders` array MUST contain a back-reference to BSN 999990627

#### Scenario: Coverage of required demographic scenarios
- **GIVEN** the BRP register is fully loaded
- **WHEN** the seed data is inspected
- **THEN** it MUST include at least one record for each scenario: married couple with children, single parent, deceased person (e.g. Astrid Abels BSN 999999655 with overlijden.datum), foreign national (e.g. Thanatos Olympos BSN 999995091), minor with custody, and person "in onderzoek" (e.g. Jan-Kees Brouwers BSN 999993355)

#### Scenario: Address linking to BAG register
- **GIVEN** the BRP and BAG registers are both loaded
- **WHEN** at least 5 BRP person records are inspected
- **THEN** their `verblijfplaats.adresseerbaarObjectIdentificatie` values MUST match existing BAG `verblijfsobject.identificatie` records
- **AND** their `verblijfplaats.nummeraanduidingIdentificatie` values MUST match existing BAG `nummeraanduiding.identificatie` records

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

### Requirement: BAG Mock Register (Basisregistratie Adressen en Gebouwen)

The system SHALL provide a mock BAG register with address and building records aligned to the Kadaster BAG API v2 / PDOK BAG data model. Seed data MUST be obtained from the PDOK BAG OGC API Features endpoint (`https://api.pdok.nl/kadaster/bag/ogc/v2`), which is freely accessible without authentication. The register MUST contain at least 30 `nummeraanduiding` records, at least 20 `verblijfsobject` records, and at least 15 `pand` records. All BAG IDs MUST follow the official 16-digit format (`GGGGTTNNNNNNNNNN`) with correct municipality codes and object type codes.

#### Scenario: BAG identification format validation
- **GIVEN** any BAG record (nummeraanduiding, verblijfsobject, or pand)
- **WHEN** the `identificatie` field is inspected
- **THEN** it MUST be exactly 16 digits
- **AND** the first 4 digits MUST be a valid Dutch municipality code (e.g. 0363 for Amsterdam)
- **AND** digits 5-6 MUST correspond to the correct object type code (01=Verblijfsobject, 10=Pand, 20=Nummeraanduiding)

#### Scenario: Gebruiksdoel diversity
- **GIVEN** the BAG register is loaded with verblijfsobject records
- **WHEN** the `gebruiksdoel` arrays are aggregated
- **THEN** at least three different gebruiksdoel values MUST be present (at minimum: woonfunctie, kantoorfunctie, winkelfunctie)

#### Scenario: Pand-to-verblijfsobject referencing
- **GIVEN** the BAG register is loaded
- **WHEN** a verblijfsobject record's `pandIdentificatie` is resolved
- **THEN** it MUST match an existing `pand.identificatie` in the same register
- **AND** the pand MUST have a valid `oorspronkelijkBouwjaar` (4-digit year)

#### Scenario: Municipality coverage matches BRP
- **GIVEN** both the BRP and BAG registers are loaded
- **WHEN** the municipality codes in BAG identification prefixes are extracted
- **THEN** they MUST include at minimum the same 6 municipalities as the BRP register (Amsterdam 0363, Rotterdam 0599, Den Haag 0518, Utrecht 0344, Groningen 0014, Almere 0034)

### Requirement: DSO Mock Register (Digitaal Stelsel Omgevingswet)

The system SHALL provide a mock DSO register with environmental permit data aligned to the CIM-OW/IMOW data model. The register MUST contain at least 20 `activiteit` records covering common construction scenarios, at least 10 `locatie` records, at least 5 `omgevingsdocument` records, and at least 10 `vergunningaanvraag` records in various statuses (ingediend, in_behandeling, verleend, geweigerd, ingetrokken). Activity hierarchy MUST be internally consistent -- every `bovenliggendeActiviteit` reference MUST resolve to a valid parent activiteit.

#### Scenario: Common construction activities present
- **GIVEN** the DSO register is loaded
- **WHEN** the activiteit records are inspected
- **THEN** they MUST include at minimum: dakkapel plaatsen, aanbouw bouwen, zonnepanelen installeren, schutting plaatsen, and boom kappen
- **AND** each activiteit MUST have a valid `regelkwalificatie` from the enum (vergunningplicht, meldingsplicht, informatieplicht, vergunningvrij)

#### Scenario: Vergunningaanvraag status distribution
- **GIVEN** the DSO register has at least 10 vergunningaanvraag records
- **WHEN** the records are grouped by `status`
- **THEN** at least 3 different statuses MUST be represented
- **AND** verleend and geweigerd applications MUST have a `besluitdatum` set

#### Scenario: Activity hierarchy consistency
- **GIVEN** an activiteit record with a `bovenliggendeActiviteit` reference
- **WHEN** the reference is resolved
- **THEN** it MUST point to an existing activiteit record in the same register
- **AND** no circular references SHALL exist in the hierarchy

#### Scenario: DSO locations link to BAG municipalities
- **GIVEN** the DSO and BAG registers are both loaded
- **WHEN** a DSO locatie record's `gemeenteCode` is inspected
- **THEN** it MUST match a municipality code present in the BAG register's identification prefixes
- **AND** at least 3 vergunningaanvraag records MUST have location addresses that correspond to BAG nummeraanduiding records

### Requirement: ORI Mock Register (Open Raadsinformatie)

The system SHALL provide a mock ORI register with council information aligned to the VNG ODS-Open-Raadsinformatie specification and the Open State Foundation data model. The register MUST contain a fictional municipality "Voorbeeldstad" with at least 1 raad organization and 3 commissies, at least 8 fracties reflecting typical Dutch council composition, at least 20 raadsleden distributed across fracties, at least 10 vergaderingen spanning 6 months, at least 30 agendapunten, at least 15 raadsdocumenten of various types (motie, amendement, besluit, brief, rapport, notulen), and at least 5 stemmingen with per-fractie results.

#### Scenario: Council composition realism
- **GIVEN** the ORI register is loaded with fractie records
- **WHEN** the fracties are inspected
- **THEN** they MUST include a mix of coalitiepartij and oppositiepartij classifications
- **AND** the total number of zetels across all fracties MUST be a realistic Dutch council size (typically 25-45)
- **AND** party names MUST be fictional but recognizable (e.g. "Voorbeeldstad Vooruit", "Groen Links Voorbeeldstad")

#### Scenario: Meeting schedule realism
- **GIVEN** the ORI register contains vergadering records
- **WHEN** the `startDatum` values are inspected
- **THEN** meetings SHOULD fall on Tuesdays and Thursdays (typical Dutch council schedule)
- **AND** the meetings MUST span at least 6 calendar months

#### Scenario: Agenda-to-meeting referential integrity
- **GIVEN** the ORI register contains agendapunt records
- **WHEN** each agendapunt's `vergadering` reference is resolved
- **THEN** it MUST point to an existing vergadering record
- **AND** agendapunten with `bovenliggendAgendapunt` MUST reference a valid parent agendapunt

#### Scenario: Voting results consistency
- **GIVEN** a stemming record with resultaat "aangenomen"
- **WHEN** the `stemmenVoor` and `stemmenTegen` values are inspected
- **THEN** `stemmenVoor` MUST be greater than `stemmenTegen`
- **AND** the sum of stemmenVoor + stemmenTegen + onthoudingen MUST equal the total number of participating raadsleden
- **AND** the `fractieResultaten` array MUST contain one entry per participating fractie

#### Scenario: Document type diversity
- **GIVEN** the ORI register contains raadsdocument records
- **WHEN** the documents are grouped by `type`
- **THEN** at least 4 different document types MUST be present from the set: motie, amendement, besluit, brief, rapport, notulen

### Requirement: Register JSON File Format Compliance

Each mock register MUST be delivered as a `*_register.json` file in `lib/Settings/` following the OpenAPI 3.0.0 + `x-openregister` extension pattern used by existing app registers (procest_register.json, pipelinq_register.json). The `x-openregister` block MUST include `type: "mock"` to distinguish demo data from production registers. Seed data objects MUST use the `@self` envelope format with `register`, `schema`, and `slug` keys in the `components.objects[]` array.

#### Scenario: Valid OpenAPI structure
- **GIVEN** any mock register JSON file (brp_register.json, kvk_register.json, bag_register.json, dso_register.json, ori_register.json)
- **WHEN** the file is parsed as JSON
- **THEN** it MUST contain top-level keys: `openapi` (value "3.0.0"), `info` (with title, description, version), `x-openregister`, `paths`, and `components`
- **AND** `components` MUST contain `registers`, `schemas`, and `objects` sub-keys

#### Scenario: Object @self envelope format
- **GIVEN** any object in the `components.objects[]` array
- **WHEN** the `@self` key is inspected
- **THEN** it MUST contain `register` (matching a key in `components.registers`), `schema` (matching a key in `components.schemas`), and `slug` (a unique human-readable identifier)

#### Scenario: Mock type identification
- **GIVEN** any mock register JSON file
- **WHEN** the `x-openregister.type` field is inspected
- **THEN** it MUST be set to `"mock"` to allow consuming apps to distinguish demo data from production registers

### Requirement: Idempotent Import via ConfigurationService Pipeline

Mock register import MUST be idempotent. The ImportHandler MUST skip creation of registers, schemas, and objects that already exist (matched by slug) when `force` is `false`. Re-importing the same file MUST NOT create duplicate records. A `force: true` flag MUST allow re-importing to update existing records. The ObjectService `searchObjects` method SHALL be used with `_rbac: false` and `_multitenancy: false` to find existing objects regardless of organisation context, preventing duplicates across tenants.

#### Scenario: First-time import creates all records
- **GIVEN** no BRP register exists in the system
- **WHEN** the administrator imports `brp_register.json` via `ConfigurationService`
- **THEN** the ImportHandler SHALL create the register, schema, and all seed objects
- **AND** each object SHALL be findable via `ObjectService::searchObjects` with the correct register and schema IDs

#### Scenario: Repeated import skips existing records
- **GIVEN** the BRP register was previously imported successfully
- **WHEN** the administrator imports `brp_register.json` again with `force: false`
- **THEN** the ImportHandler SHALL detect existing register, schemas, and objects by slug
- **AND** no duplicate records SHALL be created
- **AND** the import log SHALL indicate records were skipped

#### Scenario: Force import updates existing records
- **GIVEN** the BRP register was previously imported and seed data has been modified
- **WHEN** the administrator imports `brp_register.json` with `force: true`
- **THEN** the ImportHandler SHALL update existing objects to match the JSON file contents
- **AND** the version check (`version_compare`) SHALL be bypassed

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
