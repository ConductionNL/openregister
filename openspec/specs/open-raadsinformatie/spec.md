# Open Raadsinformatie (ORI) Register Specification

## Purpose
Provide a dedicated OpenRegister register with schemas for Open Raadsinformatie (ORI) — the Dutch open standard for publishing municipal council information. The register stores vergaderingen, agendapunten, documenten, moties, amendementen, stemmingen, raadsleden, fracties, and commissies as first-class register objects with proper relationships, public API access, and search/filter capabilities. This is the **data model and storage** side; data ingestion comes from OpenConnector connectors (iBabs, NotuBiz) as described in the `ibabs-notubiz-connector` spec.

**Source**: Open State Foundation ORI API specification; VNG Realisatie raadsinformatie standards; Wet open overheid (Woo) transparency requirements. Required for municipalities that must publish council proceedings publicly.

## ADDED Requirements

### Requirement: ORI register MUST be provisionable with all entity schemas
The system MUST provide a pre-configured "Open Raadsinformatie" register containing all ORI entity schemas, deployable via a repair step or admin action.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-001 | Provide a register template "Open Raadsinformatie" with all ORI schemas | MUST | Planned |
| REQ-ORI-002 | Register MUST be deployable via admin panel or repair step (seed data) | MUST | Planned |
| REQ-ORI-003 | Each schema MUST include JSON Schema validation rules matching ORI field definitions | MUST | Planned |
| REQ-ORI-004 | Register MUST expose a public OAS 3.1.0 API via the existing OAS generation mechanism | MUST | Planned |

#### Scenario: Provision the ORI register
- GIVEN an admin with access to OpenRegister
- WHEN they create a new register from the "Open Raadsinformatie" template
- THEN a register MUST be created with schemas for: Vergadering, Agendapunt, Document, Motie, Amendement, Stemming, Persoon, Organisatie, Fractie, Commissie
- AND each schema MUST have properly typed properties with validation rules
- AND the register MUST be flagged as publicly accessible

#### Scenario: Generate OAS for ORI register
- GIVEN the ORI register is provisioned with all schemas
- WHEN `GET /api/registers/{id}/oas` is called
- THEN the response MUST contain endpoints for all ORI entity types
- AND the OAS MUST pass `redocly lint` with zero errors (per `oas-validation` spec)

---

### Requirement: Vergadering (Meeting) schema
The system MUST store council meetings with all ORI-standard fields.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-010 | Store vergaderingen with: naam, start_date, end_date, location, type, status, organisatie reference | MUST | Planned |
| REQ-ORI-011 | Vergadering types: raadsvergadering, commissievergadering, collegevergadering, informatieavond, hoorzitting | MUST | Planned |
| REQ-ORI-012 | Vergadering status: gepland, bezig, afgelopen, geannuleerd, uitgesteld | MUST | Planned |
| REQ-ORI-013 | Link vergadering to agendapunten via one-to-many relationship | MUST | Planned |
| REQ-ORI-014 | Store video/livestream URL for vergadering | SHOULD | Planned |

#### Scenario: Create a raadsvergadering
- GIVEN the ORI register is active
- WHEN a vergadering is created with:
  - `naam`: `Raadsvergadering 15 maart 2026`
  - `type`: `raadsvergadering`
  - `startDatum`: `2026-03-15T19:00:00+01:00`
  - `eindDatum`: `2026-03-15T23:00:00+01:00`
  - `locatie`: `Raadzaal, Stadhuis`
  - `status`: `gepland`
  - `organisatie`: reference to Gemeente object
- THEN the vergadering MUST be stored as an OpenRegister object
- AND it MUST be retrievable via the public API

#### Scenario: List vergaderingen by date range
- GIVEN 10 vergaderingen exist between January and June 2026
- WHEN `GET /api/objects/{register}/{schema}?startDatum[gte]=2026-03-01&startDatum[lte]=2026-03-31` is called
- THEN only vergaderingen in March 2026 MUST be returned
- AND results MUST be ordered by startDatum ascending

