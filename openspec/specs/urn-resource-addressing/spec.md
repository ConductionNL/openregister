---
status: draft
---

# urn-resource-addressing Specification

## Purpose
Implement bidirectional URN-URL mapping for system-independent resource identification. Register objects MUST support URN identifiers following the pattern `urn:{organisation}:{system}:{component}:{resource}:{uuid}` that can be resolved to URLs and vice versa. This enables location-independent addressing of government resources across multi-vendor environments.

**Source**: Gap identified in cross-platform analysis; part of Dutch government standards ecosystem (VNG).

## ADDED Requirements

### Requirement: Objects MUST support URN identifiers
Every register object MUST have an auto-generated URN following a configurable pattern.

#### Scenario: Auto-generate URN on object creation
- GIVEN a register `zaken` owned by organisation `gemeente-utrecht`
- AND schema `meldingen` in the OpenRegister system
- WHEN a new melding object with UUID `abc-123` is created
- THEN a URN MUST be generated: `urn:gemeente-utrecht:openregister:zaken:meldingen:abc-123`
- AND the URN MUST be stored on the object and returned in API responses

#### Scenario: Configure URN pattern per register
- GIVEN the admin configures register `producten` with:
  - Organisation: `gemeente-utrecht`
  - System: `openregister`
  - Custom component: `pdc`
- THEN objects in this register MUST use URN pattern: `urn:gemeente-utrecht:openregister:pdc:{schema}:{uuid}`

### Requirement: The system MUST resolve URNs to URLs
A resolution endpoint MUST translate URNs to the corresponding API URLs.

#### Scenario: Resolve URN to URL
- GIVEN a URN `urn:gemeente-utrecht:openregister:zaken:meldingen:abc-123`
- WHEN the resolution endpoint receives GET /api/urn/resolve?urn={urn}
- THEN the response MUST return:
  - `url`: `https://gemeente-utrecht.nl/index.php/apps/openregister/api/objects/zaken/meldingen/abc-123`
  - `objectUuid`: `abc-123`
  - `register`: `zaken`
  - `schema`: `meldingen`

#### Scenario: Resolve non-existent URN
- GIVEN a URN that does not match any registered object
- WHEN the resolution endpoint is queried
- THEN the response MUST return HTTP 404 with a descriptive message

### Requirement: The system MUST resolve URLs to URNs
A reverse resolution endpoint MUST translate URLs back to URN identifiers.

#### Scenario: Reverse resolve URL to URN
- GIVEN object `abc-123` exists with a URN
- WHEN the endpoint receives GET /api/urn/reverse?url={object-url}
- THEN the response MUST return the URN: `urn:gemeente-utrecht:openregister:zaken:meldingen:abc-123`

### Requirement: URN mapping tables MUST support external resources
The system MUST support registering URN-URL mappings for resources that live outside of OpenRegister.

#### Scenario: Register external URN mapping
- GIVEN an external system hosts resource `urn:gemeente-utrecht:zaaksysteem:zaken:zaak:xyz-789`
- WHEN the admin registers the mapping:
  - URN: `urn:gemeente-utrecht:zaaksysteem:zaken:zaak:xyz-789`
  - URL: `https://zaaksysteem.gemeente-utrecht.nl/api/zaken/xyz-789`
- THEN resolving this URN MUST return the registered URL

#### Scenario: Bulk import external mappings
- GIVEN a CSV file with 1000 URN-URL pairs from an external system
- WHEN the admin imports the mappings
- THEN all 1000 pairs MUST be registered in the mapping table
- AND duplicates MUST be detected and reported

### Requirement: URNs MUST be stable across system migrations
URN identifiers MUST remain valid even if the underlying URL or system changes.

#### Scenario: Update URL for existing URN
- GIVEN a URN `urn:gemeente-utrecht:openregister:zaken:meldingen:abc-123` mapped to `https://old-server.nl/api/...`
- WHEN the system migrates to a new URL
- THEN the admin MUST be able to update the URL mapping
- AND the URN MUST remain unchanged
- AND old URLs SHOULD redirect to the new URL

### Requirement: URN references MUST be usable in object properties
Schema properties MUST support a URN reference type for cross-system linking.

#### Scenario: Link to external resource via URN
- GIVEN schema `vergunningen` with property `bronZaak` of type `urn`
- WHEN the user sets `bronZaak` to `urn:gemeente-utrecht:zaaksysteem:zaken:zaak:xyz-789`
- THEN the system MUST store the URN reference
- AND the UI MUST display the resolved resource name (if resolvable) with a link to the URL

### Current Implementation Status

**Not implemented.** No URN support exists in the codebase:

