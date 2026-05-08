---
status: draft
---

# dso-omgevingsloket Specification

## Purpose
Provide OpenRegister schemas and API mappings for hosting DSO (Digitaal Stelsel Omgevingswet) related data as a register. This covers vergunningaanvragen, activiteiten, locaties, omgevingsdocumenten, and related entities conforming to DSO data models (STAM, IMOW). Where OpenConnector's `dso-omgevingsloket` spec handles *connecting to* the DSO-LV as a source, this spec defines how OpenRegister *stores, manages, and exposes* DSO data as structured register objects with DSO-compatible API output.

**Tender demand**: 32% of analyzed government tenders require VTH (Vergunningen, Toezicht, Handhaving) capabilities aligned with the Omgevingswet/DSO. Municipalities need a register to store and query omgevingsvergunning data locally while maintaining compatibility with the national DSO-LV system.

## ADDED Requirements

### REQ-DSO-001: Register schemas for core DSO entities
OpenRegister MUST provide register schemas for the core DSO entity types, enabling structured storage of omgevingsvergunning-related data.

#### Scenario: Create a vergunningaanvraag object
- GIVEN the DSO register is configured with the `vergunningaanvraag` schema
- WHEN an operator creates a new vergunningaanvraag with:
  - `verzoekId`: `DSO-2026-001234`
  - `type`: `aanvraag`
  - `aanvrager`: `{ "bsn": "123456789", "naam": "J. de Vries", "adres": { "straat": "Kerkstraat", "huisnummer": "12", "postcode": "1234AB", "woonplaats": "Amsterdam" } }`
  - `locatie`: `{ "bagId": "0363010012345678", "adres": "Kerkstraat 12, Amsterdam", "geometrie": { "type": "Point", "coordinates": [4.8952, 52.3702] } }`
  - `activiteiten`: `[{ "code": "bouwen", "omschrijving": "Bouwen van een woning" }]`
  - `indieningsdatum`: `2026-03-15T10:30:00Z`
- THEN the object MUST be stored with all fields validated against the schema
- AND the `verzoekId` MUST be unique within the register

#### Scenario: Create an activiteit object
- GIVEN the DSO register is configured with the `activiteit` schema
- WHEN an operator creates an activiteit with:
  - `code`: `bouwen`
  - `naam`: `Bouwen van een bouwwerk`
  - `regelgroep`: `vergunningplicht`
  - `bevoegdGezag`: `gemeente`
  - `stamUri`: `https://identifier.overheid.nl/tooi/def/act/bouwen`
- THEN the activiteit MUST be stored as a register object
- AND the `code` MUST be unique within the register

#### Scenario: Create a locatie object
- GIVEN the DSO register is configured with the `locatie` schema
- WHEN an operator creates a locatie with:
  - `naam`: `Kerkstraat 12, Amsterdam`
  - `type`: `punt`
  - `bagId`: `0363010012345678`
  - `kadastraleAanduiding`: `ASD04-F-1234`
  - `geometrie`: `{ "type": "Point", "coordinates": [4.8952, 52.3702] }`
- THEN the locatie MUST be stored with GeoJSON-compatible geometry
- AND the `bagId` SHOULD be validated against BAG format (16-digit numeric)

### REQ-DSO-002: STAM data model alignment
OpenRegister's DSO schemas MUST align with the STAM (Stelselcatalogus Activiteiten Module) data model, enabling interoperability with the national DSO-LV.

#### Scenario: STAM-aligned activiteit schema
- GIVEN the STAM defines activiteiten with properties: `identificatie`, `naam`, `groep`, `regelkwalificatie`, `bevoegdGezag`
- WHEN the `activiteit` schema is configured in OpenRegister
- THEN each STAM property MUST map to an OpenRegister schema property
- AND the mapping MUST be documented in the schema metadata

