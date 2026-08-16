## Why

Government organisations with `archiefnominatie` set to `bewaren` (permanent preservation) are legally required under the Archiefwet 1995 to transfer records to an e-Depot after the retention period expires. OpenRegister already has the `retention` field on objects and the `archive` configuration on schemas, but there is no implementation for generating MDTO-compliant metadata XML, assembling SIP (Submission Information Package) packages, or transmitting them to external e-Depot systems. This is a top-priority gap: 77% of government tenders require archiving capabilities, and e-Depot transfer (overbrenging) is mandatory for `bewaren`-nominated records.

## What Changes

- Add an `EdepotTransferService` that generates MDTO XML metadata per object, conforming to the MDTO schema (version 1.0+)
- Add a `SipPackageBuilder` that assembles a standards-compliant SIP package containing MDTO XML, associated files (original + PDF/A renditions), METS structural metadata, PREMIS preservation metadata with SHA-256 fixity, and a manifest
- Add a `TransferListService` that creates, manages, and tracks transfer lists (analogous to destruction lists but for `bewaren` objects)
- Add API endpoints for e-Depot configuration (`PUT /api/settings/edepot`), transfer initiation (`POST /api/transfers`), transfer status (`GET /api/transfers/{id}`), and transfer list management
- Add a `TransferCheckJob` background job that identifies objects eligible for transfer (reached `archiefactiedatum` with `archiefnominatie` = `bewaren`)
- Support multiple transport protocols: SFTP, REST API, and OpenConnector source integration
- Update `ObjectEntity.retention` to track transfer status fields: `archiefstatus` transitions to `overgebracht`, `eDepotReferentie`, `transferErrors[]`
- Objects marked as `overgebracht` become read-only (no further modifications allowed)
- Audit trail entries for all transfer actions (`archival.transferred`, `archival.transfer_failed`, `archival.transfer_initiated`)

## Capabilities

### New Capabilities
- `edepot-transfer`: SIP package generation (MDTO XML, METS, PREMIS), transfer list management, e-Depot endpoint configuration, transport (SFTP/REST/OpenConnector), transfer status tracking, and read-only enforcement for transferred objects

### Modified Capabilities
- `archivering-vernietiging`: The existing spec defines e-Depot scenarios at a high level (scenarios under "The system MUST support e-Depot export"). This change implements those requirements and adds detail around transfer list workflow, partial failure handling, and transport protocol configuration.

## Impact

- **New PHP classes**: `EdepotTransferService`, `SipPackageBuilder`, `MdtoXmlGenerator`, `TransferListService`, `TransferCheckJob`, `EdepotSettingsController`, `TransferController`
- **Modified entities**: `ObjectEntity` (retention field structure extended with `eDepotReferentie`, `transferErrors`, `transferDate`), `Schema` (archive config extended with e-Depot settings)
- **New API routes**: Transfer management endpoints and e-Depot settings endpoint
- **Dependencies**: PHP `ext-dom` for XML generation (already typical in Nextcloud environments), `ext-zip` for SIP packaging
- **Affected apps**: opencatalogi (may need to handle transferred/read-only objects in listings), softwarecatalog (no direct impact)
- **File system**: SIP packages generated as temporary ZIP files in Nextcloud data directory before transmission