#### Scenario: Filter vergaderingen by type
- GIVEN vergaderingen of types raadsvergadering (5), commissievergadering (8), and collegevergadering (12)
- WHEN `GET /api/objects/{register}/{schema}?type=raadsvergadering` is called
- THEN only the 5 raadsvergaderingen MUST be returned

---

### Requirement: Agendapunt (Agenda Item) schema
The system MUST store agenda items linked to meetings.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-020 | Store agendapunten with: onderwerp, beschrijving, volgorde, vergadering reference | MUST | Planned |
| REQ-ORI-021 | Link agendapunt to zero or more documenten, moties, and amendementen | MUST | Planned |
| REQ-ORI-022 | Track agendapunt behandeling status: gepland, in_behandeling, afgehandeld, doorgeschoven, teruggetrokken | MUST | Planned |
| REQ-ORI-023 | Support parent-child agendapunt hierarchy (sub-agendapunten) | SHOULD | Planned |

#### Scenario: Create agendapunten for a vergadering
- GIVEN vergadering `Raadsvergadering 15 maart 2026` exists
- WHEN agendapunten are created:
  - `volgorde`: 1, `onderwerp`: `Opening en mededelingen`
  - `volgorde`: 2, `onderwerp`: `Vaststelling agenda`
  - `volgorde`: 3, `onderwerp`: `Bestemmingsplan Centrum`, `beschrijving`: `Voorstel tot vaststelling...`
  - `volgorde`: 4, `onderwerp`: `Rondvraag en sluiting`
- THEN all agendapunten MUST be linked to the vergadering
- AND they MUST be retrievable ordered by `volgorde`

#### Scenario: Move agendapunt to different vergadering
- GIVEN agendapunt `Bestemmingsplan Centrum` with status `doorgeschoven`
- WHEN the agendapunt is linked to the next raadsvergadering
- THEN the agendapunt MUST appear on the new vergadering's agenda
- AND the original vergadering MUST show the agendapunt as `doorgeschoven`
- AND an audit trail entry MUST be created

---

### Requirement: Document schema
The system MUST store document metadata with references to files in Nextcloud Files.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-030 | Store documenten with: titel, type, datum, bestandsnaam, vertrouwelijkheid, auteur | MUST | Planned |
| REQ-ORI-031 | Document types: raadsvoorstel, raadsbesluit, collegevoorstel, collegebesluit, amendement, motie, brief, notulen, bijlage | MUST | Planned |
| REQ-ORI-032 | Link document file (PDF) via Nextcloud Files integration (FileService) | MUST | Planned |
| REQ-ORI-033 | Track vertrouwelijkheid levels: openbaar, beperkt_openbaar, vertrouwelijk, geheim | MUST | Planned |
| REQ-ORI-034 | Public API MUST only expose documenten with vertrouwelijkheid `openbaar` or `beperkt_openbaar` | MUST | Planned |

#### Scenario: Store a raadsvoorstel document
- GIVEN agendapunt `Bestemmingsplan Centrum` exists
- WHEN a document is created with:
  - `titel`: `Raadsvoorstel Bestemmingsplan Centrum`
  - `type`: `raadsvoorstel`
  - `datum`: `2026-03-01`
  - `vertrouwelijkheid`: `openbaar`
  - `bestand`: reference to `raadsvoorstel-bestemmingsplan.pdf` in Nextcloud Files
- THEN the document MUST be linked to the agendapunt
- AND the document MUST be downloadable via the public API

#### Scenario: Confidential document not exposed publicly
- GIVEN a document with vertrouwelijkheid `vertrouwelijk`
- WHEN an unauthenticated user requests documents via the public API
- THEN the confidential document MUST NOT appear in the results
- AND authenticated users with appropriate roles MUST be able to access it

---

