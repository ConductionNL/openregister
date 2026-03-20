---
status: partial
---
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