#### Scenario: Import STAM reference data
- GIVEN the national STAM catalog publishes a list of wettelijke activiteiten
- WHEN an admin triggers a STAM import
- THEN all standard activiteiten (bouwen, slopen, kappen, milieu, monumenten, uitrit, etc.) MUST be imported as register objects
- AND each imported object MUST retain its STAM `identificatie` for traceability

#### Scenario: Custom activiteiten alongside STAM
- GIVEN standard STAM activiteiten are imported
- WHEN a municipality defines a custom activiteit (e.g., `evenementenvergunning`)
- THEN the custom activiteit MUST coexist with STAM activiteiten
- AND MUST be flagged as `bron: lokaal` versus `bron: stam` for STAM-sourced entries

### REQ-DSO-003: Omgevingsdocument schema
OpenRegister MUST provide a schema for omgevingsdocumenten (omgevingsplannen, -visies, -verordeningen) conforming to IMOW (Informatiemodel Omgevingswet).

#### Scenario: Store an omgevingsplan fragment
- GIVEN the DSO register has the `omgevingsdocument` schema
- WHEN an operator creates an omgevingsdocument with:
  - `identificatie`: `nl.imow-gm0363.omgevingsplan.2026-1`
  - `type`: `omgevingsplan`
  - `status`: `vastgesteld`
  - `inwerkingtreding`: `2026-07-01`
  - `werkingsgebied`: GeoJSON polygon of the applicable area
- THEN the document MUST be stored with IMOW-compliant identification
- AND the `werkingsgebied` geometry MUST be queryable via spatial filters

#### Scenario: Link activiteiten to omgevingsdocument
- GIVEN an omgevingsdocument `omgevingsplan-centrum` exists
- AND activiteiten `bouwen` and `kappen` are defined
- WHEN the admin links these activiteiten to the omgevingsdocument
- THEN querying the omgevingsdocument MUST return its linked activiteiten
- AND querying an activiteit MUST return its governing omgevingsdocumenten

### REQ-DSO-004: DSO API output mapping
OpenRegister MUST support mapping internal objects to DSO-compatible API output formats, using the same mapping engine as the ZGW API mapping spec.

#### Scenario: Map vergunningaanvraag to DSO verzoek format
- GIVEN a vergunningaanvraag object in OpenRegister with English-internal properties:
  - `requestId`: `DSO-2026-001234`
  - `type`: `application`
  - `applicant`: `{ "bsn": "123456789", "name": "J. de Vries" }`
  - `submissionDate`: `2026-03-15T10:30:00Z`
- WHEN the outbound DSO mapping is applied
- THEN the API response MUST use DSO-standard Dutch property names:
  - `verzoekId`: `DSO-2026-001234`
  - `type`: `aanvraag`
  - `aanvrager`: `{ "bsn": "123456789", "naam": "J. de Vries" }`
  - `indieningsdatum`: `2026-03-15T10:30:00Z`

#### Scenario: Inbound mapping from DSO format
- GIVEN a DSO-LV pushes a verzoek via OpenConnector with Dutch property names
- WHEN the inbound mapping is applied
- THEN the object MUST be stored with English-internal property names
- AND the original DSO `verzoekId` MUST be preserved for traceability

### REQ-DSO-005: Vergunningcheck data support
OpenRegister MUST store the data needed to support DSO vergunningcheck (permit checker) functionality: which activiteiten require a vergunning, melding, or informatieplicht at a given locatie.

#### Scenario: Query activiteit regelkwalificatie for locatie
- GIVEN activiteiten with regelkwalificaties are stored:
  - `bouwen` at `Kerkstraat 12` requires `vergunningplicht`
  - `kappen` at `Kerkstraat 12` requires `meldingsplicht`
  - `zonnepanelen` at `Kerkstraat 12` has `vergunningvrij`
- WHEN a vergunningcheck queries for `Kerkstraat 12`
- THEN the response MUST list all activiteiten with their regelkwalificatie
- AND the response MUST distinguish between `vergunningplicht`, `meldingsplicht`, `informatieplicht`, and `vergunningvrij`