### Requirement: Motie (Motion) schema
The system MUST store council motions with voting outcomes.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-040 | Store moties with: titel, tekst, indieners (persoon references), status, agendapunt reference | MUST | Planned |
| REQ-ORI-041 | Motie status: ingediend, aangenomen, verworpen, ingetrokken, aangehouden | MUST | Planned |
| REQ-ORI-042 | Link motie to stemmingen (voting records) | MUST | Planned |

#### Scenario: File a motie during raadsvergadering
- GIVEN agendapunt `Bestemmingsplan Centrum` is being treated
- WHEN raadslid "J. de Vries" files a motie:
  - `titel`: `Motie extra groenvoorziening`
  - `tekst`: `De raad, in vergadering bijeen... verzoekt het college...`
  - `indieners`: [reference to Persoon "J. de Vries", reference to Persoon "A. Bakker"]
  - `status`: `ingediend`
- THEN the motie MUST be linked to the agendapunt
- AND it MUST be publicly visible via the API

#### Scenario: Record motie outcome
- GIVEN motie `Motie extra groenvoorziening` has been voted on
- WHEN the status is updated to `aangenomen`
- AND stemming records are linked (see Stemming schema)
- THEN the motie MUST reflect the outcome
- AND the outcome MUST be publicly accessible

---

### Requirement: Amendement (Amendment) schema
The system MUST store amendments to proposals.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-050 | Store amendementen with: titel, tekst, wijziging (what changes), indieners, status, agendapunt reference | MUST | Planned |
| REQ-ORI-051 | Amendement status: ingediend, aangenomen, verworpen, ingetrokken, aangehouden | MUST | Planned |
| REQ-ORI-052 | Link amendement to the original document being amended | SHOULD | Planned |

#### Scenario: File an amendement on a raadsvoorstel
- GIVEN agendapunt `Bestemmingsplan Centrum` has raadsvoorstel document attached
- WHEN raadslid "M. Jansen" files an amendement:
  - `titel`: `Amendement maximale bouwhoogte`
  - `tekst`: `De raad besluit het raadsvoorstel als volgt te wijzigen...`
  - `wijziging`: `Artikel 3.2: maximale bouwhoogte van 25 meter naar 18 meter`
  - `indieners`: [reference to Persoon "M. Jansen"]
  - `status`: `ingediend`
- THEN the amendement MUST be linked to the agendapunt
- AND it SHOULD reference the original raadsvoorstel document

---

### Requirement: Stemming (Vote) schema
The system MUST store voting records per person per motion/amendment/decision.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-060 | Store stemmingen with: onderwerp reference (motie/amendement/agendapunt), type (hoofdelijk/handopsteking/acclamatie), uitslag | MUST | Planned |
| REQ-ORI-061 | For hoofdelijke stemming: store individual votes per persoon (voor/tegen/onthouding/niet_deelgenomen) | MUST | Planned |
| REQ-ORI-062 | Calculate and store vote totals: voor, tegen, onthouding | MUST | Planned |
| REQ-ORI-063 | Track quorum: number of present members vs required quorum | SHOULD | Planned |

#### Scenario: Record a hoofdelijke stemming on a motie
- GIVEN motie `Motie extra groenvoorziening` is put to vote
- AND 35 of 39 raadsleden are present
- WHEN the stemming is recorded:
  - `type`: `hoofdelijk`
  - `onderwerp`: reference to the motie
  - `stemmen`: [
    {"persoon": ref "J. de Vries", "stem": "voor"},
    {"persoon": ref "A. Bakker", "stem": "voor"},
    {"persoon": ref "M. Jansen", "stem": "tegen"},
    ...
  ]
- THEN the stemming MUST calculate totals: `voor`: 22, `tegen`: 11, `onthouding`: 2
- AND `uitslag` MUST be set to `aangenomen`
- AND the linked motie status MUST be updated to `aangenomen`

