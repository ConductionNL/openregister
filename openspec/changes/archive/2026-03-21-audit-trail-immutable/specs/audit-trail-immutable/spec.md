---
status: implemented
---

# Immutable Audit Trail
## Purpose

Implement an immutable audit trail with cryptographic hash chaining for all register operations, ensuring every create, read (of sensitive data), update, and delete is recorded in a tamper-evident log that satisfies Dutch government compliance requirements (BIO2, AVG/GDPR Article 30, Archiefwet 1995, NEN-ISO 16175-1:2020). The audit trail MUST be independently verifiable, exportable for compliance auditing, and retained for configurable periods (minimum 10 years for government records). It serves as the foundational evidence layer for content versioning, object reversion, archiving/destruction workflows, and referential integrity tracking.

**Tender demand**: 56% of analyzed government tenders require immutable audit trail capabilities. An additional 77% reference archiving requirements that depend on audit trail integrity.

## Requirements

### Requirement 1: Every mutation MUST produce an immutable audit trail entry

All create, update, and delete operations on register objects MUST generate an audit trail entry that cannot be modified or deleted through the application. The entry MUST capture the full context of the operation including actor identity, session metadata, network origin, and the precise changes made.

#### Scenario: Audit entry for object creation
- **GIVEN** a user `behandelaar-1` (display name `Jan de Vries`) creates an object in schema `meldingen` within register `gemeente`
- **WHEN** `SaveObject` persists the object and `isAuditTrailsEnabled()` returns `true`
- **THEN** `AuditTrailMapper.createAuditTrail(old: null, new: $savedEntity)` MUST be called
- **AND** the resulting `AuditTrail` entry MUST contain:
  - `uuid`: a freshly generated UUID v4 (via `Symfony\Component\Uid\Uuid::v4()`)
  - `action`: `create`
  - `object`: the internal ID of the created object
  - `objectUuid`: the UUID of the created object
  - `schema`: the internal ID of the schema
  - `schemaUuid`: the UUID of the schema
  - `register`: the internal ID of the register
  - `registerUuid`: the UUID of the register
  - `changed`: full snapshot of all fields as `{"field": {"old": null, "new": value}}`
  - `user`: `behandelaar-1`
  - `userName`: `Jan de Vries`
  - `session`: the PHP session ID (via `session_id()`)
  - `request`: the Nextcloud request ID (via `\OC::$server->getRequest()->getId()`)
  - `ipAddress`: the client's remote address (via `\OC::$server->getRequest()->getRemoteAddress()`)
  - `created`: server-side UTC timestamp (via `new DateTime()`)
  - `size`: the byte size of the serialized object (minimum 14 bytes)
  - `version`: the object's version string (e.g., `1.0.0`)
  - `expires`: the expiration timestamp based on configured retention
- **AND** `$savedEntity->setLastLog($log->jsonSerialize())` MUST be called so the object carries its most recent audit reference

#### Scenario: Audit entry for object update with field-level diff
- **GIVEN** object `melding-1` with title `Overlast` and status `nieuw` at version `1.0.3`
- **WHEN** a user updates the title to `Geluidsoverlast` and the status to `in_behandeling`
- **THEN** `AuditTrailMapper.createAuditTrail(old: $oldObject, new: $updatedEntity)` MUST be called
- **AND** the `changed` field MUST contain only the modified fields: `{"title": {"old": "Overlast", "new": "Geluidsoverlast"}, "status": {"old": "nieuw", "new": "in_behandeling"}}`
- **AND** unchanged fields MUST NOT appear in the `changed` field
- **AND** removed fields MUST appear as `{"field": {"old": "value", "new": null}}`

#### Scenario: Audit entry for object deletion
- **GIVEN** object `melding-1` is deleted via `DeleteObject`
- **WHEN** `AuditTrailMapper.createAuditTrail(old: $objectEntity, new: null, action: 'delete')` is called
- **THEN** the audit entry MUST include:
  - `action`: `delete`
  - `changed`: empty array (the full object state is preserved via the old object reference)
  - `object`: the internal ID of the deleted object
  - `objectUuid`: the UUID of the deleted object
- **AND** the entry MUST NOT be deletable through any API endpoint

#### Scenario: Audit entry for cascade deletion
- **GIVEN** object `person-1` is deleted and has CASCADE referential integrity rules
- **WHEN** `ReferentialIntegrityService` cascade-deletes related objects
- **THEN** each cascade-deleted object MUST produce an audit entry with `action`: `referential_integrity.cascade_delete`
- **AND** the `changed` field MUST include: `{"deletedBecause": "cascade", "triggerObject": "person-1", "triggerSchema": "person", "property": "assignee"}`
- **AND** the `user` field MUST carry the identity of the user who initiated the original deletion

#### Scenario: Silent mode suppresses audit trail for bulk imports
- **GIVEN** a bulk import operation with `silent: true` is in progress
- **WHEN** objects are created or updated in silent mode
- **THEN** `createAuditTrail()` MUST NOT be called (as per the `if ($silent === false && $this->isAuditTrailsEnabled() === true)` guard in `SaveObject`)
- **AND** the administrator MUST be aware that silent mode creates a gap in the audit trail