#### Scenario: Locatie-specific rules override general rules
- GIVEN activiteit `bouwen` has default regelkwalificatie `vergunningplicht`
- AND the omgevingsplan for `beschermd stadsgezicht` area adds extra indieningsvereisten
- WHEN a vergunningcheck queries for a locatie within that area
- THEN the response MUST include the area-specific extra requirements
- AND MUST reference the governing omgevingsdocument

### REQ-DSO-006: Relationship to OpenConnector DSO adapter
OpenRegister serves as the data store for DSO entities; OpenConnector serves as the connection layer to DSO-LV. The boundary MUST be clearly defined.

#### Scenario: OpenConnector receives verzoek, stores in OpenRegister
- GIVEN OpenConnector's DSO adapter receives a verzoek from DSO-LV
- WHEN the adapter processes the inbound verzoek
- THEN the adapter MUST create an object in OpenRegister's `vergunningaanvraag` schema
- AND the adapter MUST use OpenRegister's standard API (not direct database access)
- AND OpenRegister MUST validate the object against the schema before storing

#### Scenario: OpenRegister provides data, OpenConnector pushes to DSO-LV
- GIVEN a vergunningaanvraag in OpenRegister has its status updated to `besluit_genomen`
- WHEN OpenConnector needs to push the status update to DSO-LV
- THEN OpenConnector reads the current state from OpenRegister
- AND applies the outbound DSO mapping
- AND pushes to DSO-LV via its STAM koppelvlak adapter

#### Scenario: Local data management without DSO-LV
- GIVEN a municipality wants to manage omgevingsvergunningen without a live DSO-LV connection
- WHEN they use the DSO register schemas in OpenRegister
- THEN all CRUD operations MUST work independently of OpenConnector/DSO-LV connectivity
- AND data MUST remain DSO-compatible for future synchronization

### REQ-DSO-007: Demo and mock data
OpenRegister MUST provide demo/mock data for DSO entities to support development and testing.

#### Scenario: Seed DSO demo data
- GIVEN a fresh OpenRegister installation with DSO schemas configured
- WHEN the admin triggers demo data seeding
- THEN the register MUST be populated with:
  - At least 10 standard STAM activiteiten (bouwen, slopen, kappen, milieu, uitrit, etc.)
  - At least 5 example vergunningaanvragen in various statuses
  - At least 3 locaties with BAG references and geometry
  - At least 1 omgevingsdocument (omgevingsplan fragment)
- AND the demo data MUST be realistic (plausible addresses, valid BSN format, etc.)
- AND the demo data MUST be clearly marked as test data

#### Scenario: Demo vergunningcheck flow
- GIVEN demo data is seeded
- WHEN a developer queries the vergunningcheck for demo locatie `Marktplein 1, Voorbeeldstad`
- THEN the response MUST return multiple activiteiten with mixed regelkwalificaties
- AND the response MUST demonstrate the full data model (activiteiten, locatie, omgevingsdocument links)

### REQ-DSO-008: DSO status lifecycle
Vergunningaanvragen in OpenRegister MUST support the standard DSO status lifecycle.

#### Scenario: Status transitions
- GIVEN a vergunningaanvraag with status `ontvangen`
- WHEN the status is updated to `in_behandeling`
- THEN the status transition MUST be recorded in the object's audit trail
- AND the valid status values MUST be: `ontvangen`, `in_behandeling`, `aanvullend_nodig`, `besluit_genomen`, `ingetrokken`, `buiten_behandeling`
- AND invalid transitions (e.g., `besluit_genomen` back to `ontvangen`) SHOULD be rejected

