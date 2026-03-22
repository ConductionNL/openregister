## Why

Government tenders (56-58% of analyzed 39K tenders) require both immutable audit trails with tamper-evidence and GDPR Article 30 processing registers (verwerkingsregister). OpenRegister already has a functional AuditTrail entity with verwerkingenlogging fields (`processingActivityId`, `organisationId`, etc.), but lacks cryptographic hash chaining for tamper detection, a verification API to prove chain integrity, and a structured API for querying the verwerkingsregister. This change bridges the gap between the existing implementation and the compliance requirements demanded by Dutch government procurement.

## What Changes

- Add cryptographic hash chaining (SHA-256) to all audit trail entries, making the log tamper-evident
- Add a hash chain verification endpoint that auditors can use to prove integrity
- Add a `hash` and `previousHash` column to the `AuditTrail` entity and a database migration
- Expose a verwerkingsregister (processing register) API endpoint that returns Art 30-compliant processing activity overviews
- Add data subject access request (inzageverzoek) support: query all audit entries by a subject identifier
- Add an audit trail export endpoint (JSON/CSV) for external compliance auditing
- Ensure audit trail entries are truly immutable: block PUT/PATCH/DELETE on audit trail records via the API

## Capabilities

### New Capabilities
- `audit-hash-chain`: Cryptographic SHA-256 hash chaining on audit trail entries with genesis hash, verification endpoint, and tamper detection reporting
- `verwerkingsregister-api`: GDPR Art 30 processing register API — query processing activities, generate data subject access reports, export verwerkingsregister

### Modified Capabilities
- `audit-trail-immutable`: Add `hash` and `previousHash` fields to the AuditTrail entity, enforce immutability by blocking modification/deletion API endpoints (HTTP 405)

## Impact

- **Database**: New migration adding `hash` (VARCHAR 64) and `previous_hash` (VARCHAR 64) columns to `openregister_audit_trails` table
- **Entity**: `AuditTrail.php` — two new fields, hash computation logic
- **Controller**: `AuditTrailController.php` — new verification endpoint, immutability enforcement, export endpoint
- **New service**: `AuditHashService.php` — hash chain computation and verification logic
- **New controller**: `VerwerkingsregisterController.php` — processing register and inzageverzoek endpoints
- **Routes**: New API routes for verification, export, verwerkingsregister
- **Dependent apps**: opencatalogi/softwarecatalog use AuditTrail read-only — no breaking changes expected
- **Performance**: Hash computation adds ~1ms per write; verification is O(n) over chain length, should be async for large datasets
