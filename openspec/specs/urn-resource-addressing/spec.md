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
