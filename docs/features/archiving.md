# Archiving & Records Management

## Overview

OpenRegister implements a complete records management and archiving system for Dutch government compliance. This covers MDTO-compliant archival metadata on objects, selectielijsten-based retention scheduling, automated destruction workflows with multi-step approval, legal hold management, destruction certificate generation, and e-Depot transfer (SIP packages) for permanent archival at the Nationaal Archief or a municipal e-Depot.

## Standards Compliance

| Standard | Scope |
|----------|-------|
| Archiefwet 1995 | Statutory basis for government records retention |
| MDTO (Metagegevens Duurzaam Toegankelijke Overheidsinformatie) | Archival metadata XML schema |
| NEN 15489 | Records management principles, destruction workflows |
| NEN-ISO 16175-1:2020 | Principles and functional requirements for records in digital office environments |
| OAIS (ISO 14721) | Open Archival Information System — SIP/AIP/DIP packaging model |
| PRONOM | File format identification for MDTO `bestandsformaat` |
| WCAG AA | Accessibility for archivist-facing interfaces |

## Archival Metadata (MDTO)

Every object in an archive-enabled schema carries MDTO-compliant fields in its `retention` property:

| MDTO Field | Object Field | Description |
|-----------|-------------|-------------|
| `archiefnominatie` | `retention.archiefnominatie` | `vernietigen` or `blijvend_bewaren` |
| `archiefstatus` | `retention.archiefstatus` | `nog_te_archiveren`, `gearchiveerd`, `overgedragen` |
| `bewaartermijn` | `retention.bewaartermijn` | ISO 8601 duration (e.g., `P5Y`, `P20Y`) |
| `archiefactiedatum` | `retention.archiefactiedatum` | Calculated date for archival action |
| `classificatie` | `retention.classificatie` | Selectielijst category code |
| `toelichting` | `retention.toelichting` | Free-text explanation |

### Schema Archive Configuration

Schemas can define defaults so all new objects start with correct archival metadata:

```json
{
  "archive": {
    "enabled": true,
    "defaultNominatie": "vernietigen",
    "defaultBewaartermijn": "P5Y",
    "purgeAfter": "P10Y"
  }
}
```

When a new object is created in this schema, `retention` is automatically populated with `archiefnominatie: "vernietigen"`, `archiefstatus: "nog_te_archiveren"`, `bewaartermijn: "P5Y"`, and `archiefactiedatum` calculated as creation date + 5 years.

## Retention Lifecycle

```
nog_te_archiveren → [archiefactiedatum reached] → destruction list OR e-Depot transfer
                                                  ↓
                                            gearchiveerd / overgedragen
```

### Archiefactiedatum Calculation (Afleidingswijzen)

The `archiefactiedatum` is calculated using configurable `afleidingswijzen` (derivation methods):

| Afleidingswijze | Calculation |
|----------------|-------------|
| `aanmaakdatum + bewaartermijn` | Creation date + retention period |
| `wijzigingsdatum + bewaartermijn` | Last modification date + retention period |
| `einddatum + bewaartermijn` | Object's end date + retention period |
| `vaste_datum` | Fixed absolute date |
| Custom Twig expression | Evaluated at runtime |

## Destruction Workflow

### Automated Destruction Scheduling

`DestructionCheckJob` (a `TimedJob`) runs daily and:

1. Scans for objects where `retention.archiefactiedatum` < today AND `retention.archiefnominatie` = `vernietigen`
2. Excludes objects with active legal holds (`retention.legalHold.active` = true)
3. Creates a **destruction list object** in the archival register containing references to all eligible objects
4. Notifies the designated archivist(s)

### Destruction List Workflow

A destruction list is itself a register object that passes through a configurable approval workflow:

| Step | Actor | Action |
|------|-------|--------|
| 1 | System | Generate destruction list with object references |
| 2 | Archivist | Review list, remove objects that should not be destroyed |
| 3 | Archivist | Approve destruction list |
| 4 | Manager | Counter-sign (optional, configurable) |
| 5 | System | Execute destruction (soft delete → physical purge) |
| 6 | System | Generate destruction certificate |

Each step is tracked with timestamps, actor identities, and audit trail entries.

### Destruction Certificate

After execution, the system generates a destruction certificate containing:

- List of destroyed objects (UUID, title, schema, register)
- `archiefactiedatum` and `bewaartermijn` per object
- Selectielijst categories
- Approval chain (names, timestamps, signatures)
- Total count and data volume destroyed
- Certificate UUID and timestamp

### Legal Hold Management

Objects can be placed under legal hold to prevent destruction:

```
POST /api/objects/{register}/{schema}/{id}/legal-hold
{
  "reason": "Lopende bezwaarprocedure",
  "heldBy": "juridisch-team",
  "expiresAt": "2027-01-01"
}
```

Objects with active legal holds are excluded from destruction lists. Legal holds appear in audit trail and MDTO metadata.

## e-Depot Transfer

### MDTO XML Generation

Each object selected for e-Depot transfer generates MDTO-compliant XML:

```xml
<mdto:informatieobject xmlns:mdto="https://www.nationaalarchief.nl/mdto">
  <identificatie>
    <identificatiekenmerk>550e8400-e29b-41d4-a716-446655440000</identificatiekenmerk>
    <identificatiebron>OpenRegister/meldingen-register</identificatiebron>
  </identificatie>
  <naam>Geluidsoverlast Kerkstraat 12</naam>
  <waardering>vernietigen</waardering>
  <bewaartermijn>P5Y</bewaartermijn>
  <archiefvormer>Gemeente Voorbeeld</archiefvormer>
  <bestand>
    <naam>bijlage.pdf</naam>
    <omvang>204800</omvang>
    <bestandsformaat>fmt/276</bestandsformaat>
    <checksum>
      <checksumAlgoritme>SHA-256</checksumAlgoritme>
      <checksumWaarde>abc123...</checksumWaarde>
    </checksum>
  </bestand>
</mdto:informatieobject>
```

The XML is validated against the MDTO XSD schema before inclusion in any SIP package.

### SIP Package Assembly

Objects approved for transfer are packaged into SIP (Submission Information Package) archives per OAIS (ISO 14721):

- Self-contained ZIP archive
- Contains: MDTO XML metadata per object, all associated content files, a manifest with checksums
- Suitable for ingest by the Nationaal Archief or any OAIS-compliant e-Depot

### Transfer Workflow API

```
GET    /api/archival/eligible               List objects eligible for archival
POST   /api/archival/transfer-list          Create a transfer list
GET    /api/archival/transfer-list/{id}     Get transfer list status
POST   /api/archival/transfer-list/{id}/approve  Approve transfer list
GET    /api/archival/sip/{id}               Download SIP package (ZIP)
GET    /api/archival/destruction-lists      List destruction lists
POST   /api/archival/destruction-lists/{id}/approve  Approve destruction
GET    /api/archival/certificates/{id}      Download destruction certificate
```

## GEMMA Component Alignment

| GEMMA Component | How OpenRegister supports it |
|----------------|------------------------------|
| Archiefregistratiecomponent | Object archival metadata (MDTO), retention tracking |
| Archiefbeheercomponent | Destruction workflows, legal holds, selectielijsten |
| Archiefportaalcomponent | e-Depot transfer (SIP packages), transfer lists |

## Related Features

- [Object Storage & Lifecycle](object-storage.md) — archival metadata lives on objects; soft delete underpins destruction
- [Content Versioning & Audit Trail](versioning-and-audit.md) — audit trail required for destruction evidence
- [Access Control (RBAC)](access-control.md) — archivists and managers have specific roles in workflows
- [Workflow Automation](workflow-automation.md) — destruction approval workflows run via n8n
