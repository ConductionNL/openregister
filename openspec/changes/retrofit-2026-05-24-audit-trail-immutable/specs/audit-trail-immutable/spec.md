---
retrofit_extensions: [REQ-IMMU-001, REQ-IMMU-002]
---

## ADDED Requirements

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
