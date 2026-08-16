---
status: done
---

# audit-trail-immutable Specification

---
status: implemented
---

## Purpose

@e2e exclude backend audit trail — covered by PHPUnit
Implement an immutable audit trail with cryptographic hash chaining for all register operations. Every create, read (of sensitive data), update, and delete MUST be recorded in a tamper-evident log with minimum 10-year retention. The audit trail MUST be independently verifiable and exportable for compliance auditing.

**Tender demand**: 56% of analyzed government tenders require immutable audit trail capabilities.
## Requirements
### Requirement: Every mutation MUST produce an immutable audit trail entry
All create, update, and delete operations on register objects MUST generate an audit trail entry that cannot be modified or deleted.

#### Scenario: Audit entry for object creation
- GIVEN a user `behandelaar-1` creates an object in schema `meldingen`
- THEN an audit trail entry MUST be created with:
  - `id`: auto-incrementing sequence number
  - `timestamp`: server-side UTC timestamp (not client-provided)
  - `user`: `behandelaar-1`
  - `action`: `create`
  - `objectUuid`: the UUID of the created object
  - `schemaUuid`: the UUID of the schema
  - `registerUuid`: the UUID of the register
  - `data`: full snapshot of the created object
  - `hash`: SHA-256 hash of (previous_hash + entry_data)

#### Scenario: Audit entry for object update
- GIVEN object `melding-1` with title `Overlast` is updated to title `Geluidsoverlast`
- THEN the audit entry MUST include:
  - `action`: `update`
  - `changed`: `{"title": {"old": "Overlast", "new": "Geluidsoverlast"}}`
  - `hash`: chained from the previous audit entry's hash

#### Scenario: Audit entry for object deletion
- GIVEN object `melding-1` is deleted
- THEN the audit entry MUST include:
  - `action`: `delete`
  - `data`: full snapshot of the object before deletion

### Requirement: The AuditTrail entity MUST include hash and previousHash fields
The `AuditTrail` entity MUST be extended with `hash` and `previousHash` string fields for cryptographic chain integrity.

#### Scenario: New audit trail entry includes hash fields in JSON
- **WHEN** an audit trail entry with hash chaining is serialized to JSON
- **THEN** the JSON output MUST include `hash` and `previousHash` string fields
- **AND** both fields MUST be 64-character hexadecimal strings (SHA-256 output)

#### Scenario: Legacy entry without hash fields
- **WHEN** an audit trail entry created before hash chaining is serialized to JSON
- **THEN** the JSON output MUST include `hash` and `previousHash` as null values

### Requirement: The audit trail MUST use cryptographic hash chaining
Each audit trail entry MUST include a hash that chains to the previous entry, making any tampering detectable.

#### Scenario: Hash chain integrity
- GIVEN 100 consecutive audit trail entries
- WHEN an auditor verifies the hash chain
- THEN each entry's hash MUST equal SHA-256(previous_entry_hash + current_entry_json)
- AND the first entry's hash MUST use a known genesis hash

#### Scenario: Detect tampered entry
- GIVEN an audit trail where entry #50 has been modified after creation
- WHEN the hash chain is verified
- THEN verification MUST fail at entry #50
- AND the verification report MUST identify the exact entry where the chain broke

### Requirement: Audit trail entries MUST NOT be deletable or modifiable
No user, including administrators, MUST be able to modify or delete audit trail entries through the application.

#### Scenario: Reject audit trail deletion via API
- GIVEN an admin user attempts to DELETE `/api/audit-trails/{id}`
- THEN the system MUST return HTTP 405 Method Not Allowed
- AND the response MUST include `{"error": "Audit trail entries cannot be deleted"}`
- AND the audit entry MUST remain unchanged

#### Scenario: Reject audit trail modification via PUT
- GIVEN an admin attempts to PUT `/api/audit-trails/{id}` with modified data
- THEN the system MUST return HTTP 405 Method Not Allowed
- AND the response MUST include `{"error": "Audit trail entries cannot be modified"}`

#### Scenario: Reject audit trail modification via PATCH
- GIVEN an admin attempts to PATCH `/api/audit-trails/{id}` with modified data
- THEN the system MUST return HTTP 405 Method Not Allowed
- AND the response MUST include `{"error": "Audit trail entries cannot be modified"}`

### Requirement: The audit trail MUST support minimum 10-year retention
Audit trail entries MUST be retained for at least 10 years, with configurable retention periods per register.

