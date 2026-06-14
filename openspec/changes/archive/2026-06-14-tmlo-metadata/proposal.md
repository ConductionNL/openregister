# Proposal: TMLO Metadata Standard Support

## Summary

Add optional TMLO (Toepassingsprofiel Metadatastandaard Lokale Overheden) metadata fields to OpenRegister objects. When enabled on a register or schema, objects automatically receive TMLO-compliant archival metadata (classification, retention, destruction date, archive status, etc.). This makes any app using OpenRegister -- Procest, Pipelinq, Docudesk, and others -- archival-compliant by default without each app implementing TMLO separately.

## Problem

Dutch municipalities must comply with TMLO for archival metadata on government records. Currently OpenRegister objects have no structured archival metadata conforming to the TMLO standard. Each consuming app would need to implement its own archival metadata layer, leading to inconsistency, duplication, and compliance gaps.

TMLO is the local government profile of MDTO (Metadatastandaard voor Duurzaam Toegankelijke Overheidsinformatie), which is the national standard from Rijksoverheid. Both standards feed into the e-Depot ecosystem maintained by the Nationaal Archief.

## Demand Evidence

- **TMLO**: 54 tender sources explicitly requiring TMLO compliance
- **MDTO**: 73 tender sources requiring MDTO (the national standard that TMLO profiles)
- **e-Depot**: 56 tender sources requiring e-Depot integration (which depends on TMLO/MDTO metadata)
- **Digital archiving**: 141 tender sources requiring digital archiving capabilities

### Sample Requirements from Tenders

1. Municipalities require TMLO-compliant metadata on all zaakdossiers before transfer to e-Depot
2. Archival metadata must include classificatie, archiefnominatie, archiefactiedatum, and bewaarTermijn
3. Objects must carry vernietigingsCategorie linked to VNG Selectielijst result types
4. Systems must support export in MDTO/TMLO XML format for e-Depot ingest
5. Archival status transitions (actief, semi-statisch, overgebracht, vernietigd) must be tracked with audit trail

## Scope

### In Scope

- **TMLO metadata schema**: Add TMLO-compliant fields to OpenRegister objects -- classificatie, archiefnominatie, archiefactiedatum, archiefstatus, bewaarTermijn, vernietigingsCategorie
- **Configurable per register**: Enable or disable TMLO metadata per register, so only registers that need archival compliance carry the overhead
- **Auto-populate metadata**: Automatically fill metadata fields based on schema/register-level settings (default retention periods, default classification, default archiefnominatie)
- **TMLO export format**: Generate TMLO/MDTO-compliant XML for e-Depot integration and archival transfer
- **Metadata validation**: Enforce required TMLO fields before allowing archival status transitions (e.g., cannot set archiefstatus to "overgebracht" without archiefactiedatum)
- **MDTO compatibility**: Ensure metadata model aligns with MDTO as the parent standard -- TMLO is the local government profile of MDTO
- **Archival status query endpoints**: API endpoints to query objects by archival status (e.g., "ready for destruction", "transferred to e-Depot", "permanently retained")

### Out of Scope

- Actual destruction execution (see: `archival-destruction-workflow`)
- e-Depot transfer protocol/connection (see: `edepot-transfer`)
- Retention period calculation engine (see: `retention-management`)
- DMS-level document management features

## Features

1. **TMLO metadata schema** -- Structured metadata fields conforming to TMLO 1.2: classificatie, archiefnominatie (blijvend bewaren / vernietigen), archiefactiedatum, archiefstatus (actief / semi-statisch / overgebracht / vernietigd), bewaarTermijn, vernietigingsCategorie
2. **Register-level TMLO toggle** -- Configurable per register: enable/disable TMLO metadata. When enabled, all objects in that register carry TMLO fields
3. **Auto-populate defaults** -- Schema and register settings define default retention periods, classification codes, and archiefnominatie. New objects inherit these defaults automatically
4. **TMLO/MDTO export** -- Export objects with their TMLO metadata in MDTO-compliant XML format, suitable for e-Depot ingest workflows
5. **Metadata validation rules** -- Required-field validation before archival status changes. Configurable per register to enforce completeness before transfer or destruction
6. **MDTO compatibility layer** -- TMLO is the local government profile of MDTO. The metadata model supports both, allowing central government apps to use MDTO directly
7. **Archival status query API** -- Endpoints to filter and retrieve objects by archiefstatus, archiefactiedatum ranges, and vernietigingsCategorie for batch operations

## Acceptance Criteria

1. A register can be configured to enable TMLO metadata on its objects
2. When TMLO is enabled, all objects in that register carry the six core TMLO fields
3. Default values for TMLO fields can be configured at register and schema level
4. New objects automatically inherit TMLO defaults from their schema/register configuration
5. Archival status transitions are validated -- required fields must be present before status change
6. Objects can be exported in MDTO-compliant XML format including all TMLO metadata
7. API endpoints allow querying objects by archiefstatus and archiefactiedatum range
8. TMLO metadata is stored as first-class object metadata (not custom properties)

## Dependencies

- OpenRegister Register and Schema entities for TMLO configuration storage
- OpenRegister ObjectService for metadata management
- `retention-management` change for retention period calculation (complementary, not blocking)
- `edepot-transfer` change for actual e-Depot connection (uses TMLO export as input)

## Standards & Regulations

- **TMLO 1.2** -- Toepassingsprofiel Metadatastandaard Lokale Overheden (Nationaal Archief)
- **MDTO** -- Metadatastandaard voor Duurzaam Toegankelijke Overheidsinformatie (Rijksoverheid)
- **e-Depot** -- Nationaal Archief digital repository standards
- **GEMMA Archiefregistratiecomponent** -- Reference architecture for archival registration in municipalities
- **Archiefwet 1995** -- Dutch Archives Act
- **Selectielijst gemeenten** -- VNG retention schedule for municipal records

## Impact

All apps storing data in OpenRegister benefit automatically from TMLO compliance:
- **Procest** -- Process/zaak records get archival metadata
- **Pipelinq** -- Pipeline objects can be classified and retained
- **Docudesk** -- Document metadata includes TMLO fields for archival transfer
- **ZaakAfhandelApp** -- Zaak handling inherits archival compliance
- **OpenCatalogi** -- Catalog items carry proper archival metadata

## Notes

- This change complements `retention-management` (which handles retention period calculation) and `edepot-transfer` (which handles the actual transfer protocol). TMLO metadata provides the data model that both depend on.
- TMLO 1.2 is the current version maintained by the Nationaal Archief. The metadata model should be versioned to support future TMLO updates.
- MDTO is increasingly replacing TMLO as the primary standard. The implementation should treat MDTO as the base and TMLO as a profile/subset.