#### Scenario: Besluit registration
- GIVEN a vergunningaanvraag in status `in_behandeling`
- WHEN the behandelaar registers a besluit:
  - `besluitType`: `verleend` | `geweigerd` | `deels_verleend` | `buiten_behandeling`
  - `besluitDatum`: `2026-05-01`
  - `motivering`: free-text motivation
  - `voorschriften`: array of permit conditions (if `verleend`)
- THEN the vergunningaanvraag status MUST change to `besluit_genomen`
- AND the besluit MUST be stored as a linked object

## Data Model

### Schema: Vergunningaanvraag (Permit Application)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| verzoekId | string | Yes | DSO-LV unique verzoek identifier |
| type | string (enum) | Yes | `aanvraag`, `melding`, `informatieverzoek`, `vooroverleg` |
| status | string (enum) | Yes | `ontvangen`, `in_behandeling`, `aanvullend_nodig`, `besluit_genomen`, `ingetrokken`, `buiten_behandeling` |
| indieningsdatum | datetime | Yes | Date/time of submission |
| aanvrager | object | Yes | Initiatiefnemer details (BSN/KVK, naam, adres, contact) |
| gemachtigde | object | No | Authorized representative (if different from aanvrager) |
| locatie | string (ref) | Yes | Reference to Locatie object |
| activiteiten | array (refs) | Yes | References to Activiteit objects |
| bouwkosten | decimal | No | Stated construction costs (for legesberekening) |
| projectomschrijving | string | No | Free-text project description |
| bijlagen | array | No | References to uploaded documents |
| besluit | object | No | Besluit details (set when status = besluit_genomen) |
| bronOrganisatie | string | No | OIN of originating DSO-LV (if received from DSO) |
| zaakId | string (UUID) | No | Reference to Procest zaak (if linked) |

### Schema: Activiteit (Activity)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| code | string | Yes | Unique activity code (e.g., `bouwen`, `slopen`) |
| naam | string | Yes | Human-readable name |
| omschrijving | string | No | Extended description |
| regelkwalificatie | string (enum) | Yes | `vergunningplicht`, `meldingsplicht`, `informatieplicht`, `vergunningvrij` |
| regelgroep | string | No | Grouping (e.g., `bouwactiviteit`, `milieuactiviteit`) |
| bevoegdGezag | string (enum) | Yes | `gemeente`, `provincie`, `waterschap`, `rijk` |
| stamIdentificatie | string | No | STAM catalog identifier |
| bron | string (enum) | Yes | `stam` (national catalog) or `lokaal` (local custom) |
| omgevingsdocumenten | array (refs) | No | References to governing Omgevingsdocument objects |
| indieningsvereisten | array | No | Required documents/information for this activity |

### Schema: Locatie (Location)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| naam | string | Yes | Human-readable location name or address |
| type | string (enum) | Yes | `punt`, `vlak`, `lijn`, `multi` |
| bagId | string | No | BAG (Basisregistratie Adressen en Gebouwen) identifier |
| kadastraleAanduiding | string | No | BRK cadastral designation |
| adres | object | No | Structured address (straat, huisnummer, postcode, woonplaats) |
| geometrie | object (GeoJSON) | Yes | GeoJSON geometry (Point, Polygon, MultiPolygon, etc.) |
| oppervlakte | decimal | No | Area in square meters (for vlak/multi types) |

### Schema: Omgevingsdocument (Environmental Document)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| identificatie | string | Yes | IMOW-compliant identifier |
| type | string (enum) | Yes | `omgevingsplan`, `omgevingsvisie`, `omgevingsverordening`, `waterschapsverordening`, `amvb`, `ministeriele_regeling` |
| naam | string | Yes | Document name |
| status | string (enum) | Yes | `ontwerp`, `vastgesteld`, `inwerking`, `ingetrokken` |
| inwerkingtreding | date | No | Effective date |
| werkingsgebied | object (GeoJSON) | No | Geographic area of applicability |
| activiteiten | array (refs) | No | References to Activiteit objects governed by this document |
| regels | array | No | Structured rules and provisions |