#### Scenario: Configure retention period
- GIVEN a register `archief` requiring 20-year audit retention
- WHEN the admin sets retention to 20 years
- THEN audit entries for this register MUST be retained for 20 years
- AND entries MUST NOT be purged before the configured retention period

#### Scenario: Archive old entries for performance
- GIVEN 5 million audit trail entries spanning 8 years
- WHEN entries older than 2 years are archived
- THEN archived entries MUST remain accessible via a separate archive query endpoint
- AND the hash chain MUST remain verifiable across the archive boundary

### Requirement: The audit trail MUST be exportable for compliance audits
The audit trail MUST support export in formats suitable for external auditors.

#### Scenario: Export audit trail for date range
- GIVEN an auditor requests all audit entries for register `zaken` from 2025-01-01 to 2025-12-31
- WHEN the export is generated
- THEN the export MUST include all entries in the date range
- AND the export MUST include the hash chain for independent verification
- AND the export format MUST be JSON or CSV with hash verification instructions

### Requirement: Sensitive data reads MUST be audited
Read operations on schemas marked as containing sensitive data MUST also produce audit trail entries.

#### Scenario: Log read of personal data
- GIVEN schema `inwoners` is marked as sensitive
- WHEN user `medewerker-1` reads object `inwoner-123`
- THEN an audit entry MUST be created with action `read`
- AND the entry MUST NOT include the full object data (only the object UUID)

### Requirement: Audit-Trail Access, Export, and Administrative Deletion Surface
The service MUST expose audit-trail retrieval scoped to a single object with register/schema membership validation that still succeeds when the register or schema has been soft-deleted, MUST count and list entries with the same filters/pagination, MUST export entries in CSV, JSON, XML, and TXT formats, and MUST support single and filtered bulk deletion as an administrative retention operation.

`LogService::getLogs()` and `LogService::count()` MUST resolve the object across all sources including soft-deleted objects, MUST validate the object belongs to the requested register/schema by comparing stored IDs while tolerating a soft-deleted (unresolvable) register or schema, and MUST restrict results to the object's UUID. `LogService::exportLogs()` MUST emit `{ content, filename, contentType }` for each of the `csv`, `json`, `xml`, and `txt` formats and MUST reject an unsupported format with `InvalidArgumentException`. `LogService::deleteLog()` MUST delete one entry by id (or surface a not-found error), and `LogService::deleteLogs()` MUST delete a filter/id-selected set and MUST report `{ deleted, failed, total }`. Deletion is an administrative retention action and does not relax the per-entry immutability guarantee enforced elsewhere in this capability.

#### Scenario: Audit trail readable after register soft-delete
- **GIVEN** an object whose register has been soft-deleted
- **WHEN** the object-scoped log retrieval runs
- **THEN** the object's audit entries MUST still be returned, scoped by the object UUID

#### Scenario: Export rejects an unsupported format
- **GIVEN** an export request for an unknown format
- **WHEN** the export runs
- **THEN** an `InvalidArgumentException` MUST be raised

#### Scenario: Bulk deletion reports per-item outcome
- **GIVEN** a filter selecting several audit entries
- **WHEN** the bulk deletion runs
- **THEN** the result MUST report the deleted, failed, and total counts

### Requirement: File-attachment audit events MUST persist via the same hash-chained AuditTrail entity (REQ-IMMU-001)

File-attachment lifecycle events (bulk downloads as ZIP archives, and namespaced single-file actions such as `file.renamed`, `file.locked`, `file.version_restored`) MUST produce `AuditTrail` rows persisted via `AuditTrailMapper::insert`, so they inherit the same hash-chain integrity, immutability, and verification guarantees as object mutations. File-event audit rows MUST be keyed to the parent `ObjectEntity` (object id, objectUuid, register, schema) so they surface in the same audit timeline as object updates. Audit-logging failures MUST NOT break the underlying file operation — the audit write is best-effort and any exception MUST be swallowed and logged at warning level.

#### Scenario: Bulk download produces a single audit row for the ZIP

- **GIVEN** an authenticated user downloads files `[42, 43, 44]` from object `obj-uuid-1` as a single ZIP archive named `bundle.zip`
- **WHEN** `FileAuditHandler::logBulkDownload($object, [42,43,44], ['a.pdf','b.pdf','c.pdf'], 'bundle.zip', 3200)` is called
- **THEN** exactly ONE `AuditTrail` row MUST be persisted (not three)
- **AND** the row's `action` MUST equal `file.bulk_downloaded`
- **AND** the row's `changed` payload MUST include `fileIds: [42,43,44]`, `fileNames: ['a.pdf','b.pdf','c.pdf']`, `fileCount: 3`, `zipName: 'bundle.zip'`, `totalBytes: 3200`
- **AND** the row MUST inherit the active hash-chain via `AuditTrailMapper::insert`