- No URN generation on object creation
- No URN resolution endpoint (`/api/urn/resolve`)
- No reverse resolution endpoint (`/api/urn/reverse`)
- No URN mapping table or entity
- No URN property type in schema definitions
- No organisation-level URN configuration
- Objects have `uuid` fields but no `urn` field

The only URN-like patterns found in the codebase are unrelated (e.g., `urn:ietf:params:...` in authentication service for JWT handling).

### Standards & References
- RFC 8141 -- Uniform Resource Names (URNs) syntax
- RFC 2141 -- URN Syntax (superseded by RFC 8141)
- NEN 3610 -- Dutch geographic information standard (uses URN-based identifiers for geo-objects)
- VNG Common Ground -- recommends URN-based resource identification for interoperability
- NL GOV API Design Rules (API-49) -- stable identifiers for government resources
- PURL (Persistent URL) -- alternative approach to stable resource addressing

### Specificity Assessment
- **Specific enough to implement?** Partially -- the URN pattern and resolution endpoints are clear, but several details are missing.
- **Missing/ambiguous:**
  - No specification for URN validation (what characters are allowed in each segment?)
  - No specification for how URN pattern is stored (register-level config, global config?)
  - No specification for URN uniqueness enforcement (can two objects have the same URN?)
  - No specification for the URN mapping table schema (what entity stores external mappings?)
  - No specification for URN in GraphQL or MCP API (only REST)
  - No specification for performance of URN resolution (indexed lookup? cache?)
  - No specification for bulk URN resolution
- **Open questions:**
  - Should URNs be auto-generated as a computed field or stored as a dedicated column?
  - How should URN resolution work for federated/distributed deployments?
  - Is the URN pattern `urn:{org}:{system}:{component}:{resource}:{uuid}` aligned with RFC 8141 NID requirements?

## Nextcloud Integration Analysis

**Status**: Not yet implemented. No URN generation, resolution endpoints, mapping tables, or URN property types exist. Objects have `uuid` fields but no `urn` field.

**Nextcloud Core Interfaces**:
- `IURLGenerator` (`OCP\IURLGenerator`): Use Nextcloud's URL generator for constructing the URL portion of URN-URL mappings. `IURLGenerator::linkToRouteAbsolute()` generates stable absolute URLs for OpenRegister API endpoints, ensuring URN resolution returns correct URLs regardless of reverse proxy configuration.
- `ICapability` (`OCP\Capabilities\ICapability`): Expose URN resolution endpoint availability and the configured URN namespace (organisation, system) via Nextcloud capabilities. Clients can discover the resolution endpoint at `/ocs/v2.php/cloud/capabilities` and use it for URN lookups.
- `routes.php`: Register URN resolution endpoints (`/api/urn/resolve`, `/api/urn/reverse`) as dedicated routes. These are lightweight lookup endpoints that do not require the full object retrieval pipeline.
- `IAppConfig`: Store URN configuration (organisation identifier, system name, default component prefix) in Nextcloud app configuration at the register level.

**Implementation Approach**:
- Add a `urn` field to `ObjectEntity` (or compute it on-the-fly). The URN is constructed from the register's organisation, system name (`openregister`), register slug (component), schema slug (resource), and object UUID. Configuration is stored on the `Register` entity as metadata properties.
- Create a `UrnService` with methods: `generateUrn(ObjectEntity)`, `resolveUrn(string)`, `reverseResolve(string)`. The service parses URN segments to identify register, schema, and UUID, then uses `ObjectService` to verify the object exists. For external URN mappings, a `UrnMapping` entity stores the URN-URL pairs.
- Register URN resolution routes in `routes.php`. The `UrnController` handles resolve (URN to URL+metadata) and reverse (URL to URN) requests. Both endpoints support single and bulk operations.
- For external URN mappings, create a `UrnMappingMapper` (Nextcloud Entity/Mapper pattern) with a database table storing: `urn` (indexed, unique), `url`, `label`, `source_system`, and `created_at`. Bulk import from CSV uses a `QueuedJob` to avoid timeout issues.
- Add a `urn` property type to the schema property system, enabling schema properties to store URN references. The UI resolves URN references to display the resource name (if resolvable) with a link to the resolved URL.

**Dependencies on Existing OpenRegister Features**:
- `ObjectEntity` — object model where URN is generated/stored.
- `ObjectService` — object retrieval for URN resolution verification.
- `RegisterService` / `SchemaService` — register and schema metadata for URN segment construction.
- `MagicMapper` — indexed lookup for efficient URN resolution queries.
- Schema property type system — extension point for the `urn` property type.