#### Scenario: Record acclamatie vote
- GIVEN agendapunt `Vaststelling agenda` is voted on by acclamatie
- WHEN the stemming is recorded with `type`: `acclamatie`, `uitslag`: `aangenomen`
- THEN no individual vote records are required
- AND the stemming MUST still be publicly visible

---

### Requirement: Persoon (Person/Council Member) schema
The system MUST store council member information.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-070 | Store personen with: naam, voornaam, achternaam, functie, fractie reference, actief (boolean) | MUST | Planned |
| REQ-ORI-071 | Functie types: raadslid, wethouder, burgemeester, commissielid, griffier, secretaris | MUST | Planned |
| REQ-ORI-072 | Track historical membership: start/end dates per persoon-fractie relation | SHOULD | Planned |
| REQ-ORI-073 | Public API MUST NOT expose BSN or private contact details of personen | MUST | Planned |

#### Scenario: Register a raadslid
- GIVEN fractie "VVD" exists in the ORI register
- WHEN a persoon is created:
  - `voornaam`: `Jan`
  - `achternaam`: `de Vries`
  - `functie`: `raadslid`
  - `fractie`: reference to Fractie "VVD"
  - `actief`: true
  - `startDatum`: `2022-03-30`
- THEN the persoon MUST be stored and publicly accessible
- AND the public API MUST NOT include BSN or personal email/phone

#### Scenario: Filter personen by fractie
- GIVEN 39 raadsleden across 8 fracties
- WHEN `GET /api/objects/{register}/{schema}?fractie={vvd-id}` is called
- THEN only raadsleden of fractie VVD MUST be returned

---

### Requirement: Fractie (Political Party/Faction) schema
The system MUST store council factions/parties.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-080 | Store fracties with: naam, afkorting, zetels (seat count), organisatie reference | MUST | Planned |
| REQ-ORI-081 | Link fractie to its leden (personen) via bidirectional references | MUST | Planned |

#### Scenario: Create fracties for a gemeenteraad
- GIVEN the ORI register for gemeente "Voorbeeldstad"
- WHEN fracties are created:
  - `naam`: `Volkspartij voor Vrijheid en Democratie`, `afkorting`: `VVD`, `zetels`: 8
  - `naam`: `Partij van de Arbeid`, `afkorting`: `PvdA`, `zetels`: 6
  - `naam`: `GroenLinks`, `afkorting`: `GL`, `zetels`: 5
- THEN each fractie MUST be stored and publicly accessible
- AND each fractie MUST show its leden count via the API

---

### Requirement: Commissie (Committee) schema
The system MUST store council committees.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-090 | Store commissies with: naam, type, leden (persoon references), voorzitter (persoon reference) | MUST | Planned |
| REQ-ORI-091 | Commissie types: raadscommissie, stadsdeelcommissie, adviescommissie, rekenkamercommissie | MUST | Planned |
| REQ-ORI-092 | Link commissie to its vergaderingen (commissievergaderingen) | SHOULD | Planned |

#### Scenario: Create a raadscommissie
- GIVEN personen "J. de Vries" and "A. Bakker" exist as raadsleden
- WHEN a commissie is created:
  - `naam`: `Commissie Ruimte en Wonen`
  - `type`: `raadscommissie`
  - `voorzitter`: reference to Persoon "J. de Vries"
  - `leden`: [reference to "J. de Vries", reference to "A. Bakker", ...]
- THEN the commissie MUST be stored
- AND vergaderingen of type `commissievergadering` MUST be linkable to this commissie

---

### Requirement: Organisatie (Organization) schema
The system MUST store the municipality organization as the parent entity.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-100 | Store organisatie with: naam, gemeentecode (CBS code), website, classification | MUST | Planned |
| REQ-ORI-101 | Organisatie classification: gemeente, provincie, waterschap, gemeenschappelijke_regeling | SHOULD | Planned |

