---
status: done
---

# tmlo-export Specification

## Purpose

@e2e exclude backend MDTO/XML export service — covered by PHPUnit
TBD - created by archiving change tmlo-metadata. Update Purpose after archive.
## Requirements
### Requirement: MDTO-compliant XML export

The system SHALL provide an XML export of objects with their TMLO metadata as
MDTO, valid against MDTO-XML 1.0.1 as vendored at
`lib/Resources/mdto/MDTO-XML1.0.1.xsd`.

There SHALL be one implementation of the MDTO format. This export and the
e-Depot export in `edepot-transfer` both go through `MdtoXmlGenerator`, so a
change to the format reaches both, and neither can drift into a shape the
schema rejects while the other stays valid.

**MDTO output carries MDTO elements.** This requirement used to list
`archiefnominatie`, `archiefactiedatum`, `archiefstatus`, `bewaarTermijn` and
`vernietigingsCategorie`, which are TMLO field names, and three of them are
not MDTO elements at all. That list is what made the output invalid. TMLO
remains how the facts are STORED; MDTO is how they are EXPORTED, and the two
are different vocabularies. Each stored fact SHALL be exported under the MDTO
element that carries it:

| TMLO field | MDTO element |
| --- | --- |
| `archiefnominatie` | `waardering`, as a term of the closed Waarderingen list |
| `bewaarTermijn` | `bewaartermijn/termijnLooptijd` |
| `archiefactiedatum` | `bewaartermijn/termijnEinddatum` |
| `vernietigingsCategorie` | `informatiecategorie`, the selectielijst category that decides disposal |
| `classification` | `classificatie`, the classification scheme code |
| `archiefstatus` | none: MDTO has no such element |

`archiefstatus` is the one fact with no MDTO element, and it is not lost.
MDTO expresses the same lifecycle as `event` (Overbrenging, Vernietigen),
which the generator derives from the audit trail, and openregister exposes it
abstractly as `_retention.status` from the `RecordState` vocabulary. A
consumer that needs the raw TMLO field SHALL read the object's `tmlo` block
or the abstract answer; it SHALL NOT be added back to the MDTO output, because
a document carrying it cannot validate.

The XML output SHALL include:
- Root element `MDTO` in the MDTO namespace, holding exactly one
  `informatieobject`, carrying the schema version in `xsi:schemaLocation`
- `identificatie` with the object UUID and the organisation as its source
- `naam` with the object's name, from its data, else the entity's name, else
  its UUID
- `waardering`, which MDTO requires
- `beperkingGebruik`, which MDTO requires
- the elements in the table above, each when the object carries the fact

An object whose appraisal is unknown SHALL be refused rather than exported
without `waardering`, which is `minOccurs="1"`. An object whose retention
period is unknown SHALL still be exported, without `bewaartermijn`, which
MDTO marks "Verplicht: Ja, indien bekend"; refusing such a record is a
transfer-time policy and belongs to `edepot-transfer`.

#### Scenario: Export single object as MDTO XML

- **WHEN** a GET request is made to `/api/tmlo/{register}/{schema}/{id}/export`
- **THEN** the response SHALL be an XML document with Content-Type `application/xml`
- **AND** the document SHALL validate against the vendored MDTO XSD
- **AND** each TMLO fact SHALL appear under the MDTO element that carries it, per the table above

#### Scenario: Export object without TMLO metadata

- **WHEN** an export is requested for an object with no TMLO metadata
- **THEN** the response SHALL return a 422 error indicating TMLO metadata is required for MDTO export

### Requirement: Batch export uses an envelope that does not claim to be MDTO

MDTO has no batch container: an `MDTO` document holds exactly one
informatieobject or one bestand. A batch export SHALL therefore wrap complete
MDTO documents in an envelope element of openregister's own, in
openregister's own namespace, and SHALL NOT invent an element in the MDTO
namespace. The previous `mdto:informatieobjecten` wrapper did exactly that.

Every child of the envelope SHALL be a complete MDTO document that validates
on its own. An object without TMLO metadata SHALL be skipped rather than
failing the batch.

#### Scenario: Batch envelope carries whole MDTO documents
- **WHEN** two objects with TMLO metadata are exported as a batch
- **THEN** the envelope element MUST be in openregister's namespace, not MDTO's
- **AND** it MUST contain one `mdto:MDTO` document per object, each valid against the vendored XSD

#### Scenario: Batch export objects as MDTO XML

- **WHEN** a GET request is made to `/api/tmlo/{register}/{schema}/export` with optional query filters
- **THEN** the response SHALL be an XML document whose root is openregister's envelope element
- **AND** it SHALL contain one complete MDTO document per exported object