### Schema: Besluit (Decision)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| vergunningaanvraag | string (ref) | Yes | Reference to Vergunningaanvraag object |
| type | string (enum) | Yes | `verleend`, `geweigerd`, `deels_verleend`, `buiten_behandeling` |
| datum | date | Yes | Decision date |
| motivering | string | Yes | Decision motivation/reasoning |
| voorschriften | array | No | Permit conditions (if verleend/deels_verleend) |
| bezwaartermijn | date | No | Deadline for filing objections |
| publicatieDatum | date | No | Date of public announcement |
| document | string (ref) | No | Reference to beschikking document (PDF) |

## Non-Requirements
- **Running a DSO-LV node**: OpenRegister is not a replacement for the national DSO-LV infrastructure; it stores and manages DSO-related data locally.
- **Full IMOW compliance**: The omgevingsdocument schema captures key IMOW fields but does not implement the complete IMOW information model (which includes annotaties, juridische regels, and complex OW-object hierarchies).
- **DSO-LV connectivity**: Actual connection to DSO-LV is handled by OpenConnector (see `openconnector/openspec/specs/dso-omgevingsloket/spec.md`). This spec covers data storage only.
- **Toepasbare regels engine**: Executing STTR (Standard voor Toepasbare Regels) rule sets for automated vergunningcheck is out of scope; OpenRegister stores the data, but rule execution belongs in a dedicated rules engine.
- **3D geometry / BIM integration**: Complex 3D building models and BIM data are out of scope for the base DSO register schemas.

## Dependencies
- **OpenRegister core**: Schema management, object CRUD, RBAC, multi-tenancy, audit trail
- **OpenRegister mapping engine**: Twig-based property/value mapping (shared with ZGW API mapping spec)
- **OpenConnector DSO adapter**: Inbound/outbound DSO-LV communication (separate spec, separate app)
- **Procest**: Zaak lifecycle management for vergunningaanvragen that become cases
- **Docudesk**: PDF generation for beschikkingen
- **GeoJSON support**: Geometry storage and spatial queries (existing OpenRegister capability)
- **BAG/BRK reference data**: Address and cadastral validation (via OpenConnector sources)

### Using Mock Register Data

The **DSO** mock register provides test data for omgevingsvergunning development and demos.

**Loading the register:**
```bash
# Load DSO register (53 records, register slug: "dso", schemas: "activiteit", "locatie", "omgevingsdocument", "vergunningaanvraag")
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/dso_register.json
```

**Test data available:**
- **Activiteiten**: 20+ activity records covering common scenarios (dakkapel plaatsen, aanbouw, zonnepanelen, kappen, etc.) with regelkwalificatie (vergunningplicht, meldingsplicht, vergunningvrij)
- **Locaties**: 10+ location records with municipality codes and optional address references
- **Omgevingsdocumenten**: 5+ documents (omgevingsplan, omgevingsverordening, etc.)
- **Vergunningaanvragen**: 10+ applications in various statuses (ingediend, in_behandeling, verleend, geweigerd)

**Querying mock data:**
```bash
# List all activiteiten
curl "http://localhost:8080/index.php/apps/openregister/api/objects/{dso_register_id}/{activiteit_schema_id}" -u admin:admin

# Find vergunningaanvragen by status
curl "http://localhost:8080/index.php/apps/openregister/api/objects/{dso_register_id}/{vergunningaanvraag_schema_id}?_search=verleend" -u admin:admin
```

## Current Implementation Status

### Implemented
- **None of the DSO-specific schemas or mappings are implemented.** OpenRegister has no DSO-related schemas, activiteit catalogs, or omgevingsdocument storage.