#### Scenario: Anonymous bulk download is attributed by IP

- **GIVEN** an anonymous (no session) bulk-download request from remote address `203.0.113.7`
- **WHEN** `FileAuditHandler::logBulkDownload` is called with no active user session
- **THEN** the persisted row's `user` MUST equal `Anonymous`
- **AND** the row's `userName` MUST contain the remote address (e.g. `Anonymous (203.0.113.7)`)

#### Scenario: Namespaced file action produces an audit row

- **GIVEN** a file `42` attached to object `obj-uuid-1` is renamed
- **WHEN** `FileAuditHandler::logFileAction($object, 42, 'file.renamed', ['newName' => 'report-final.pdf'])` is called
- **THEN** an `AuditTrail` row MUST be persisted with `action: 'file.renamed'`
- **AND** the row's `changed` payload MUST include `fileId: 42` and `data: {newName: 'report-final.pdf'}`
- **AND** the row MUST be keyed to the parent object (object id, objectUuid, register, schema columns set from the `ObjectEntity`)

#### Scenario: Audit-write failure does not break the file operation

- **GIVEN** the database is unavailable mid-request
- **WHEN** `FileAuditHandler::logFileAction` or `logBulkDownload` is called
- **THEN** the method MUST return `null` (not throw)
- **AND** the failure MUST be logged via `LoggerInterface::warning`

#### Notes

- File-action audit rows currently set `expires` to `+30 days`. This is shorter than the spec's 10-year retention REQ; either the retention REQ does not apply uniformly to file events, or the 30-day default is incorrect. Flagged as a follow-up clarification, not a retrofit blocker.
- The bulk-download `size` field is hardcoded to `14` (matching the default in `AuditTrailMapper::createAuditTrail`). This is observed behavior, not a calculated payload size.

### Requirement: Admin-only `DELETE /api/audit-trails/clear-all` MUST wipe the entire audit table (REQ-IMMU-002 — drift from immutability REQ)

