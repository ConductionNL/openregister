## 1. Database Migration

- [x] 1.1 Create migration `Version1Date20260322100000.php` adding `hash` (VARCHAR 64, nullable) and `previous_hash` (VARCHAR 64, nullable) columns to `openregister_audit_trails` table
- [x] 1.2 Add index on `hash` column and index on `processing_activity_id` column in the same migration

## 2. AuditTrail Entity Update

- [x] 2.1 Add `hash` and `previousHash` properties to `AuditTrail.php` entity with `addType` calls in constructor
- [x] 2.2 Add `hash` and `previousHash` to `jsonSerialize()` output and update `@method` annotations
- [x] 2.3 Update `@psalm-return` type annotation to include the new fields

## 3. AuditHashService

- [x] 3.1 Create `lib/Service/AuditHashService.php` with `computeHash(AuditTrail $entry, string $previousHash): string` method using SHA-256
- [x] 3.2 Implement `getCanonicalJson(AuditTrail $entry): string` that serializes entry data excluding `hash` and `previousHash` with sorted keys
- [x] 3.3 Implement `getGenesisHash(): string` returning `SHA-256("openregister-genesis-v1")`
- [x] 3.4 Implement `getLastHash(): string` that queries the most recent audit trail entry's hash, returning genesis hash if none exist
- [x] 3.5 Implement `verifyChain(?int $from, ?int $to): array` that iterates entries and validates hash chain integrity

## 4. AuditTrailMapper Integration

- [x] 4.1 Override `insert()` in `AuditTrailMapper` to call `AuditHashService` for hash computation before persisting
- [x] 4.2 Wrap hash chain write in a database transaction with row locking to prevent race conditions

## 5. Immutability Enforcement

- [x] 5.1 Add `update()` method to `AuditTrailController` returning HTTP 405 with error message
- [x] 5.2 Add `destroy()` method to `AuditTrailController` returning HTTP 405 with error message
- [x] 5.3 Register PUT/PATCH/DELETE routes for `/api/audit-trails/{id}` pointing to the 405 handlers

## 6. Verification Endpoint

- [x] 6.1 Add `verify()` action to `AuditTrailController` accepting optional `from` and `to` query parameters
- [x] 6.2 Register GET route `/api/audit-trails/verify` in `routes.php`
- [x] 6.3 Return JSON response with `valid`, `entriesVerified`, `brokenAt` (if invalid), and `skippedNullHashes` fields

## 7. Verwerkingsregister API

- [x] 7.1 Add `verwerkingsregister()` action to `AuditTrailController` that queries distinct processing activities with counts and date ranges
- [x] 7.2 Add `inzageverzoek()` action that searches audit entries by identifier in the `changed` JSON field, grouped by schema
- [x] 7.3 Register GET routes `/api/audit-trails/verwerkingsregister` and `/api/audit-trails/inzageverzoek` in `routes.php`

## 8. Export Endpoint

- [x] 8.1 Add `export()` action to `AuditTrailController` supporting JSON and CSV formats with date range filtering
- [x] 8.2 Register GET route `/api/audit-trails/export` in `routes.php`
- [x] 8.3 Implement CSV serialization with headers and JSON-stringified `changed` field

## 9. Tests

- [x] 9.1 Write unit tests for `AuditHashService`: hash computation, genesis hash, canonical JSON, chain verification
- [x] 9.2 Write unit tests for immutability enforcement (405 responses on update/delete)
- [x] 9.3 Write unit tests for verwerkingsregister and inzageverzoek endpoints
- [x] 9.4 Write unit test for export endpoint (JSON and CSV formats)

## 10. Quality and Regression

- [x] 10.1 Run `composer check:strict` and fix any PHPCS, PHPMD, Psalm, or PHPStan issues
- [x] 10.2 Verify opencatalogi and softwarecatalog still function correctly with the updated AuditTrail entity