### Partially relevant existing infrastructure
- **Schema system** (`lib/Db/Schema.php`, `lib/Service/SchemaService.php`): OpenRegister's core schema system supports defining custom schemas with property definitions, validation, and relationships. DSO schemas would be registered as standard OpenRegister schemas.
- **GeoJSON support**: OpenRegister can store GeoJSON geometry in object properties. Spatial querying may require Solr or Elasticsearch with geo_shape field type.
- **Mapping engine** (`lib/Service/MappingService.php`): Twig-based mapping is available for translating between internal and external property names/values, directly applicable for DSO API output formatting.
- **Object references** (`lib/Service/ObjectService.php`): OpenRegister supports inter-object references via UUID, which can model the relationships between vergunningaanvragen, activiteiten, locaties, and omgevingsdocumenten.
- **Import/export** (`lib/Service/Configuration/ImportHandler.php`, `ExportHandler.php`): Configuration import/export can distribute pre-built DSO schema templates.
- **Audit trail** (`lib/Db/AuditTrail.php`): Existing audit trail captures object changes, supporting the status transition tracking required for vergunningaanvragen.
- **Multi-tenancy**: OpenRegister's organization/tenant model supports multiple municipalities using the same instance with isolated data.

### Not implemented
- DSO entity schemas (vergunningaanvraag, activiteit, locatie, omgevingsdocument, besluit)
- STAM reference data import mechanism
- DSO API output mapping definitions
- Vergunningcheck data model and query interface
- DSO status lifecycle validation (allowed transitions)
- Demo/mock data seeder for DSO entities
- Spatial query support for werkingsgebied/locatie (depends on index backend)
- IMOW identification format validation

## Standards & References
- **Omgevingswet (2024)**: Dutch Environment and Planning Act, effective January 1, 2024. Replaces Wabo, Wro, Wet milieubeheer, and 26 other laws.
- **DSO-LV (Digitaal Stelsel Omgevingswet - Landelijke Voorziening)**: National digital system operated by Kadaster/RWS. Provides Omgevingsloket, vergunningcheck, regelgeving, and STAM.
- **STAM (Stelselcatalogus Activiteiten Module)**: National catalog of activiteiten under the Omgevingswet with standardized codes, regelkwalificaties, and bevoegd gezag assignments.
- **IMOW (Informatiemodel Omgevingswet)**: Information model for omgevingsdocumenten, defining structure for omgevingsplannen, -visies, and -verordeningen. Maintained by Geonovum.
- **STOP/TPOD (Standaard Officiële Publicaties / Toepassingsprofiel Omgevingsdocumenten)**: Publication standard for omgevingsdocumenten.
- **GeoJSON (RFC 7946)**: Standard for encoding geographic data, used for locatie geometrie and werkingsgebieden.
- **BAG (Basisregistratie Adressen en Gebouwen)**: National address and building registry, managed by Kadaster.
- **BRK (Basisregistratie Kadaster)**: National cadastral registry for kadastrale aanduidingen.
- **OIN (Organisatie-Identificatienummer)**: Unique identifier for Dutch government organizations, used as `bronOrganisatie`.
- **PKIoverheid**: Dutch government PKI for mTLS authentication with DSO-LV (relevant for OpenConnector adapter, referenced here for context).
- **STTR (Standaard voor Toepasbare Regels)**: Standard for executable rules used in the vergunningcheck (out of scope for this spec, but referenced for context).
- **Common Ground principles**: API-first, data-at-the-source architecture for Dutch municipalities.

## Specificity Assessment

### Sufficient for implementation
- The five core schemas (vergunningaanvraag, activiteit, locatie, omgevingsdocument, besluit) are defined with clear field types and cardinality.
- The relationship between OpenRegister (data store) and OpenConnector (connection layer) is explicitly defined with scenario-based boundary clarification.
- Demo data requirements are concrete with specific minimum counts and content expectations.
- Status lifecycle is defined with valid values and transition constraints.

