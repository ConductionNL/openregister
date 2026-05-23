# Tasks: audit-hash-chain

## 1. Database Migration

- [ ] 1.1 Create a new migration file in `lib/Migration/` following the naming convention (e.g., `Version20260523000000.php` for today's date). The migration MUST add two nullable VARCHAR(64) columns to the `audit_trails` table: `hash` and `previous_hash`. Use `ISchemaWrapper` and `Table::addColumn()` with `Type::STRING, ['length' => 64, 'notnull' => false]`.
- [ ] 1.2 Add an index on the `hash` column: `$table->addIndex(['hash'], 'idx_audit_trails_hash')` for verification endpoint query performance.
- [ ] 1.3 Verify the migration can run forward and backward (rollback) without errors. Add a PHPUnit test covering successful migration application and column presence.

## 2. Core Hash Computation Service

- [ ] 2.1 Extend or create `AuditTrailHashService` in `lib/Service/` with the following methods:
  - `getGenesisHash(): string` — returns `SHA-256("openregister-genesis-v1")` as the anchor for the first entry.
  - `computeCanonicalJson(array $entryData): string` — excludes `hash` and `previousHash` from the data, sorts all remaining keys lexicographically (case-sensitive), encodes as compact JSON (no whitespace), and UTF-8 encodes the result.
  - `computeHash(string $previousHash, array $entryData): string` — returns `SHA-256($previousHash . $canonicalJson)`.
  - `getLastEntry(IDBConnection $db): ?array` — queries the audit_trails table for the entry with the maximum ID; returns null if no entries exist.

- [ ] 2.2 Add PHPUnit tests for `AuditTrailHashService`:
  - `testComputeHashDeterministic()` — verify that two calls with identical input produce identical hashes.
  - `testCanonicalJsonKeyOrder()` — verify that different insertion orders of the same keys produce the same JSON (lexicographic sorting).
  - `testCanonicalJsonExcludesHashFields()` — verify that `hash` and `previousHash` are never included in the canonical JSON.
  - `testGenesisHashConstant()` — verify the genesis hash matches the documented constant value.
  - `testCanonicalJsonUtf8()` — verify that non-ASCII characters (e.g., "François", "José", "Müller") are encoded as UTF-8, not escaped.

## 3. Serialized Audit Trail Writes

- [ ] 3.1 Extend `AuditTrailService` (existing service in `lib/Service/`) to integrate hash chaining:
  - When creating a new audit trail entry via `saveAuditTrail()` (or equivalent), wrap the logic in a database transaction with SERIALIZABLE isolation (or use row-level locking as a fallback).
  - Within the transaction: fetch the last entry via `AuditTrailHashService::getLastEntry()`, compute the new entry's `previousHash` from the last entry's hash (or genesis hash if no prior entries), compute the new entry's `hash`, and INSERT the entry with both hash fields.
  - On transaction failure (Throwable), log the error, clean up any partial state, and re-throw with a clear message (e.g., "Audit write serialization failed").

- [ ] 3.2 Add a `@spec openspec/changes/audit-hash-chain/tasks.md#task-3.1` PHPDoc tag to the modified method(s).

- [ ] 3.3 Add PHPUnit tests for concurrent writes:
  - `testConcurrentAuditWritesDoNotSharePreviousHash()` — spawn two concurrent processes that each create an audit entry; verify that both entries are successfully written with different `previousHash` values (or both with genesis hash if they are the first two entries ever).
  - `testAuditWriteSerializationUnderRaceCondition()` — use a custom database transaction mock to simulate a concurrent write race; verify that the service correctly re-fetches the "last entry" and computes the correct chain.

## 4. Hash Chain Verification Endpoint

- [ ] 4.1 Create a new controller method in `AuditTrailController` (or extend an existing one):
  - Method name: `verify(IRequest $request): DataResponse`.
  - Annotation: `#[NoAdminRequired]` (auditors may not be admins; later authorization enforces per-object audit read permissions).
  - Query parameters: `?from=<int>&?to=<int>` (optional range for partial verification).
  - Return shape: `{valid: bool, entriesVerified: int, brokenAt?: int, broken?: int[], skippedNullHashes?: int, range?: {from: int, to: int}}`.
  - Authorization: only users with audit read permissions should call this endpoint. Use per-object RBAC checks or audit-specific permission (e.g., `requirePermission('audit', 'read')`).

- [ ] 4.2 Create `AuditTrailVerificationService` in `lib/Service/`:
  - Method `verify(?int $from = null, ?int $to = null): array` — returns the response shape above.
  - Algorithm:
    1. If `$from` and `$to` are set, fetch entries where `ID >= $from AND ID <= $to`, and also fetch the entry at `ID = $from - 1` to obtain its hash as the chain anchor.
    2. Iterate entries in ID-ascending order.
    3. For each entry with a non-null `hash`: recompute the hash and compare to stored. If mismatch, mark the entry as broken and record its ID.
    4. Track the first broken entry ID.
    5. Count skipped (null-hash) entries.
    6. Return the response object.

- [ ] 4.3 Wire the controller method to a route in `appinfo/routes.php`:
  - Route: `GET /api/audit-trails/verify`.
  - Verb: `GET`.
  - Specific routes BEFORE wildcard routes.

- [ ] 4.4 Add the `@spec openspec/changes/audit-hash-chain/tasks.md#task-4.1` and `@spec openspec/changes/audit-hash-chain/tasks.md#task-4.2` PHPDoc tags to the controller and service.

- [ ] 4.5 Add PHPUnit tests for `AuditTrailVerificationService`:
  - `testVerifyFullChainValid()` — create 5 audit entries with correct hash chains; verify full chain returns `valid: true, entriesVerified: 5, skippedNullHashes: 0`.
  - `testVerifyDetectsFirstBrokenEntry()` — create 5 entries, manually modify the data of entry 2 (breaking its hash); verify returns `valid: false, brokenAt: 2`.
  - `testVerifyWithRangeParameters()` — create 10 entries; verify range `from=3&to=7` returns `entriesVerified: 5, range: {from: 3, to: 7}`.
  - `testVerifySkipsNullHashes()` — create 3 entries pre-migration (nulls), then 4 post-migration (with hashes); verify returns `valid: true, entriesVerified: 4, skippedNullHashes: 3`.
  - `testVerifyChainBreakDetectedMidway()` — create 10 entries; modify entry 5; verify returns `valid: false, brokenAt: 5, broken: [5, 6, 7, 8, 9, 10]` (all entries from the break onward have invalid hashes due to the chain break).

- [ ] 4.6 Add a Newman API collection test:
  - GET `/api/audit-trails/verify` — 200 with valid chain.
  - GET `/api/audit-trails/verify?from=10&to=20` — 200 with correct range response.
  - Malformed range parameters (e.g., `from=abc`) — 400 with validation error.

## 5. Integration with Existing AuditTrail Writes

- [ ] 5.1 Identify all places in the codebase where audit trail entries are created (search for calls to `AuditTrailService::saveAuditTrail()` or equivalent, or where audit entries are inserted via the controller).
- [ ] 5.2 Ensure that all writes use the extended `AuditTrailService` (already handled by task 3.1). No direct inserts bypassing the service layer.
- [ ] 5.3 Test with real object mutations (CRUD operations on objects): verify that audit entries are created with correct hashes and the chain is unbroken.

## 6. API Documentation

- [ ] 6.1 Document the new endpoint in OpenRegister's API docs (if using Swagger/OpenAPI):
  - `GET /api/audit-trails/verify` with optional `from` and `to` query parameters.
  - Response shape: `{valid: bool, entriesVerified: int, brokenAt?: int, broken?: int[], skippedNullHashes?: int, range?: {from: int, to: int}}`.
  - Example: full chain valid, chain with break, range verification.

## 7. Security & Auditing

- [ ] 7.1 Verify that hash fields are **read-only** in the API (no client-provided `hash` or `previousHash` in POST/PUT requests).
- [ ] 7.2 Log all verification endpoint calls (especially any that detect breaks) to the audit trail itself for forensic purposes (note: the log entry itself will have a hash chained from the prior entry).
- [ ] 7.3 Add a `@spec openspec/changes/audit-hash-chain/tasks.md#task-7` PHPDoc tag to any logging or security-related code.

## 8. Documentation & Changelog

- [ ] 8.1 Update the OpenRegister README (or docs/) with a section explaining audit trail integrity:
  - What hash chaining does (tamper detection).
  - How to verify the chain via the endpoint.
  - Genesis hash constant and what it means.
  - Performance implications (hash computation per write is O(1); verification is O(n) and can be run on-demand).

- [ ] 8.2 Update CHANGELOG.md with an entry for this feature: "Add SHA-256 hash chaining to audit trail entries for tamper detection."

- [ ] 8.3 Add inline code comments (one short line per non-obvious detail) explaining canonical JSON, hash computation, and serialization requirements.

## 9. Quality Assurance

- [ ] 9.1 Run full PHPUnit test suite with coverage target ≥95% for `AuditTrailHashService` and `AuditTrailVerificationService`.
- [ ] 9.2 Run linting (`php-cs-fixer`, `phpstan`) on all new/modified files and fix any violations.
- [ ] 9.3 Manual QA: create several objects via the UI, generate audit entries, call the verification endpoint (via curl or Postman), and verify the response is correct.
- [ ] 9.4 Backwards compatibility: verify that pre-migration audit entries (with null hashes) continue to work correctly and are skipped during verification, not causing errors.

## 10. Deployment & Rollout

- [ ] 10.1 Ensure the migration is applied before the code is deployed (standard database migration flow).
- [ ] 10.2 On deployment, the verification endpoint is available at `/api/audit-trails/verify`; document the endpoint in release notes for auditors.
- [ ] 10.3 Consider running a full chain verification on production post-deployment to establish a baseline and confirm no existing entries have been tampered with.

## Deduplication Check

- **AuditTrailService** — existing service, extended (not duplicated). Uses the existing pattern for service injection and transaction handling.
- **Database transaction support** — leverages existing `IDBConnection` and Nextcloud's transaction API (no custom isolation logic).
- **Hash function** — uses PHP's built-in `hash()` function (no custom crypto libraries).
- **No overlap with ObjectService, RegisterService, or other existing capabilities** — this change is audit-specific and does not duplicate or conflict with any existing service.
