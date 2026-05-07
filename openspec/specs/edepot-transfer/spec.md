# e-Depot Transfer

## Purpose
Define how OpenRegister exports objects to a Dutch e-Depot for permanent archival storage. The capability MUST package selected objects as OAIS-compliant SIP archives with MDTO-compliant XML metadata, deliver them via SFTP, REST, or OpenConnector, track per-object transfer status, and enforce read-only state on transferred objects so the e-Depot remains the authoritative copy. This satisfies Archiefwet 1995 obligations for transferring permanent records to the receiving archival institution.

## Requirements

### Requirement: The system MUST generate MDTO-compliant XML metadata per object
Each object selected for e-Depot transfer MUST have its metadata exported as valid XML conforming to the MDTO (Metagegevens Duurzaam Toegankelijke Overheidsinformatie) schema version 1.0 or later. The XML MUST include all mandatory MDTO elements and use the correct namespace.

#### Scenario: Generate MDTO XML for a single object
- **WHEN** object `zaak-123` with complete archival metadata is selected for MDTO export
- **THEN** the system MUST produce an XML document with root element `mdto:informatieobject` in namespace `https://www.nationaalarchief.nl/mdto`
- **AND** the XML MUST include mandatory elements: `identificatie` (object UUID + register source), `naam` (object title or schema+UUID), `waardering` (mapped from `archiefnominatie`), `bewaartermijn` (ISO 8601 duration from retention field), `informatiecategorie` (mapped from selectielijst `classificatie`)
- **AND** the XML MUST include `archiefvormer` (the organisation identifier from app settings)
- **AND** the XML MUST validate against the MDTO XSD schema

#### Scenario: MDTO XML includes file references
- **WHEN** object `zaak-123` has 2 associated files (original.docx and rendition.pdf)
- **THEN** the MDTO XML MUST include `bestand` elements for each file with `naam`, `omvang` (file size in bytes), `bestandsformaat` (PRONOM identifier or MIME type), and `checksum` (SHA-256)

#### Scenario: MDTO XML handles missing optional fields gracefully
- **WHEN** an object lacks optional MDTO fields (e.g., no `toelichting`, no `classificatie`)
- **THEN** the XML MUST omit those elements rather than including empty elements
- **AND** the XML MUST still validate against the MDTO XSD schema
- **AND** required fields that are missing MUST cause the export to fail with a descriptive error logged to the transfer list

### Requirement: The system MUST assemble SIP packages for e-Depot transfer
Objects approved for transfer MUST be packaged into a SIP (Submission Information Package) conforming to the OAIS reference model (ISO 14721). Each SIP MUST be a self-contained ZIP archive containing all metadata and content files needed for archival ingest.

#### Scenario: Assemble a SIP package for 5 objects
- **WHEN** a transfer list with 5 approved objects is ready for packaging
- **THEN** the system MUST generate a ZIP archive named `sip-{transferId}.zip` containing:
  - A `mets.xml` file describing the structural map of the package (file groups, div structure per object)
  - A `premis.xml` file with preservation events (creation, packaging) and SHA-256 fixity for every content file
  - A `sip-manifest.json` listing all files in the package with their relative paths, SHA-256 checksums, and sizes
  - One directory per object under `objects/{uuid}/` containing `mdto.xml`, `metadata.json` (object data snapshot), and a `content/` directory with associated files
- **AND** the total package MUST be integrity-verifiable by recomputing checksums from the manifest

#### Scenario: SIP package includes PDF/A renditions when available
- **WHEN** object `doc-001` has both an original file (report.docx) and a PDF/A rendition (report.pdf)
- **THEN** the SIP MUST include both files in `objects/{uuid}/content/`
- **AND** the `mets.xml` MUST distinguish between the original file group and the rendition file group

#### Scenario: SIP package handles objects without files
- **WHEN** object `record-001` has metadata but no associated files
- **THEN** the SIP MUST still include the object directory with `mdto.xml` and `metadata.json`
- **AND** the `content/` directory MUST be omitted
- **AND** the `mets.xml` MUST reflect that this object has no content files

