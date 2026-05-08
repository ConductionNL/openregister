## 1. MDTO XML Generation

- [x] 1.1 Create `MdtoXmlGenerator` class in `lib/Service/Edepot/` that generates MDTO-compliant XML using `DOMDocument` with `mdto:` namespace prefix and the `https://www.nationaalarchief.nl/mdto` namespace URI
- [x] 1.2 Implement mandatory MDTO element mapping: `identificatie` (UUID + register source), `naam`, `waardering` (from `archiefnominatie`), `bewaartermijn`, `informatiecategorie` (from `classificatie`), `archiefvormer` (from app settings)
- [x] 1.3 Implement `bestand` element generation for associated files including `naam`, `omvang`, `bestandsformaat`, and `checksum` (SHA-256)
- [x] 1.4 Add validation: skip optional empty elements, fail with descriptive error on missing required fields
- [x] 1.5 Write unit tests for `MdtoXmlGenerator` covering: complete object, object with files, object with missing optional fields, object with missing required fields

## 2. SIP Package Builder

- [x] 2.1 Create `SipPackageBuilder` class in `lib/Service/Edepot/` that assembles a ZIP archive with per-object directories (`objects/{uuid}/mdto.xml`, `metadata.json`, `content/`)
- [x] 2.2 Implement `mets.xml` generation with structural map: file groups (original, rendition) and div structure per object
- [x] 2.3 Implement `premis.xml` generation with preservation events (creation, packaging) and SHA-256 fixity per content file
- [x] 2.4 Implement `sip-manifest.json` generation listing all files with relative paths, SHA-256 checksums, and sizes
- [x] 2.5 Implement package splitting when combined file size exceeds configurable max (default 2 GB), including `sip-sequence.json` in each split package
- [x] 2.6 Handle edge cases: objects without files (omit `content/` dir), objects with PDF/A renditions (separate file groups)
- [x] 2.7 Write unit tests for `SipPackageBuilder` covering: single object, multiple objects, objects with/without files, package splitting

## 3. Transfer List Management

- [x] 3.1 Create transfer list JSON Schema definition (properties: status, objectReferences, approvalMetadata, exclusions, transferResult) and register it as a schema in the archival management register
- [x] 3.2 Create `TransferListService` in `lib/Service/Edepot/` with methods: `createTransferList()`, `approveTransferList()`, `rejectTransferList()`, `excludeObjects()`
- [x] 3.3 Implement `TransferCheckJob` extending `OCP\BackgroundJob\TimedJob` — scans for objects with `archiefnominatie=bewaren`, `archiefactiedatum<=today`, `archiefstatus=nog_te_archiveren`, excludes objects already on active transfer lists
- [x] 3.4 Send `INotification` to archivist-role users when a new transfer list is generated
- [x] 3.5 Write unit tests for `TransferListService` and `TransferCheckJob`

## 4. Transport Layer

- [x] 4.1 Create `TransportInterface` in `lib/Service/Edepot/Transport/` with `send()`, `testConnection()`, and `getName()` methods
- [x] 4.2 Implement `SftpTransport` using `phpseclib` — upload ZIP, verify remote file size, handle connection errors
- [x] 4.3 Implement `RestApiTransport` — multipart POST with configurable auth headers (API key, OAuth2 bearer), parse response for per-object acceptance/rejection
- [x] 4.4 Implement `OpenConnectorTransport` — create synchronization job via OpenConnector with SIP as payload, track via call log
- [x] 4.5 Implement retry logic: 3 retries with exponential backoff (30s, 120s, 480s) for transient failures
- [x] 4.6 Write unit tests for each transport implementation (mock external services)

## 5. Transfer Execution and Status Tracking

- [x] 5.1 Create `EdepotTransferService` in `lib/Service/Edepot/` that orchestrates: build SIP via `SipPackageBuilder`, send via `TransportInterface`, update object statuses
- [x] 5.2 Create `TransferExecutionJob` extending `OCP\BackgroundJob\QueuedJob` — picks up approved transfer lists and executes the full transfer pipeline
- [x] 5.3 Implement per-object status tracking: set `retention.archiefstatus=overgebracht`, `retention.eDepotReferentie`, `retention.transferDate` on success; `retention.transferErrors[]` on failure
- [x] 5.4 Implement partial failure handling: mark accepted objects as transferred, keep rejected objects as `nog_te_archiveren`, update transfer list with mixed result
- [x] 5.5 Send `INotification` on transfer completion (success or partial failure)

## 6. Read-Only Enforcement

- [x] 6.1 Add check in `ObjectService` save pipeline: if `retention.archiefstatus === 'overgebracht'`, reject update with HTTP 409 and error code `OBJECT_TRANSFERRED`
- [x] 6.2 Add check in `ObjectService` delete pipeline: if `retention.archiefstatus === 'overgebracht'`, reject deletion with HTTP 409
- [x] 6.3 Ensure read access (GET) still works for transferred objects with full metadata
- [x] 6.4 Write unit tests for read-only enforcement (update rejected, delete rejected, read allowed)

## 7. API Endpoints and Settings

- [x] 7.1 Create `EdepotSettingsController` with `PUT /api/settings/edepot` (configure endpoint, auth, transport, SIP profile) and `POST /api/settings/edepot/test` (test connection)
- [x] 7.2 Create `TransferController` with `POST /api/transfers` (initiate transfer from approved list), `GET /api/transfers/{id}` (status), `GET /api/transfers` (list all)
- [x] 7.3 Register routes in `appinfo/routes.php` with admin-only access for settings, archivist role for transfers
- [x] 7.4 Store e-Depot configuration in `IAppConfig` with sensitive values (credentials, keys) encrypted
- [x] 7.5 Validate SIP profile names against available profiles, return error for unknown profiles

## 8. Audit Trail Integration

- [x] 8.1 Add audit trail entries for `archival.transfer_initiated` (transfer list UUID, archivist, object count, target e-Depot)
- [x] 8.2 Add audit trail entries for `archival.transferred` (per object: UUID, transfer list, e-Depot reference, timestamp)
- [x] 8.3 Add audit trail entries for `archival.transfer_failed` (transfer list UUID, error details, failed count, transport protocol)
- [x] 8.4 Add audit trail entry for `archival.transfer_approved` when archivist approves transfer list

## 9. Registration and Wiring

- [x] 9.1 Register `TransferCheckJob` in `Application.php` boot (no-op if no e-Depot configured)
- [x] 9.2 Register DI bindings for `TransportInterface` implementations and `EdepotTransferService` in the container
- [x] 9.3 Add `phpseclib/phpseclib` to `composer.json` for SFTP transport support

## 10. Integration Testing

- [x] 10.1 Test full transfer pipeline end-to-end: create objects with `bewaren` nominatie, run `TransferCheckJob`, approve transfer list, verify SIP package contents
- [x] 10.2 Test partial failure scenario: mock transport returning mixed accept/reject, verify per-object status tracking
- [x] 10.3 Test read-only enforcement: verify 409 responses for update/delete on transferred objects
- [x] 10.4 Test with opencatalogi and softwarecatalog to verify no regressions on listing/search of transferred objects
