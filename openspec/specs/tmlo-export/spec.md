---
status: done
---

# tmlo-export Specification

## Purpose

@e2e exclude backend MDTO/XML export service — covered by PHPUnit
TBD - created by archiving change tmlo-metadata. Update Purpose after archive.
## Requirements
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

**Known gap: this export does not validate against MDTO, and the list above is why.**
Measured on 2026-09-11 against the Nationaal Archief's MDTO-XML 1.0.1 schema,
vendored at `lib/Resources/mdto/MDTO-XML1.0.1.xsd`, the output of
`TmloService::generateMdtoXml()` is rejected at its root: "Element
'{https://www.nationaalarchief.nl/mdto}informatieobject': No matching global
declaration available for the validation root." The schema's only global
element is `MDTO`. Past the root, the export emits three elements that do not
exist in MDTO at all: `archiefactiedatum`, `archiefstatus` and
`vernietigingsCategorie`, which are TMLO fields. (Two further items in the
list above, `archiefnominatie` and `bewaarTermijn`, are also TMLO spellings,
but the code already emits them as MDTO's `waardering` and `bewaartermijn`.)
A document carrying those three cannot validate however it is wrapped, so the
sentence above claiming MDTO conformance and the element list beneath it
cannot both hold. Choosing which gives way is a decision for
this spec; the e-Depot export in `edepot-transfer` already validates.

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