#### Scenario: SIP package size limit triggers splitting
- **WHEN** a transfer list contains objects whose combined file size exceeds the configured maximum package size (default: 2 GB)
- **THEN** the system MUST split the transfer into multiple SIP packages
- **AND** each package MUST be independently valid with its own `mets.xml`, `premis.xml`, and `sip-manifest.json`
- **AND** a `sip-sequence.json` MUST be included in each package indicating its position (e.g., 1 of 3) and the parent transfer list UUID

### Requirement: The system MUST support transfer list management
Transfer lists MUST track which objects are pending, approved, or completed for e-Depot transfer. Transfer lists follow the same review-approve pattern as destruction lists.

#### Scenario: Automatically generate a transfer list via background job
- **WHEN** the `TransferCheckJob` runs and finds 8 objects with `archiefnominatie` = `bewaren`, `archiefactiedatum` <= today, and `archiefstatus` = `nog_te_archiveren`
- **THEN** the system MUST create a transfer list object containing references to all 8 objects
- **AND** the transfer list MUST have status `in_review`
- **AND** an `INotification` MUST be sent to users with the archivist role
- **AND** objects already on an existing transfer list with status `in_review` or `approved` MUST be excluded

#### Scenario: Archivist approves a transfer list
- **WHEN** an archivist with the `archivaris` role approves a transfer list containing 8 objects
- **THEN** the transfer list status MUST change to `approved`
- **AND** the system MUST queue a `TransferExecutionJob` to build the SIP and transmit it
- **AND** an audit trail entry MUST be created with action `archival.transfer_approved`

#### Scenario: Archivist partially excludes objects from transfer list
- **WHEN** the archivist removes 2 objects from a transfer list of 8 and approves the remaining 6
- **THEN** only the 6 approved objects MUST be included in the SIP package
- **AND** the 2 excluded objects MUST have their exclusion reason recorded
- **AND** the excluded objects MUST remain eligible for future transfer lists

#### Scenario: Reject a transfer list
- **WHEN** the archivist rejects an entire transfer list
- **THEN** no SIP package MUST be generated
- **AND** the transfer list status MUST change to `rejected`
- **AND** the archivist MUST provide a reason for rejection
- **AND** all objects on the list MUST remain eligible for future transfer lists

### Requirement: The system MUST support configurable e-Depot endpoint settings
Administrators MUST be able to configure the target e-Depot system, transport protocol, authentication, and SIP profile through the admin settings API.

#### Scenario: Configure e-Depot endpoint via API
- **WHEN** an admin sends `PUT /api/settings/edepot` with endpoint URL, authentication type (`api_key`, `certificate`, or `oauth2`), target archive identifier, and SIP profile name
- **THEN** the system MUST validate the configuration by performing a test connection
- **AND** the configuration MUST be stored in `IAppConfig` with sensitive values encrypted
- **AND** the response MUST confirm the connection test result

#### Scenario: Test e-Depot connection
- **WHEN** an admin sends `POST /api/settings/edepot/test` with the current or proposed configuration
- **THEN** the system MUST attempt to connect to the endpoint using the specified protocol
- **AND** the response MUST report success or failure with a descriptive error message

#### Scenario: Configure SIP profile
- **WHEN** an admin sets `sipProfile` to `nationaal-archief-v2`
- **THEN** the SIP package builder MUST use the corresponding profile's directory structure, naming conventions, and metadata requirements
- **AND** unknown profile names MUST be rejected with a validation error listing available profiles

### Requirement: The system MUST support multiple transport protocols for SIP delivery
SIP packages MUST be transmittable to e-Depot systems via SFTP, REST API, or OpenConnector source integration. The transport protocol MUST be configurable per e-Depot endpoint.

#### Scenario: Transfer SIP via SFTP
- **WHEN** the e-Depot is configured with transport `sftp` and the SIP package is ready
- **THEN** the system MUST upload the ZIP file to the configured SFTP path using `phpseclib`
- **AND** the system MUST verify the upload by checking remote file size matches local file size
- **AND** on success the transfer status MUST be updated to `completed`

#### Scenario: Transfer SIP via REST API
- **WHEN** the e-Depot is configured with transport `rest_api`
- **THEN** the system MUST POST the SIP as a multipart upload to the configured endpoint URL
- **AND** the system MUST include authentication headers as configured (API key or OAuth2 bearer token)
- **AND** the system MUST parse the response to determine acceptance or rejection per object