### Requirement 2: The audit trail MUST use cryptographic hash chaining for tamper detection

Each audit trail entry MUST include a SHA-256 hash that chains to the previous entry's hash, forming an append-only Merkle-like chain. Any modification to a historical entry will break the chain, making tampering immediately detectable. This follows the Certificate Transparency model (RFC 6962).

#### Scenario: Hash chain construction on entry creation
- **GIVEN** the most recent audit trail entry has `hash`: `a1b2c3d4...`
- **WHEN** a new audit trail entry is created
- **THEN** the new entry's `hash` MUST equal `SHA-256(previous_entry_hash + JSON_CANONICAL(current_entry_data))`
- **AND** `current_entry_data` MUST include: uuid, action, objectUuid, schemaUuid, registerUuid, changed, user, created
- **AND** the hash MUST be stored as a hexadecimal string in the `hash` column of `openregister_audit_trails`

#### Scenario: Genesis hash for first entry
- **GIVEN** a register has no audit trail entries
- **WHEN** the first audit trail entry is created
- **THEN** the `hash` MUST equal `SHA-256("GENESIS:" + register_uuid + ":" + JSON_CANONICAL(entry_data))`
- **AND** the genesis hash MUST be deterministic and reproducible for verification

#### Scenario: Verify hash chain integrity
- **GIVEN** a register with 1000 consecutive audit trail entries
- **WHEN** an auditor invokes `GET /api/audit-trail/verify?register={id}&from={date}&to={date}`
- **THEN** the system MUST iterate through all entries in chronological order
- **AND** for each entry, `SHA-256(previous_hash + current_entry_json)` MUST equal the stored hash
- **AND** the response MUST include: `{"valid": true, "entriesChecked": 1000, "firstEntry": "...", "lastEntry": "..."}`

#### Scenario: Detect tampered entry in hash chain
- **GIVEN** an attacker directly modifies the `changed` field of audit entry #500 in the database
- **WHEN** the hash chain is verified
- **THEN** verification MUST fail at entry #501 (because entry #501's hash was computed using the original #500 data)
- **AND** the verification report MUST include: `{"valid": false, "brokenAt": 501, "expectedHash": "...", "actualHash": "...", "suspectedTamperedEntry": 500}`

#### Scenario: Hash chain spans across archive boundaries
- **GIVEN** audit entries older than 2 years are archived to a separate table or storage
- **WHEN** the full hash chain is verified
- **THEN** the verification MUST load the last hash from the archive to validate the first entry in the active table
- **AND** the chain MUST be continuous across the boundary

### Requirement 3: Audit trail entries MUST NOT be deletable or modifiable through the application

No user, including administrators, SHALL be able to modify or delete audit trail entries through the OpenRegister API. The only permitted removal mechanism is the automated `LogCleanUpTask` cron job that removes entries past their `expires` date, and this mechanism MUST be configurable and auditable itself.

#### Scenario: Reject audit trail deletion via API
- **GIVEN** the current `AuditTrailController.destroy()` method allows deletion of audit entries
- **WHEN** immutability enforcement is enabled
- **THEN** `DELETE /api/audit-trail/{id}` MUST return HTTP 405 Method Not Allowed with body `{"error": "Audit trail entries are immutable and cannot be deleted"}`
- **AND** `DELETE /api/audit-trail/multiple` (`destroyMultiple()`) MUST also return HTTP 405
- **AND** `DELETE /api/audit-trail/clear` (`clearAll()`) MUST also return HTTP 405

#### Scenario: Reject audit trail modification via API
- **GIVEN** an admin attempts to `PUT /api/audit-trail/{id}` with modified data
- **WHEN** the request is processed
- **THEN** the system MUST return HTTP 405 Method Not Allowed
- **AND** no update operation SHALL be performed on the `openregister_audit_trails` table for content fields (uuid, action, changed, user, created)

#### Scenario: Automated expiry-based cleanup remains functional
- **GIVEN** the `LogCleanUpTask` (`lib/Cron/LogCleanUpTask.php`) runs hourly (every 3600 seconds)
- **WHEN** it invokes `AuditTrailMapper.clearLogs()` which deletes entries where `expires IS NOT NULL AND expires < NOW()`
- **THEN** only entries past their configured expiration MUST be removed
- **AND** the cleanup operation itself MUST produce a system-level log entry recording how many entries were purged

#### Scenario: Database-level protection against direct manipulation
- **WHEN** immutability is enforced at the database level
- **THEN** a database trigger SHOULD prevent `UPDATE` and `DELETE` statements on the `openregister_audit_trails` table for all columns except `expires` (which the cleanup job needs to read)
- **AND** if database triggers are not supported (e.g., SQLite in development), the application-level enforcement MUST be the fallback

### Requirement 4: The audit trail MUST record comprehensive BIO2 and GDPR compliance fields

Each audit trail entry MUST carry metadata fields required by BIO (Baseline Informatiebeveiliging Overheid) logging controls, AVG/GDPR Article 30 processing records, and Archiefwet 1995 provenance requirements. These fields are already present on the `AuditTrail` entity and MUST be populated systematically.

