## MODIFIED Requirements

### Requirement: The system MUST support e-Depot export (overbrenging)
Objects with `archiefnominatie` set to `bewaren` that have reached their `archiefactiedatum` MUST be exportable to external e-Depot systems in a standardized SIP (Submission Information Package) format, conforming to the OAIS reference model (ISO 14721) and MDTO metadata standard. Transfer MUST follow a transfer list workflow with archivist approval, and MUST support multiple transport protocols.

#### Scenario: Export objects to e-Depot as SIP package
- **GIVEN** 5 objects with `archiefnominatie` `bewaren` and `archiefactiedatum` reached
- **WHEN** the archivist initiates e-Depot transfer via an approved transfer list
- **THEN** the system MUST generate a SIP (Submission Information Package) containing:
  - Object metadata in MDTO XML format per object (one `mdto.xml` per object directory)
  - Associated documents from Nextcloud Files (original format plus PDF/A rendition if available)
  - A `mets.xml` structural metadata file describing the package hierarchy
  - A `premis.xml` preservation metadata file with fixity checksums (SHA-256)
  - A `sip-manifest.json` listing all files with checksums
- **AND** the SIP MUST be structured following the e-Depot specification of the target archive (configurable via SIP profile)
- **AND** the SIP MUST be transmittable via the configured transport protocol (SFTP, REST API, or OpenConnector source)

#### Scenario: Successful e-Depot transfer
- **GIVEN** a SIP package for 5 objects is transmitted to the e-Depot
- **WHEN** the e-Depot confirms receipt and acceptance
- **THEN** all 5 objects MUST have their `archiefstatus` updated to `overgebracht`
- **AND** each object MUST store the e-Depot reference identifier in `retention.eDepotReferentie`
- **AND** `retention.transferDate` MUST store the ISO 8601 timestamp of the transfer
- **AND** an audit trail entry MUST be created for each object with action `archival.transferred`
- **AND** the objects MUST become read-only in OpenRegister (no further modifications allowed, enforced with HTTP 409)

#### Scenario: e-Depot transfer failure (partial)
- **GIVEN** an e-Depot transfer is initiated for 5 objects
- **WHEN** the e-Depot system accepts 3 objects but rejects 2 (e.g., metadata validation errors)
- **THEN** only the 3 accepted objects MUST be marked as `overgebracht`
- **AND** the 2 rejected objects MUST remain in status `nog_te_archiveren`
- **AND** the rejection reasons MUST be stored per object in `retention.transferErrors[]` with timestamp and error message
- **AND** an `INotification` MUST be sent to the archivist with details of the partial failure

#### Scenario: Configure e-Depot endpoint
- **GIVEN** an admin configuring the e-Depot connection
- **WHEN** they set the e-Depot endpoint via `PUT /api/settings/edepot` with:
  - `endpointUrl`: the e-Depot API or SFTP address
  - `authenticationType`: `api_key`, `certificate`, or `oauth2`
  - `targetArchive`: identifier of the receiving archive (e.g., `regionaal-archief-leiden`)
  - `sipProfile`: the SIP profile to use (e.g., `nationaal-archief-v2`, `tresoar-v1`)
  - `transport`: the transport protocol (`sftp`, `rest_api`, or `openconnector`)
- **THEN** the configuration MUST be validated by performing a test connection
- **AND** the configuration MUST be stored securely in `IAppConfig` with sensitive values encrypted

#### Scenario: e-Depot transfer via OpenConnector
- **GIVEN** an OpenConnector source is configured for the e-Depot endpoint with transport `openconnector`
- **WHEN** the archivist initiates e-Depot transfer
- **THEN** the system MUST use the OpenConnector synchronization mechanism to transmit the SIP
- **AND** the transfer status MUST be tracked via OpenConnector's call log

#### Scenario: Automatic transfer list generation
- **GIVEN** the `TransferCheckJob` background job is enabled
- **WHEN** the job runs and finds objects with `archiefnominatie` = `bewaren` and `archiefactiedatum` <= today and `archiefstatus` = `nog_te_archiveren`
- **THEN** a transfer list MUST be created with status `in_review`
- **AND** objects already on an existing transfer list (status `in_review` or `approved`) MUST be excluded
- **AND** an `INotification` MUST be sent to users with the archivist role