The system exposes an admin-only operational escape hatch at `DELETE /api/audit-trails/clear-all` (route `auditTrail#clearAll`) that calls `AuditTrailMapper::clearAllLogs()` and deletes ALL rows from the `openregister_audit_trails` table — not just expired ones. The endpoint MUST be gated at two layers: (1) framework-level (no `@NoAdminRequired`, so NC's middleware blocks non-admins) and (2) body-level (`AuditTrailController::requireAdmin()` returns 401 for unauthenticated and 403 for non-admin callers as defense-in-depth). The admin UI at `src/modals/logs/ClearAuditTrails.vue` is the documented consumer of this endpoint (POSTing `DELETE /api/audit-trails` with active filters; the broader `clearAll` variant has no filter set).

**DRIFT**: This requirement contradicts the existing REQ "Audit trail entries MUST NOT be deletable or modifiable". An authenticated admin can destroy the chain of trust required for AVG/GDPR Art 30 reviews. Captured here as observed behavior; resolving the contradiction is a future spec change, not a retrofit.

#### Scenario: Admin clears all audit trails

- **GIVEN** an authenticated admin user
- **WHEN** the admin issues `DELETE /api/audit-trails/clear-all`
- **THEN** `AuditTrailMapper::clearAllLogs()` MUST execute `DELETE FROM openregister_audit_trails` (no WHERE clause)
- **AND** the response MUST be HTTP 200 with `{success: true, message: 'All audit trails cleared successfully'}`
- **AND** the response body's `deleted` field MUST be set (true value indicates at least one row was deleted)

#### Scenario: Non-admin caller is rejected at the body-level gate

- **GIVEN** an authenticated non-admin user `user-1`
- **WHEN** the user issues `DELETE /api/audit-trails/clear-all`
- **THEN** the response MUST be HTTP 403 with `{error: 'Forbidden: this audit-trail operation is admin-only'}`
- **AND** no rows MUST be deleted

#### Scenario: Unauthenticated caller is rejected at the body-level gate

- **GIVEN** no active user session
- **WHEN** the request reaches `AuditTrailController::clearAll`
- **THEN** the response MUST be HTTP 401 with `{error: 'Authentication required'}`

#### Scenario: Admin clears filtered audit trails via the UI

- **GIVEN** the admin opens the `ClearAuditTrails` dialog with active filters in `auditTrailStore.filters`
- **WHEN** the admin confirms "Clear Entries"
- **THEN** the UI MUST issue `DELETE /index.php/apps/openregister/api/audit-trails?<filters as query params>`
- **AND** on success the UI MUST display `{count} audit trails cleared successfully` and auto-close the dialog after 3 seconds
- **AND** on failure the UI MUST display the error message and keep the dialog open

#### Notes

- The `ClearAuditTrails.vue` dialog defaults to deleting ALL entries when no filters are active and surfaces a warning note-card to that effect; the UI flow tries to dissuade but does not block.
- The companion routes `auditTrail#destroy` (DELETE `/api/audit-trails/{id}`) and `auditTrail#destroyMultiple` (DELETE `/api/audit-trails`) DO return HTTP 405 per the existing immutability REQ, which makes the `clear-all` carve-out inconsistent. Flagged as part of the drift in D-1 of the proposal.

## Current Implementation Status
- **Implemented:**
  - `AuditTrail` entity (`lib/Db/AuditTrail.php`) with fields: uuid, schema, register, object, objectUuid, registerUuid, schemaUuid, action, changed, user, userName, created, organisation, session, request, ipAddress, size, hash, previousHash
  - `AuditTrailMapper` (`lib/Db/AuditTrailMapper.php`) with `createAuditTrail()` method recording create/update/delete actions with user context, session, IP address, and changed fields
  - `AuditHandler` (`lib/Service/Object/AuditHandler.php`) orchestrating audit trail creation during object operations
  - Referential integrity actions logged with specific action types: `referential_integrity.cascade_delete`, `referential_integrity.set_null`, `referential_integrity.set_default`, `referential_integrity.restrict_blocked` (in `ReferentialIntegrityService`)
  - `RevertHandler` (`lib/Service/Object/RevertHandler.php`) uses audit trail for object reversion
  - AuditTrail controller for listing/viewing entries
  - Cryptographic hash chaining: `AuditHashService` computes SHA-256 hashes, `AuditTrailMapper.insert()` chains hashes automatically
  - Immutability enforcement: PUT/DELETE on audit trail API endpoints return HTTP 405
  - Hash chain verification endpoint: `GET /api/audit-trails/verify`
  - Export functionality: `GET /api/audit-trails/export` (JSON/CSV)
- **NOT implemented:**
  - 10-year retention configuration (no retention period settings per register)
  - Archive mechanism for old entries (no partitioning or separate archive table)
  - Sensitive data read auditing (no `read` action logging; only mutations are recorded)
- **Partial:**
  - The existing AuditTrail records most of the required metadata including hash chaining and immutability guarantees

## Standards & References
- **GDPR Article 30** — Processing records requirement
- **NEN 2082** — Records management (audit trail requirements)
- **Archiefwet 1995** — Dutch archival law (long-term retention)
- **BIO (Baseline Informatiebeveiliging Overheid)** — Government information security baseline (logging requirements)
- **RFC 6962** — Certificate Transparency (hash chain model reference)
- **W3C PROV-O** — Provenance ontology (for audit trail semantics)
- **Common Criteria (ISO 15408)** — Security audit logging requirements

## Nextcloud Integration Analysis

- **Status**: Implemented in OpenRegister
- **Existing Implementation**: `AuditTrail` entity with comprehensive fields including hash and previousHash for chain integrity. `AuditTrailMapper` with `createAuditTrail()` recording all mutations and automatic hash chain computation on insert. `AuditHashService` for SHA-256 hash computation and chain verification. `AuditHandler` orchestrates audit trail creation. `AuditTrailController` for listing/viewing/exporting/verification/verwerkingsregister/inzageverzoek. `RevertHandler` uses audit trail for object reversion. Referential integrity actions logged with specific action types.
- **Nextcloud Core Integration**: The `AuditTrail` entity extends NC's `Entity` base class, `AuditTrailMapper` extends `QBMapper`. Events fired via `IEventDispatcher`. Should implement `IProvider` for NC's Activity app stream to surface audit entries in the NC activity feed. Consider integrating with NC's `ILogger` for system-level audit logging.
- **Recommendation**: Mark as implemented. Consider implementing `IProvider` for the Activity app to surface audit entries in NC's activity stream. 10-year retention and sensitive data read auditing are documented as not-yet-implemented enhancements.