#### Scenario: Organisation identification fields populated on every entry
- **GIVEN** the OpenRegister instance is configured with organisation identifier `OIN:00000001234567890000` of type `OIN`
- **WHEN** any audit trail entry is created
- **THEN** the entry MUST include:
  - `organisationId`: `00000001234567890000`
  - `organisationIdType`: `OIN`
- **AND** these values MUST be sourced from the app configuration or the active organisation context

#### Scenario: Processing activity fields for GDPR compliance
- **GIVEN** schema `inwoners` is configured with processing activity ID `PA-2025-042` and URL `https://avg-register.gemeente.nl/verwerking/PA-2025-042`
- **WHEN** an audit trail entry is created for an object in this schema
- **THEN** the entry MUST include:
  - `processingActivityId`: `PA-2025-042`
  - `processingActivityUrl`: `https://avg-register.gemeente.nl/verwerking/PA-2025-042`
  - `processingId`: a unique identifier for this specific processing operation

#### Scenario: Confidentiality classification on audit entries
- **GIVEN** schema `vertrouwelijk-dossier` has confidentiality level `confidential`
- **WHEN** an audit entry is created for objects in this schema
- **THEN** `confidentiality` MUST be set to `confidential`
- **AND** when listing audit entries, the `confidentiality` field MUST be filterable so administrators can restrict access to sensitive audit data

#### Scenario: Retention period stored per audit entry
- **GIVEN** the retention settings specify `deleteLogRetention: 2592000000` (30 days in milliseconds)
- **WHEN** a delete-action audit entry is created
- **THEN** `retentionPeriod` MUST be set to the ISO 8601 duration equivalent (e.g., `P30D`)
- **AND** `expires` MUST be set to `created + 30 days`
- **AND** create-action entries MUST use `createLogRetention` (default 30 days)
- **AND** update-action entries MUST use `updateLogRetention` (default 7 days)
- **AND** read-action entries MUST use `readLogRetention` (default 24 hours)

#### Scenario: BIO2 logging controls satisfied
- **GIVEN** the BIO (Baseline Informatiebeveiliging Overheid) requires logging of: who, what, when, from where, and the result of the action
- **WHEN** any audit trail entry is reviewed
- **THEN** it MUST provide:
  - **Who**: `user` (UID) + `userName` (display name) + `organisationId`
  - **What**: `action` + `changed` (detailed field-level changes)
  - **When**: `created` (server-side UTC timestamp)
  - **From where**: `ipAddress` + `session` + `request` (Nextcloud request ID)
  - **Result**: the presence of the entry itself indicates success; failed operations SHOULD produce entries with action `error.*`

### Requirement 5: Sensitive data read operations MUST be audited

Read operations on schemas marked as containing sensitive or personal data (bijzondere persoonsgegevens) MUST also produce audit trail entries with action `read`. This is required by AVG/GDPR Article 30 and BIO control A.12.4.1. Read audit entries MUST NOT include the full object data to avoid creating additional copies of sensitive information.

