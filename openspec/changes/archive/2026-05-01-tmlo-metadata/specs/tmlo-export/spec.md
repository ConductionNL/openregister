## ADDED Requirements

### Requirement: MDTO-compliant XML export

The system SHALL provide an XML export of objects with their TMLO metadata in MDTO-compliant format. The export SHALL conform to the MDTO XML schema (Metadatastandaard voor Duurzaam Toegankelijke Overheidsinformatie).

The XML output SHALL include:
- Root element with MDTO namespace
- `identificatie` with the object UUID
- `naam` with the object name
- `classificatie` with the classification code
- `archiefnominatie` with the archival nomination
- `archiefactiedatum` with the archival action date
- `archiefstatus` mapping TMLO values to MDTO equivalents
- `bewaarTermijn` with the retention period
- `vernietigingsCategorie` with the destruction category

#### Scenario: Export single object as MDTO XML

- **WHEN** a GET request is made to `/api/objects/{register}/{schema}/{id}/export/mdto`
- **THEN** the response SHALL be an XML document with Content-Type `application/xml`
- **THEN** the XML SHALL contain the object's TMLO metadata in MDTO format

#### Scenario: Export object without TMLO metadata

- **WHEN** an export is requested for an object with no TMLO metadata
- **THEN** the response SHALL return a 422 error indicating TMLO metadata is required for MDTO export

#### Scenario: Batch export objects as MDTO XML

- **WHEN** a GET request is made to `/api/objects/{register}/{schema}/export/mdto` with optional query filters
- **THEN** the response SHALL be an XML document containing multiple object elements
- **THEN** each object SHALL include its TMLO metadata in MDTO format