### Missing or ambiguous
- **STAM import mechanism**: The spec requires STAM import but does not specify the source format (REST API, CSV, XML) or update frequency. The national STAM catalog's API is not yet stable.
- **Spatial query syntax**: REQ-DSO-005 requires location-based queries but does not specify the query syntax (bounding box, point-in-polygon, radius search) or which index backend is required.
- **Schema property mapping detail**: The data model tables define fields conceptually but do not include the full JSON Schema definitions with validation rules, patterns, and nested object structures.
- **Versioning of omgevingsdocumenten**: IMOW supports multiple versions of omgevingsdocumenten (ontwerp, vastgesteld, consolidated). The versioning strategy is not specified.
- **Samenloop between activiteiten**: When multiple activiteiten apply to one locatie with different bevoegd gezag (gemeente + waterschap), the coordination mechanism is undefined.
- **Legesberekening**: The `bouwkosten` field exists but there is no spec for how leges are calculated from it (this may belong in Procest).

### Open questions
1. Should DSO schemas use Dutch or English property names internally? The ZGW mapping spec uses English internally with Dutch mapping — should DSO follow the same pattern, or use Dutch natively since DSO is inherently Dutch?
2. How should STAM reference data be kept in sync — periodic import, real-time API calls, or manual upload?
3. Should the vergunningcheck query endpoint live in OpenRegister (data query) or in a separate service that combines register data with STTR rules?
4. What level of IMOW compliance is needed for omgevingsdocumenten — minimal metadata or full annotatie/juridische-regel support?
5. How does the DSO register relate to the product-service-catalog spec — are omgevingsvergunningen also products in the PDC sense?

## Nextcloud Integration Analysis

**Status**: Not yet implemented. No DSO-specific schemas, mappings, or API endpoints exist. The core OpenRegister infrastructure (schemas, objects, mapping engine, audit trail) provides the foundation.

**Nextcloud Core Interfaces**:
- `routes.php`: Register a DSO API endpoint group (e.g., `/api/dso/`) for DSO-compatible output. Alternatively, use the generic ZGW/mapping route infrastructure once the `zgw-api-mapping` spec is implemented.
- `IEventDispatcher`: Fire typed events (e.g., `DsoStatusChangedEvent`) when a vergunningaanvraag transitions status, enabling OpenConnector or other listeners to push updates to DSO-LV via webhooks.
- `IJobList` / `TimedJob`: Schedule periodic STAM reference data imports and DSO-LV synchronization checks as background jobs.
- `INotifier` / `INotification`: Send notifications to behandelaars when a new vergunningaanvraag arrives from DSO-LV or when status transitions require action.

**Implementation Approach**:
- Define DSO entity schemas (vergunningaanvraag, activiteit, locatie, omgevingsdocument, besluit) as standard OpenRegister schemas with JSON Schema validation rules. Deploy via a register template JSON file loaded through `openregister:load-register` CLI command or repair step.
- Use `MappingService` for bidirectional property mapping between English-internal properties and Dutch DSO API output, following the same pattern as the ZGW API mapping spec.
- Leverage OpenConnector as the external API gateway for DSO-LV communication. OpenRegister stores and validates the data; OpenConnector handles mTLS/PKIoverheid authentication and STAM koppelvlak protocol specifics.
- Store GeoJSON geometry in object properties for locatie and werkingsgebied fields. Spatial querying depends on the `geo-metadata-kaart` spec or Solr/Elasticsearch backends with geo_shape support.
- Use `AuditTrailMapper` for recording status transitions on vergunningaanvragen, providing the immutable audit history required for government processes.

**Dependencies on Existing OpenRegister Features**:
- `SchemaService` / `RegisterService` — schema definitions and register provisioning.
- `MappingService` — Twig-based property/value mapping for DSO API output formatting.
- `ObjectService` — CRUD with validation, filtering, and inter-object references (UUID-based).
- `AuditTrailMapper` — status transition logging and change history.
- `ImportHandler` / `ExportHandler` — register template distribution and STAM reference data import.
- OpenConnector app — external DSO-LV connectivity (separate app, separate spec).