#### Scenario: Transfer SIP via OpenConnector
- **WHEN** the e-Depot is configured with transport `openconnector` and a source ID is specified
- **THEN** the system MUST create a synchronization job in OpenConnector with the SIP file as payload
- **AND** the transfer status MUST be tracked via OpenConnector's call log

#### Scenario: Transport failure with retry
- **WHEN** a SIP transfer fails due to a transient error (network timeout, connection refused)
- **THEN** the system MUST retry up to 3 times with exponential backoff (30s, 120s, 480s)
- **AND** if all retries fail, the transfer list status MUST change to `failed`
- **AND** the failure details MUST be stored in the transfer list and per-object `retention.transferErrors[]`
- **AND** an `INotification` MUST be sent to the archivist

### Requirement: The system MUST track transfer status per object
Each object involved in an e-Depot transfer MUST have its transfer status tracked in the `retention` field, enabling status queries and preventing duplicate transfers.

#### Scenario: Successful transfer updates object status
- **WHEN** the e-Depot confirms acceptance of object `zaak-123`
- **THEN** `retention.archiefstatus` MUST be set to `overgebracht`
- **AND** `retention.eDepotReferentie` MUST store the e-Depot's reference identifier for this object
- **AND** `retention.transferDate` MUST store the ISO 8601 timestamp of the transfer
- **AND** an audit trail entry MUST be created with action `archival.transferred`

#### Scenario: Partial transfer failure tracks per-object errors
- **WHEN** a SIP containing 5 objects is sent and the e-Depot accepts 3 but rejects 2
- **THEN** the 3 accepted objects MUST be marked as `overgebracht` with their e-Depot references
- **AND** the 2 rejected objects MUST remain in status `nog_te_archiveren`
- **AND** each rejected object MUST have the rejection reason stored in `retention.transferErrors[]` with timestamp and error message
- **AND** an `INotification` MUST be sent to the archivist detailing the partial failure

#### Scenario: Query objects by transfer status
- **WHEN** a user queries `GET /api/objects/{register}/{schema}?retention.archiefstatus=overgebracht`
- **THEN** the system MUST return only objects that have been successfully transferred
- **AND** the response MUST include the `eDepotReferentie` in the retention field

### Requirement: Transferred objects MUST be read-only
Objects with `archiefstatus` set to `overgebracht` MUST NOT be modifiable. The authoritative copy now resides in the e-Depot.

#### Scenario: Reject update to transferred object
- **WHEN** a user attempts to update object `zaak-123` which has `archiefstatus` = `overgebracht`
- **THEN** the system MUST reject the request with HTTP 409 Conflict
- **AND** the response body MUST include error code `OBJECT_TRANSFERRED` and a message indicating the object has been transferred to the e-Depot

#### Scenario: Reject deletion of transferred object
- **WHEN** a user attempts to delete object `zaak-123` which has `archiefstatus` = `overgebracht`
- **THEN** the system MUST reject the request with HTTP 409 Conflict
- **AND** the response MUST indicate that transferred objects cannot be deleted from the source system

#### Scenario: Read access to transferred objects is preserved
- **WHEN** a user requests `GET /api/objects/{register}/{schema}/{id}` for a transferred object
- **THEN** the system MUST return the object with all its metadata
- **AND** the response MUST include `retention.archiefstatus` = `overgebracht` and `retention.eDepotReferentie`

### Requirement: The system MUST log all transfer actions in the audit trail
Every transfer lifecycle event MUST produce an immutable audit trail entry for legal accountability and traceability.

#### Scenario: Audit trail for transfer initiation
- **WHEN** an archivist approves a transfer list and the transfer is initiated
- **THEN** an audit trail entry MUST be created with action `archival.transfer_initiated` containing the transfer list UUID, archivist user ID, number of objects, and target e-Depot identifier

#### Scenario: Audit trail for successful transfer
- **WHEN** an object is successfully transferred to the e-Depot
- **THEN** an audit trail entry MUST be created with action `archival.transferred` containing the object UUID, transfer list UUID, e-Depot reference, and timestamp

#### Scenario: Audit trail for transfer failure
- **WHEN** a transfer fails (partially or completely)
- **THEN** an audit trail entry MUST be created with action `archival.transfer_failed` containing the transfer list UUID, error details, number of failed objects, and transport protocol used