#### Scenario: Register a municipality
- GIVEN the ORI register is provisioned
- WHEN an organisatie is created:
  - `naam`: `Gemeente Voorbeeldstad`
  - `gemeentecode`: `0999`
  - `website`: `https://www.voorbeeldstad.nl`
  - `classification`: `gemeente`
- THEN the organisatie MUST be the root entity for all ORI data
- AND all vergaderingen, fracties, and commissies MUST reference this organisatie

---

### Requirement: Demo/mock data for development and testing
The system MUST provide seed data representing a realistic municipality council.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-110 | Provide mock data for a fictional municipality "Voorbeeldstad" with realistic council composition | MUST | Planned |
| REQ-ORI-111 | Mock data MUST include: 1 organisatie, 6+ fracties, 30+ raadsleden, 3+ commissies, 10+ vergaderingen, 50+ agendapunten | MUST | Planned |
| REQ-ORI-112 | Mock data MUST include example moties, amendementen, and stemmingen with realistic voting patterns | SHOULD | Planned |
| REQ-ORI-113 | Mock data MUST include example documents (PDF placeholders) linked to agendapunten | SHOULD | Planned |

#### Scenario: Seed demo data via repair step
- GIVEN a fresh OpenRegister installation with the ORI register
- WHEN the admin triggers the ORI demo data seeder
- THEN the system MUST create a complete municipality council dataset for "Voorbeeldstad"
- AND the data MUST be immediately browsable via the public API
- AND the data MUST demonstrate all entity relationships (vergadering -> agendapunt -> motie -> stemming)

---

### Requirement: Search and filtering
The system MUST support efficient search and filtering across all ORI entities.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-120 | Full-text search across vergaderingen, agendapunten, documenten, moties, amendementen | MUST | Planned |
| REQ-ORI-121 | Filter by date range on vergaderingen and documenten | MUST | Planned |
| REQ-ORI-122 | Filter by persoon across moties, amendementen, stemmingen (e.g., "all moties filed by raadslid X") | MUST | Planned |
| REQ-ORI-123 | Filter by fractie across personen and derived voting statistics | SHOULD | Planned |
| REQ-ORI-124 | Faceted search: expose facets for type, status, fractie, date period on search results | SHOULD | Planned |

#### Scenario: Search for all activity by a raadslid
- GIVEN raadslid "J. de Vries" has filed 5 moties and 3 amendementen across multiple vergaderingen
- WHEN a search is performed filtering by persoon "J. de Vries"
- THEN all 5 moties and 3 amendementen MUST be returned
- AND results MUST include the linked vergadering and agendapunt context

#### Scenario: Search agendapunten by keyword
- GIVEN 50 agendapunten exist with various onderwerpen
- WHEN a full-text search for "bestemmingsplan" is performed
- THEN all agendapunten containing "bestemmingsplan" in onderwerp or beschrijving MUST be returned
- AND linked documenten containing "bestemmingsplan" SHOULD also surface

---

### Requirement: Public access and transparency (Woo compliance)
The system MUST support public, unauthenticated access to council information in line with Wet open overheid (Woo) requirements.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-130 | All ORI data marked as `openbaar` MUST be accessible without authentication | MUST | Planned |
| REQ-ORI-131 | Public API MUST support pagination, sorting, and filtering without authentication | MUST | Planned |
| REQ-ORI-132 | Rate limiting MUST be applied to public endpoints to prevent abuse | MUST | Planned |
| REQ-ORI-133 | Public API responses MUST include Cache-Control headers for CDN compatibility | SHOULD | Planned |
| REQ-ORI-134 | The register MUST support bulk export (JSON/CSV) for open data reuse | SHOULD | Planned |

#### Scenario: Anonymous user browses upcoming vergaderingen
- GIVEN 3 upcoming raadsvergaderingen are scheduled
- WHEN an unauthenticated user calls `GET /api/objects/{register}/{schema}?status=gepland&_order[startDatum]=asc`
- THEN all 3 vergaderingen MUST be returned with full metadata
- AND response headers MUST include appropriate Cache-Control directives