#### Scenario: Log read of personal data
- **GIVEN** schema `inwoners` is marked as sensitive via `schema.archive.sensitiveData: true`
- **WHEN** user `medewerker-1` retrieves object `inwoner-123` via `GET /api/objects/{register}/{schema}/{id}`
- **THEN** an audit trail entry MUST be created with:
  - `action`: `read`
  - `objectUuid`: the UUID of `inwoner-123`
  - `user`: `medewerker-1`
  - `changed`: empty or `{"accessed": true}` (MUST NOT include the object's data)
- **AND** the entry MUST use `readLogRetention` for its `expires` calculation (default 24 hours)

#### Scenario: Bulk read of sensitive data
- **GIVEN** schema `inwoners` is marked as sensitive
- **WHEN** user `medewerker-1` lists objects via `GET /api/objects/{register}/{schema}?_limit=50`
- **THEN** a single audit trail entry MUST be created with action `read.list`
- **AND** the `changed` field MUST record `{"objectCount": 50, "query": {"_limit": 50}}` (without individual object data)

#### Scenario: Non-sensitive schemas skip read auditing
- **GIVEN** schema `producten` is NOT marked as sensitive
- **WHEN** any user reads objects from this schema
- **THEN** NO read audit entry SHALL be created (to avoid performance overhead)

#### Scenario: Read audit configurable at schema level
- **GIVEN** an administrator wants to enable read auditing for a specific schema
- **WHEN** they set `schema.archive.auditReads: true` on the schema configuration
- **THEN** all read operations on that schema MUST produce audit entries
- **AND** removing the flag MUST stop read auditing for future requests

### Requirement 6: The audit trail MUST support configurable retention periods per register

Audit trail retention MUST be configurable at the global level (via `ObjectRetentionHandler`) and overridable at the register level. Government registers subject to Archiefwet 1995 MUST support minimum 10-year retention. The existing `expires` field on `AuditTrail` and `AuditTrailMapper.setExpiryDate()` MUST be the mechanism for enforcement.

#### Scenario: Global default retention from settings
- **GIVEN** the retention settings in `ConfigurationSettingsHandler` specify:
  - `createLogRetention`: 2592000000ms (30 days)
  - `readLogRetention`: 86400000ms (24 hours)
  - `updateLogRetention`: 604800000ms (7 days)
  - `deleteLogRetention`: 2592000000ms (30 days)
- **WHEN** audit trail entries are created
- **THEN** the `expires` field MUST be set according to the action-specific retention period
- **AND** `LogCleanUpTask` MUST NOT remove entries before their `expires` date

#### Scenario: Per-register retention override for government compliance
- **GIVEN** register `archief` requires 20-year audit retention per Archiefwet 1995
- **WHEN** the admin sets `register.retention.auditTrailRetention: "P20Y"` on the register configuration
- **THEN** all audit entries for objects in this register MUST have `expires` set to `created + 20 years`
- **AND** this register-level setting MUST override the global defaults

#### Scenario: Minimum retention enforcement
- **GIVEN** a register marked as `archive.governmentRecord: true`
- **WHEN** an admin attempts to set audit retention below 10 years
- **THEN** the system MUST reject the setting with an error: `Government records require minimum 10-year audit retention per Archiefwet 1995`
- **AND** the setting MUST NOT be saved

#### Scenario: Retention period change updates existing entries
- **GIVEN** register `zaken` has 5000 audit entries with `expires` calculated from the old 30-day retention
- **WHEN** the admin increases retention to 5 years
- **THEN** `AuditTrailMapper.setExpiryDate()` MUST recalculate `expires` for entries that do not yet have an expiry date
- **AND** entries with an existing `expires` date SHOULD be extended if the new retention period is longer

#### Scenario: Archival audit entries use permanent retention
- **GIVEN** an audit entry with action `archival.destroyed` or `archival.transferred`
- **WHEN** the entry is created
- **THEN** `expires` MUST be set to NULL (permanent retention)
- **AND** `LogCleanUpTask` MUST NOT delete entries with NULL `expires`

### Requirement 7: The audit trail MUST be queryable with filtering, sorting, and pagination

The audit trail API MUST support rich querying to allow administrators, auditors, and compliance officers to find specific entries. The existing `AuditTrailController` and `AuditTrailMapper.findAll()` provide the foundation, but MUST support all filter combinations required for compliance auditing.

#### Scenario: Filter audit entries by object UUID
- **GIVEN** 500 audit entries exist across multiple objects
- **WHEN** a user requests `GET /api/audit-trail?object_uuid={uuid}`
- **THEN** only entries for that specific object MUST be returned
- **AND** the response MUST include pagination metadata: `total`, `page`, `pages`, `limit`, `offset`

#### Scenario: Filter audit entries by action type
- **GIVEN** an auditor needs to review all deletion events
- **WHEN** they request `GET /api/audit-trail?action=delete,referential_integrity.cascade_delete`
- **THEN** only entries with those action types MUST be returned (using the comma-separated IN filter in `AuditTrailMapper.findAll()`)

#### Scenario: Filter audit entries by user
- **GIVEN** an investigation requires all actions by a specific user
- **WHEN** the request includes `?user=behandelaar-1`
- **THEN** only entries where `user = 'behandelaar-1'` MUST be returned

#### Scenario: Filter audit entries by date range
- **GIVEN** an annual compliance audit covering January through December 2025
- **WHEN** the auditor requests `?created_from=2025-01-01&created_to=2025-12-31`
- **THEN** only entries within that date range MUST be returned

#### Scenario: Sort audit entries
- **GIVEN** the default sort is `created DESC` (most recent first)
- **WHEN** the user requests `?sort=user&order=ASC`
- **THEN** entries MUST be sorted alphabetically by user in ascending order
- **AND** only valid column names (as defined in `AuditTrailMapper.findAll()`) SHALL be accepted as sort fields

### Requirement 8: The audit trail MUST be exportable for external compliance audits

The audit trail MUST support export in formats suitable for external auditors, SIEM systems, and compliance reporting. The existing `AuditTrailController.export()` and `LogService.exportLogs()` provide a foundation that MUST be extended with hash verification data and standardized formats.

#### Scenario: Export audit trail as CSV for date range
- **GIVEN** an auditor requests all audit entries for register `zaken` from 2025-01-01 to 2025-12-31
- **WHEN** they invoke `GET /api/audit-trail/export?format=csv&register={id}&created_from=2025-01-01&created_to=2025-12-31`
- **THEN** the export MUST include all entries in the date range with columns: uuid, action, objectUuid, schemaUuid, registerUuid, user, userName, ipAddress, created, changed (JSON string)
- **AND** the export MUST be downloadable as a file with appropriate Content-Type and Content-Disposition headers

#### Scenario: Export audit trail as JSON with hash chain
- **GIVEN** an auditor requests a JSON export
- **WHEN** they invoke `GET /api/audit-trail/export?format=json&includeHashes=true`
- **THEN** each entry in the JSON array MUST include the `hash` field
- **AND** the export MUST include a `_verification` object with: `genesisHash`, `lastHash`, `entryCount`, `hashAlgorithm: "SHA-256"`, `chainValid: true/false`
- **AND** the auditor MUST be able to independently verify the chain using the exported data

#### Scenario: Export for SIEM integration (syslog format)
- **GIVEN** the organisation uses a SIEM system that ingests syslog-formatted events
- **WHEN** audit entries are exported with `format=syslog`
- **THEN** each entry MUST be formatted as an RFC 5424 syslog message with structured data elements
- **AND** the `SD-ID` MUST be `openregister@IANA-PEN` with parameters: action, objectUuid, user, ipAddress

#### Scenario: Export includes metadata for compliance evidence
- **GIVEN** the export is intended as evidence for an ISO 27001 or BIO audit
- **WHEN** `includeMetadata=true` is specified
- **THEN** the export MUST include: organisationId, organisationIdType, processingActivityId, confidentiality, retentionPeriod for each entry

### Requirement 9: Bulk operations MUST produce traceable audit entries

When multiple objects are created, updated, or deleted in a single batch operation, each object MUST receive its own audit trail entry, and all entries from the same batch MUST be linkable through a shared batch identifier.

#### Scenario: Batch import creates individual audit entries
- **GIVEN** a CSV import of 100 objects into schema `meldingen`
- **WHEN** the import runs with `silent: false`
- **THEN** each of the 100 created objects MUST have its own audit trail entry with action `create`
- **AND** all entries MUST share the same `request` ID (the Nextcloud request ID for the import request)
- **AND** each entry MUST be independently verifiable in the hash chain

#### Scenario: Batch update via API creates individual audit entries
- **GIVEN** a bulk update request modifies the status of 50 objects
- **WHEN** the update is processed
- **THEN** each modified object MUST receive its own audit entry with action `update`
- **AND** the `changed` field for each entry MUST reflect only that specific object's changes

#### Scenario: Cascade deletion creates linked audit entries
- **GIVEN** deleting `person-1` cascades to 5 orders and 15 order-lines
- **WHEN** the cascade completes
- **THEN** 21 audit entries MUST be created (1 for the person + 5 for orders + 15 for order-lines)
- **AND** each cascade entry MUST include `triggerObject: "person-1"` in its `changed` field for traceability
- **AND** all entries MUST be part of the same hash chain

### Requirement 10: The audit trail MUST support cross-app visibility

Audit trail data MUST be accessible to other Nextcloud apps and external systems through standardized integration points, including the Nextcloud Activity stream, event dispatching, and webhook notifications.

#### Scenario: Surface audit entries in Nextcloud Activity stream
- **GIVEN** the OpenRegister app implements `OCP\Activity\IProvider`
- **WHEN** an audit trail entry is created
- **THEN** the Activity stream MUST display: `"{userName} {action}d object {objectUuid} in {schemaName}"`
- **AND** clicking the activity entry MUST link to the object detail view in the OpenRegister UI

#### Scenario: Webhook notification on audit events
- **GIVEN** an n8n workflow is configured to listen for `audit.created` events
- **WHEN** any audit trail entry is created
- **THEN** a CloudEvent webhook payload MUST be sent containing the full audit entry (excluding the raw `changed` data if the schema is marked as sensitive)

#### Scenario: MCP tool exposes audit trail
- **GIVEN** the OpenRegister MCP server provides tools for registers, schemas, and objects
- **WHEN** an MCP client requests audit trail data
- **THEN** an `audit-trails` tool SHOULD be available with `list` and `get` actions
- **AND** the tool MUST respect the same RBAC permissions as the REST API

### Requirement 11: Audit trail writing MUST be performant and MUST NOT block user-facing operations

Audit trail creation MUST NOT significantly impact the response time of CRUD operations. The system MUST handle high-throughput scenarios (bulk imports, cascade operations) without degrading performance.

#### Scenario: Audit trail write completes within acceptable latency
- **GIVEN** a single object update triggers an audit trail entry
- **WHEN** the entry is written to the database
- **THEN** the audit trail insert MUST complete within 10ms under normal load
- **AND** the total overhead of audit trail creation (including hash computation) MUST NOT exceed 5% of the total request time

#### Scenario: High-throughput bulk import performance
- **GIVEN** a bulk import of 10,000 objects with `silent: false`
- **WHEN** all 10,000 audit entries are created
- **THEN** the hash chain computation MUST use sequential insertion (not parallel) to maintain chain ordering
- **AND** the total import time MUST NOT exceed 2x the time of the same import with `silent: true`

#### Scenario: Audit trail query performance with large datasets
- **GIVEN** 5 million audit trail entries spanning 3 years
- **WHEN** a user queries `GET /api/audit-trail?register={id}&_limit=30`
- **THEN** the query MUST use the index on `(register, created)` columns
- **AND** the response MUST return within 200ms

#### Scenario: Statistics computation remains fast
- **GIVEN** `AuditTrailMapper.getStatistics()` uses `COUNT(id)` and `COALESCE(SUM(size), 0)`
- **WHEN** called for a register with 1 million entries
- **THEN** the aggregate query MUST return within 100ms
- **AND** `getStatisticsGroupedBySchema()` MUST remain efficient by using `GROUP BY schema`

### Requirement 12: Audit trail storage MUST be optimized for long-term retention

For registers requiring 10+ year retention, the system MUST provide mechanisms to manage storage growth including compression, archival to cold storage, and the ability to query across active and archived data.

#### Scenario: Archive old entries for performance
- **GIVEN** 5 million audit trail entries spanning 8 years
- **WHEN** entries older than 2 years are archived via a configurable archival policy
- **THEN** archived entries MUST be moved to a separate `openregister_audit_trails_archive` table (or external storage)
- **AND** the hash chain MUST remain verifiable across the archive boundary (the active table's first entry references the archive's last hash)
- **AND** archived entries MUST remain queryable via `GET /api/audit-trail?includeArchive=true`

#### Scenario: Storage size tracking per schema
- **GIVEN** `AuditTrailMapper.getStatisticsGroupedBySchema()` returns per-schema totals and sizes
- **WHEN** the dashboard displays storage usage
- **THEN** the storage size MUST be accurate (calculated from the `size` column on each entry)
- **AND** administrators MUST be alerted when audit trail storage exceeds configurable thresholds

#### Scenario: Compressed storage for large changed fields
- **GIVEN** an object with 50 properties is updated and the `changed` field contains a large JSON blob
- **WHEN** the audit entry is stored
- **THEN** the `size` field MUST reflect the actual serialized byte size (as implemented in `AuditTrailMapper.createAuditTrail()` using `strlen(serialize($objectEntity->jsonSerialize()))`)
- **AND** for entries larger than 64KB, the system SHOULD compress the `changed` field using gzip before storage

### Requirement 13: GDPR right to erasure MUST be reconciled with audit trail retention

When a data subject exercises their right to erasure (AVG Article 17), the audit trail MUST balance the legal obligation to erase personal data with the legal obligation to maintain audit records for compliance. The resolution MUST follow the principle that audit records serve as legal evidence and are exempt from erasure under AVG Article 17(3)(b) (legal claims) and Article 17(3)(e) (archival in the public interest).

#### Scenario: Erasure request for personal data in audit trail
- **GIVEN** a data subject requests erasure of all their personal data
- **AND** audit trail entries exist that reference this person's data in the `changed` field
- **WHEN** the erasure is processed
- **THEN** the `changed` field in relevant audit entries MUST be pseudonymized (personal data replaced with hashed identifiers)
- **AND** the `user` field MUST NOT be pseudonymized if it refers to the acting official (not the data subject)
- **AND** the audit entry MUST remain in the chain (not deleted) to preserve chain integrity
- **AND** a new audit entry with action `gdpr.pseudonymized` MUST record the pseudonymization operation

#### Scenario: Distinguish between data subject and actor in audit entries
- **GIVEN** user `medewerker-1` creates an object containing personal data of citizen `burger-123`
- **WHEN** `burger-123` requests erasure
- **THEN** `medewerker-1` in the `user` field MUST NOT be erased (they are the actor, not the subject)
- **AND** personal data of `burger-123` within the `changed` field MUST be pseudonymized

#### Scenario: Audit trail retained for ongoing legal proceedings
- **GIVEN** audit entries are subject to a legal hold (as defined in the `archivering-vernietiging` spec)
- **WHEN** an erasure request conflicts with the legal hold
- **THEN** the erasure MUST be deferred until the legal hold is lifted
- **AND** the data subject MUST be informed of the deferral reason

### Requirement 14: The audit trail MUST support object reversion using historical entries

The audit trail MUST serve as the source of truth for object version history, enabling reversion to any previous state. The existing `AuditTrailMapper.revertObject()` and `RevertHandler` implement this capability and MUST maintain consistency with the immutable audit trail.

#### Scenario: Revert object to a previous version
- **GIVEN** object `melding-1` is at version `1.0.5`
- **WHEN** a user reverts to version `1.0.2` via `POST /api/revert/{register}/{schema}/{id}` with `{"version": "1.0.2"}`
- **THEN** `AuditTrailMapper.findByObjectUntil()` MUST find all entries after version `1.0.2`
- **AND** `AuditTrailMapper.revertChanges()` MUST apply reversions in reverse chronological order
- **AND** the result MUST be saved as a new version `1.0.6` (reversion never deletes history)
- **AND** an audit trail entry MUST be created with action `revert` and `changed` including `{"revertedToVersion": "1.0.2"}`

#### Scenario: Revert object to a point in time
- **GIVEN** object `melding-1` has been modified 8 times over the past week
- **WHEN** the user reverts to DateTime `2026-03-15T14:00:00Z`
- **THEN** `AuditTrailMapper.findByObjectUntil(objectId, objectUuid, $until)` MUST return entries created after that timestamp
- **AND** each entry's changes MUST be reversed in order

#### Scenario: Revert respects object locking
- **GIVEN** object `melding-1` is locked by `behandelaar-2` via `LockHandler`
- **WHEN** `behandelaar-1` attempts a revert
- **THEN** `RevertHandler` MUST throw a `LockedException`
- **AND** the revert MUST NOT proceed

#### Scenario: Revert produces a new audit entry preserving the chain
- **GIVEN** a successful revert from version `1.0.5` to `1.0.2`
- **WHEN** the new version `1.0.6` is saved
- **THEN** the audit entry for the revert MUST be appended to the hash chain like any other entry
- **AND** versions `1.0.3`, `1.0.4`, and `1.0.5` MUST remain in the audit trail (history is never deleted)

### Requirement 15: Audit trail MUST be toggleable via application settings

The audit trail system MUST respect the `auditTrailsEnabled` setting in `ConfigurationSettingsHandler`. When disabled, CRUD operations MUST proceed without audit trail creation. The toggle MUST itself be audited.

#### Scenario: Audit trails enabled (default)
- **GIVEN** `auditTrailsEnabled` is `true` in the retention settings (the default)
- **WHEN** any CRUD operation is performed
- **THEN** `SaveObject` and `DeleteObject` MUST call `AuditTrailMapper.createAuditTrail()`

#### Scenario: Audit trails disabled
- **GIVEN** an admin sets `auditTrailsEnabled` to `false` via `PUT /api/settings/retention`
- **WHEN** CRUD operations are performed
- **THEN** `isAuditTrailsEnabled()` MUST return `false`
- **AND** `createAuditTrail()` MUST NOT be called
- **AND** a system-level warning MUST be logged: `Audit trail creation is disabled. This may violate compliance requirements.`

#### Scenario: Toggling audit trails produces a log entry
- **GIVEN** audit trails are currently enabled
- **WHEN** an admin disables them
- **THEN** a final audit entry MUST be created with action `system.audit_disabled` BEFORE the feature is turned off
- **AND** when re-enabled, an entry with action `system.audit_enabled` MUST be created

## Current Implementation Status
- **Implemented:**
  - `AuditTrail` entity (`lib/Db/AuditTrail.php`) with comprehensive fields: uuid, schema, register, object, objectUuid, registerUuid, schemaUuid, action, changed, user, userName, session, request, ipAddress, version, created, organisationId, organisationIdType, processingActivityId, processingActivityUrl, processingId, confidentiality, retentionPeriod, size, expires
  - `AuditTrailMapper` (`lib/Db/AuditTrailMapper.php`) with `createAuditTrail()` recording create/update/delete actions with full user context, session, IP address, and field-level diffs (old/new values). Also provides: `findAll()` with filtering/sorting/pagination, `revertObject()` and `revertChanges()` for object reversion, `getStatistics()` and `getStatisticsGroupedBySchema()` for analytics, `getActionChartData()` for visualization, `getDetailedStatistics()` and `getActionDistribution()` for dashboards, `getMostActiveObjects()` for activity tracking, `clearLogs()` for expiry-based cleanup, `clearAllLogs()` for full purge, `setExpiryDate()` for retention period application
  - `AuditHandler` (`lib/Service/Object/AuditHandler.php`) with `getLogs()` for filtered retrieval and `validateObjectOwnership()` for access control
  - `AuditTrailController` (`lib/Controller/AuditTrailController.php`) with endpoints: `index()` (list all), `show()` (get by ID), `objects()` (get by register/schema/object), `export()` (CSV/JSON export), `destroy()` (delete single), `destroyMultiple()` (delete multiple), `clearAll()` (delete all)
  - `LogService` (`lib/Service/LogService.php`) orchestrating audit trail operations including export in CSV/JSON format
  - `LogCleanUpTask` (`lib/Cron/LogCleanUpTask.php`) runs hourly, deletes entries past their `expires` date
  - `SaveObject` calls `createAuditTrail()` on both create and update (guarded by `silent` flag and `isAuditTrailsEnabled()`)
  - `DeleteObject` calls `createAuditTrail()` on delete with cascade context
  - `ReferentialIntegrityService` logs cascade/set_null/set_default/restrict actions with dedicated action types via `logIntegrityAction()`
  - `RevertHandler` and `AuditTrailMapper.revertObject()` enable object reversion from audit trail data
  - `ObjectRevertedEvent` dispatched on successful revert
  - Configurable retention per action type: `createLogRetention`, `readLogRetention`, `updateLogRetention`, `deleteLogRetention` (in milliseconds)
  - Global toggle: `auditTrailsEnabled` in retention settings
  - Default expiration: 30 days from creation (set in `createAuditTrail()`)
- **NOT implemented:**
  - Cryptographic hash chaining (no `hash` column on `openregister_audit_trails` table; no SHA-256 chain computation; no genesis hash)
  - Hash chain verification API endpoint
  - Immutability enforcement (the `destroy()`, `destroyMultiple()`, and `clearAll()` endpoints currently allow deletion; `update()` method exists on the mapper)
  - Database-level triggers preventing UPDATE/DELETE on audit entries
  - Per-register retention override (retention is global, not per-register)
  - Minimum retention enforcement for government records
  - Sensitive data read auditing (no `read` action logging; only mutations are recorded)
  - Archive mechanism for old entries (no partitioning, archive table, or cold storage)
  - SIEM export format (syslog)
  - GDPR pseudonymization of audit trail entries
  - Batch tracking identifier across bulk operations
  - Activity stream integration (`IProvider`)
  - Compression for large `changed` fields
  - Storage threshold alerts
  - System-level audit of the audit toggle itself

## Standards & References
- **AVG / GDPR Article 30** -- Processing records requirement; Article 17 right to erasure with exceptions
- **BIO (Baseline Informatiebeveiliging Overheid)** -- Dutch government information security baseline; controls A.12.4.1 (event logging), A.12.4.2 (protection of log information), A.12.4.3 (administrator and operator logs)
- **BIO2** -- Updated BIO framework with enhanced logging requirements for cloud-hosted government systems
- **Archiefwet 1995** -- Dutch archival law mandating long-term retention of government records including audit trails
- **Archiefbesluit 1995** -- Implementing decree; Articles 6-8 on destruction evidence
- **NEN-ISO 16175-1:2020** -- Records management standard (successor to NEN 2082); audit trail requirements for record-keeping systems
- **NEN 2082** -- Records management audit trail requirements (superseded by NEN-ISO 16175-1:2020 but still referenced in tenders)
- **RFC 6962** -- Certificate Transparency; hash chain model reference for tamper-evident logging
- **RFC 5424** -- Syslog protocol for SIEM integration
- **RFC 6902** -- JSON Patch format for describing changes between JSON documents
- **W3C PROV-O** -- Provenance ontology for audit trail semantics
- **Common Criteria (ISO 15408)** -- Security audit logging requirements (FAU class)
- **ISO 27001:2022** -- Information security management; Annex A.8.15 (logging), A.8.17 (clock synchronization)
- **OWASP Logging Cheat Sheet** -- Best practices for security event logging

## Cross-Referenced Specs
- **deletion-audit-trail** -- Defines how referential integrity actions (CASCADE, SET_NULL, SET_DEFAULT, RESTRICT) are logged with dedicated action types `referential_integrity.*`
- **archivering-vernietiging** -- Archival lifecycle actions produce audit entries with `archival.*` action types; destruction certificates depend on audit trail integrity; legal holds interact with audit retention
- **content-versioning** -- Version history is built on top of the audit trail; `AuditTrailMapper.revertObject()` reconstructs object state from audit entries; version metadata (MAJOR.MINOR.PATCH) is stored in the `version` field

## Specificity Assessment
- The spec is well-defined for CRUD auditing, field-level diff storage, and the revert mechanism, all of which are fully implemented.
- Hash chaining is precisely specified but not yet implemented; the implementation requires: (1) adding a `hash` VARCHAR(64) column via migration, (2) computing SHA-256 on insert in `createAuditTrail()`, (3) a verification endpoint.
- Immutability enforcement requires removing or guarding the `destroy()`, `destroyMultiple()`, and `clearAll()` endpoints and adding database-level protections.
- Per-register retention requires extending the Register entity's configuration and modifying `createAuditTrail()` to read register-specific retention periods.
- Read auditing requires intercepting `GetObject` operations and checking the schema's sensitivity flag.
- GDPR pseudonymization requires a new service that can redact personal data within `changed` fields while preserving chain integrity.
- Open questions:
  - Should the hash chain be per-register (isolated chains) or global (single chain across all registers)?
  - Should the `clearAll()` endpoint be removed entirely or restricted to a super-admin role with additional confirmation?
  - What is the threshold for compressing `changed` fields (64KB, 256KB)?
  - Should archived entries be queryable inline or require a separate API call?
  - How should the system handle hash chain verification for registers with millions of entries (streaming verification vs. background job)?

## Nextcloud Integration Analysis

- **Status**: Partially implemented in OpenRegister. Core CRUD auditing, field-level diffs, reversion, and retention-based cleanup are production-ready. Hash chaining, immutability enforcement, read auditing, and per-register retention are documented enhancements.
- **Existing Implementation**: `AuditTrail` entity with 25+ fields covering identity, action, changes, network context, GDPR fields, and retention. `AuditTrailMapper` with full CRUD, querying, statistics, charting, reversion, and cleanup. `AuditHandler` for filtered retrieval. `AuditTrailController` with REST endpoints. `LogCleanUpTask` for automated expiry-based cleanup. `SaveObject` and `DeleteObject` integrate audit trail creation. `ReferentialIntegrityService` logs integrity actions. `RevertHandler` enables object reversion from audit data.
- **Nextcloud Core Integration**: Uses NC's `Entity`/`QBMapper` patterns. Request metadata sourced from `IRequest`. User context from `IUserSession`. Background cleanup via `TimedJob`. Events via `IEventDispatcher` (`ObjectRevertedEvent`). Should implement `IProvider` for the Activity app to surface audit entries. Could integrate with NC's `ILogger` for system-level audit logging. Export functionality leverages NC's file download infrastructure.
- **Recommendation**: The existing audit trail infrastructure is comprehensive and production-ready for CRUD auditing. Priority enhancements: (1) Immutability enforcement by disabling `destroy`/`destroyMultiple`/`clearAll` endpoints, (2) Hash chaining via SHA-256 for tamper detection, (3) Per-register retention override for government compliance, (4) Sensitive data read auditing. Lower priority: SIEM export, Activity stream integration, GDPR pseudonymization, storage archival.
