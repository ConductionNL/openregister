## Context

OpenRegister has a fully functional AuditTrail system that records create, update, and delete operations on register objects. The `AuditTrail` entity already stores user, action, changed data, timestamps, and verwerkingenlogging fields (organisation ID, processing activity ID, confidentiality, retention period). However, two critical compliance gaps remain:

1. **No tamper evidence**: Entries can theoretically be modified in the database without detection. Government auditors require cryptographic proof that the log has not been altered.
2. **No verwerkingsregister API**: While fields exist for GDPR Art 30 data, there is no dedicated API to query processing activities, generate data subject access reports, or export the register in a structured format.

Current stack: PHP 8.1+, Nextcloud OCP framework, PostgreSQL, Nextcloud Entity/Mapper pattern.

## Goals / Non-Goals

**Goals:**
- Add SHA-256 hash chaining to every audit trail entry for tamper detection
- Provide a verification endpoint that validates the entire chain or a subset
- Enforce true immutability: HTTP 405 on PUT/PATCH/DELETE for audit trail records
- Expose a verwerkingsregister API for Art 30 compliance queries
- Support data subject access requests (inzageverzoek) through the API
- Enable audit trail export in JSON and CSV formats

**Non-Goals:**
- Purpose-bound access control (blocking access without valid processing purpose) — deferred to a future change as it requires deep integration with the RBAC system
- PDF export of data subject access reports — JSON export is sufficient for now
- Real-time streaming of audit events — batch queries are adequate
- External audit trail storage (e.g., blockchain, external SIEM) — out of scope

## Decisions

### 1. Hash chain implementation in AuditHashService

**Decision**: Create a dedicated `AuditHashService` that computes SHA-256 hashes at insert time and provides verification.

**Rationale**: Separating hash logic from the mapper keeps the AuditTrailMapper focused on CRUD and allows the hash service to be independently tested. The service is called from the mapper's `insert()` method override.

**Alternatives considered**:
- Hash in AuditTrailMapper directly — rejected because it mixes concerns and makes testing harder
- Hash in a Nextcloud event listener — rejected because it would allow a window where unhashed entries exist

**Hash formula**: `SHA-256(previous_hash + JSON(entry_data_without_hash_fields))`
- Genesis hash for first entry: `SHA-256("openregister-genesis-v1")`
- Entry data is canonical JSON (sorted keys, no whitespace) excluding `hash` and `previousHash` fields

### 2. Database migration for hash columns

**Decision**: Add `hash` VARCHAR(64) and `previous_hash` VARCHAR(64) columns to `openregister_audit_trails`, both nullable to support existing data.

**Rationale**: Existing entries predate hash chaining. Nullable columns allow backward compatibility. A backfill command can optionally chain existing entries.

**Migration**: Standard Nextcloud `IMigrationStep` with `changeSchema()`.

### 3. Immutability enforcement at controller level

**Decision**: Override update/delete routes in `AuditTrailController` to return HTTP 405.

**Rationale**: The controller already handles CRUD. Blocking at this level is the simplest approach that works within Nextcloud's routing. Database-level triggers are PostgreSQL-specific and not portable.

### 4. Verwerkingsregister as dedicated endpoints on AuditTrailController

**Decision**: Add verwerkingsregister endpoints to the existing `AuditTrailController` rather than creating a separate controller.

**Rationale**: The verwerkingsregister queries the same `openregister_audit_trails` table. A separate controller would add routing complexity without clear benefit. The endpoints are:
- `GET /api/audit-trails/verwerkingsregister` — list processing activities
- `GET /api/audit-trails/inzageverzoek/{identifier}` — data subject access report
- `GET /api/audit-trails/export` — export audit trail as JSON/CSV

### 5. Verification endpoint design

**Decision**: `GET /api/audit-trails/verify` with optional `?from={id}&to={id}` range parameters.

**Rationale**: Full chain verification can be expensive (O(n)). Range parameters allow auditors to verify specific segments. The response includes: valid/invalid status, first broken entry (if any), and total entries verified.

## Risks / Trade-offs

- **[Performance]** Hash computation adds ~1ms per audit write. For bulk imports (1000+ objects), this adds measurable latency. Mitigation: hash computation is a single SHA-256 call per entry, which is CPU-cheap.
- **[Concurrency]** Two simultaneous writes could race for the "previous hash". Mitigation: Use a database transaction with `SELECT ... FOR UPDATE` on the last entry to serialize hash chain writes.
- **[Backward compatibility]** Existing audit entries have no hash. Mitigation: Nullable hash columns; verification endpoint skips entries with null hashes and reports the unverified range.
- **[Data volume]** Verwerkingsregister queries on large audit tables could be slow. Mitigation: Add database index on `processing_activity_id` column in the migration.

## Migration Plan

1. Deploy migration adding `hash` and `previous_hash` columns (nullable)
2. New entries automatically get hashed from deployment onward
3. Optional: run `occ openregister:audit:backfill-hashes` to chain existing entries (out of scope for this change, but the hash service supports it)
4. Rollback: drop the two columns; no data loss since existing functionality is unaffected

## Open Questions

- Should the backfill command for existing entries be included in this change or deferred? (Recommendation: defer)
- Should verification results be cached for performance on repeated checks? (Recommendation: no caching — auditors expect fresh results)