#### Scenario: Bulk export for open data portal
- GIVEN the ORI register contains 2 years of council data
- WHEN an export is requested in JSON format
- THEN all public vergaderingen, agendapunten, documenten, moties, amendementen, and stemmingen MUST be included
- AND the export format MUST be compatible with data.overheid.nl publishing requirements

---

### Requirement: Integration with OpenConnector data sources
The system MUST serve as the data store for council information ingested via OpenConnector connectors.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-140 | Schema field names and types MUST align with iBabs and NotuBiz data models for seamless mapping | MUST | Planned |
| REQ-ORI-141 | Support idempotent upsert: re-importing the same vergadering/agendapunt from iBabs/NotuBiz MUST update, not duplicate | MUST | Planned |
| REQ-ORI-142 | Store source system reference (sourceSystem, sourceId) on every object for traceability | MUST | Planned |
| REQ-ORI-143 | Support incremental sync: new/changed objects from source systems MUST be mergeable with existing data | MUST | Planned |

#### Scenario: Import vergadering from iBabs via OpenConnector
- GIVEN an iBabs connector is configured in OpenConnector
- AND the connector fetches vergadering data from iBabs API
- WHEN the data is stored in the ORI register
- THEN the vergadering object MUST include `_sourceSystem`: `ibabs` and `_sourceId`: `{ibabs-meeting-id}`
- AND a subsequent import of the same vergadering MUST update the existing object (not create a duplicate)

#### Scenario: Import from NotuBiz with different field names
- GIVEN NotuBiz uses field name `Onderwerp` where iBabs uses `subject`
- WHEN the OpenConnector mapping transforms NotuBiz data to ORI schema format
- THEN the resulting object MUST use the ORI schema field names (e.g., `onderwerp`)
- AND the source mapping MUST be traceable via `_sourceSystem`: `notubiz`

## Data Model

### Entity Relationship Overview

```
Organisatie (1) ──── (N) Vergadering
    │                       │
    │                       └── (N) Agendapunt
    │                                │
    ├── (N) Fractie                  ├── (N) Document
    │       │                        ├── (N) Motie ──── (1) Stemming
    │       └── (N) Persoon          └── (N) Amendement ──── (1) Stemming
    │               │
    └── (N) Commissie ── (N) Persoon (leden)
```

### Schema Field Definitions

| Schema | Key Fields | Relationships |
|--------|-----------|---------------|
| Vergadering | naam, startDatum, eindDatum, locatie, type, status, videoUrl | -> Organisatie, -> [Agendapunt], -> Commissie (optional) |
| Agendapunt | onderwerp, beschrijving, volgorde, status | -> Vergadering, -> [Document], -> [Motie], -> [Amendement], -> Agendapunt (parent) |
| Document | titel, type, datum, vertrouwelijkheid, bestandsnaam | -> Agendapunt, -> Nextcloud File |
| Motie | titel, tekst, status | -> Agendapunt, -> [Persoon] (indieners), -> Stemming |
| Amendement | titel, tekst, wijziging, status | -> Agendapunt, -> [Persoon] (indieners), -> Stemming, -> Document (original) |
| Stemming | type, uitslag, voor, tegen, onthouding | -> Motie/Amendement/Agendapunt, -> [{Persoon, stem}] |
| Persoon | voornaam, achternaam, functie, actief, startDatum, eindDatum | -> Fractie |
| Fractie | naam, afkorting, zetels | -> Organisatie, -> [Persoon] |
| Commissie | naam, type, voorzitter | -> Organisatie, -> [Persoon] (leden) |
| Organisatie | naam, gemeentecode, website, classification | root entity |

### Source Tracking Fields (all schemas)

