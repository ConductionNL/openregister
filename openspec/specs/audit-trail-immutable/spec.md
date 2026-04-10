# audit-trail-immutable Specification

---
status: implemented
---

## Purpose
Implement an immutable audit trail with cryptographic hash chaining for all register operations. Every create, read (of sensitive data), update, and delete MUST be recorded in a tamper-evident log with minimum 10-year retention. The audit trail MUST be independently verifiable and exportable for compliance auditing.

**Tender demand**: 56% of analyzed government tenders require immutable audit trail capabilities.

## ADDED Requirements

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

### Current Implementation Status
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

### Standards & References
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