Every ORI object MUST include:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| _sourceSystem | string (enum) | No | `ibabs`, `notubiz`, `manual`, `api` |
| _sourceId | string | No | Original ID in the source system |
| _sourceUrl | string (URL) | No | Deep link to the item in the source system |
| _lastSyncedAt | datetime | No | Timestamp of last sync from source |

## Dependencies

- **OpenRegister**: Register and schema storage, object CRUD, public API, OAS generation
- **OpenConnector**: iBabs and NotuBiz connectors for data ingestion (see `ibabs-notubiz-connector` spec)
- **Docudesk**: PDF handling for council documents (optional, for document conversion)
- **Nextcloud Files**: Storage backend for document attachments (PDFs)
- **OpenRegister FileService**: Linking register objects to Nextcloud files

### Using Mock Register Data

The **ORI** mock register provides test data for council information development and demos.

**Loading the register:**
```bash
# Load ORI register (115 records, register slug: "ori", schemas: "vergadering", "agendapunt", "raadsdocument", "stemming", "raadslid", "fractie")
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/ori_register.json
```

**Test data available:**
- **Vergaderingen**: Council meetings spanning 6 months for fictional municipality "Voorbeeldstad"
- **Agendapunten**: Agenda items linked to vergaderingen with proper ordering
- **Raadsdocumenten**: Documents of various types (motie, amendement, besluit, brief, rapport, notulen)
- **Stemmingen**: Voting records with per-fractie results (aangenomen/verworpen)
- **Raadsleden**: 20+ council members distributed across fracties
- **Fracties**: 8 parties reflecting typical Dutch council composition (coalitie/oppositie)

**Querying mock data:**
```bash
# List all vergaderingen
curl "http://localhost:8080/index.php/apps/openregister/api/objects/{ori_register_id}/{vergadering_schema_id}" -u admin:admin

# Find council member by name
curl "http://localhost:8080/index.php/apps/openregister/api/objects/{ori_register_id}/{raadslid_schema_id}?_search=Bakker" -u admin:admin
```

## Current Implementation Status

### Implemented
- **Nothing ORI-specific is implemented.** There are no raadsinformatie schemas, no ORI register template, no council-related entities in the codebase.

### Relevant existing infrastructure
- **Register/Schema entities** (`lib/Db/Register.php`, `lib/Db/Schema.php`): Foundation for creating the ORI register and schemas. Schemas support JSON Schema property definitions, required fields, and `$ref` references between schemas.
- **ObjectService** (`lib/Service/ObjectService.php`): Full CRUD for register objects, including filtering, pagination, and sorting. Supports the query patterns needed for REQ-ORI-120 through REQ-ORI-124.
- **OasService** (`lib/Service/OasService.php`): Generates OpenAPI 3.1.0 specs from register/schema definitions. The ORI register would automatically get a public API spec (REQ-ORI-004).
- **FileService** (`lib/Service/FileService.php`): Links Nextcloud files to register objects. Needed for document attachments (REQ-ORI-032).
- **Public API endpoints**: The existing `/api/objects/{register}/{schema}` endpoints support public access when the register is configured for it.
- **Search infrastructure**: Object filtering by property values, date ranges, and full-text search (where configured) already exist.
- **Faceting** (per `faceting-configuration` spec): When implemented, would directly serve REQ-ORI-124.

### Not implemented
- ORI register template with pre-configured schemas
- ORI-specific JSON Schema definitions for all entity types
- Source tracking fields (_sourceSystem, _sourceId, etc.)
- Idempotent upsert based on source system + source ID
- Demo/mock data seeder for "Voorbeeldstad"
- Privacy filtering for Persoon schema (BSN/contact detail exclusion from public API)
- Bulk export endpoint for open data portal compatibility
- Quorum tracking for stemmingen
- Incremental sync support for connector-imported data
- Cache-Control headers for public endpoints

## Standards & References

- **Open Raadsinformatie (ORI)**: Open standard by Open State Foundation for publishing Dutch council information. Defines entity types, field names, and API structure for interoperability between municipalities. See: https://openraadsinformatie.nl
- **Open State Foundation**: Non-profit maintaining the ORI standard and aggregating council data from Dutch municipalities. See: https://openstate.eu
- **VNG Realisatie**: Association of Dutch municipalities; promotes standardization including raadsinformatie. See: https://vng.nl/rubrieken/gemeentelijke-gemeenschappelijke-uitvoering
- **Wet open overheid (Woo)**: Dutch transparency law (successor to WOB) requiring active publication of government decisions and council proceedings. See: https://wetten.overheid.nl/BWBR0045754
- **Popolo ontology**: International standard for legislative data that ORI partially aligns with (persons, organizations, motions, votes). See: http://www.intgovforum.org
- **iBabs API**: Proprietary council information system by Meeting.nl. Primary source for B&W/college data. See: https://developer.ibabs.eu
- **NotuBiz API**: Proprietary council information system by CMSolutions. Covers raads- and commissievergaderingen. See: https://www.notubiz.nl
- **data.overheid.nl**: Dutch government open data portal where ORI data should be publishable. See: https://data.overheid.nl
- **GEMMA referentiearchitectuur**: Standard architecture for Dutch municipalities, includes raadsinformatieprocessen. See: https://gemmaonline.nl
- **CBS gemeentecodes**: Central Bureau of Statistics municipality codes used for organisatie identification. See: https://www.cbs.nl

## Specificity Assessment

### Sufficient for implementation
- All 10 entity schemas are well-defined with fields, types, and relationships.
- Gherkin scenarios cover CRUD, filtering, search, and privacy use cases.
- Source tracking fields are specific and directly implementable.
- The data model diagram clarifies all entity relationships.
- Integration with existing OpenRegister infrastructure is well-mapped (ObjectService, FileService, OasService).
- Public access requirements are clear with explicit vertrouwelijkheid filtering.

### Missing or ambiguous
- **JSON Schema definitions**: The spec describes fields at a high level but does not provide the exact JSON Schema documents for each entity. These need to be authored as part of implementation.
- **Upsert mechanism**: REQ-ORI-141 requires idempotent upsert by _sourceSystem + _sourceId, but OpenRegister's ObjectService may not support composite unique constraints natively. Implementation approach needs design.
- **Privacy filtering implementation**: REQ-ORI-073 says "MUST NOT expose BSN" — but the mechanism (field-level ACL? separate public schema view? property exclusion list?) is not specified.
- **Bulk export format**: REQ-ORI-134 mentions JSON/CSV but doesn't define the exact schema mapping for data.overheid.nl compatibility (DCAT-AP-DONL metadata, etc.).
- **Vote totals calculation**: REQ-ORI-062 requires calculating totals from individual votes — this implies computed/derived fields or application-level logic, which needs to interact with the `computed-fields` spec.
- **Historical membership**: REQ-ORI-072 tracks persoon-fractie relations over time, but the mechanism (separate junction schema? date fields on persoon? versioned references?) is not specified.

### Open questions
1. Should the ORI register use a fixed register UUID so that connector configurations can reference it stably across environments?
2. How should the upsert-by-source-ID be implemented — as a new ObjectService feature, or as connector-level logic in OpenConnector?
3. Should stemmingen vote totals be computed fields (auto-calculated) or manually entered? Computed fields may depend on the `computed-fields` spec being implemented first.
4. What is the minimum viable subset of schemas for an initial release? (e.g., Vergadering + Agendapunt + Document first, then Motie/Amendement/Stemming later)
5. Should the ORI API follow the exact Open State Foundation API URL structure (`/api/v1/meetings`, `/api/v1/events`) or use the standard OpenRegister URL pattern (`/api/objects/{register}/{schema}`)?
6. How does the privacy filter for Persoon interact with the existing RBAC scopes spec — are they the same mechanism or separate?
