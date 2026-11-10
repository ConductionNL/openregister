## 1. Wire the hash chain on insert

- [ ] 1.1 In `AuditTrailMapper::createAuditTrail()` (`lib/Db/AuditTrailMapper.php:341-458`), before `insert()`, fetch the chain head via `AuditHashService::getLastHash()`, set `previousHash`, compute `hash` via `computeHash()`, set it on the entity.
- [ ] 1.2 Do the same in `createAuditTrailEntry()` (`:1477-1514`).
- [ ] 1.3 Ensure hash computation excludes `hash`/`previousHash` from the canonicalised payload (confirm `AuditHashService` already does; add a test).
- [ ] 1.4 Guard against concurrent inserts racing on the chain head (serialise via a row lock or a single-writer path) so two simultaneous audit writes cannot both read the same `previousHash`.

## 2. Make verification fail-closed after cutover

- [ ] 2.1 Record a cutover marker (migration timestamp / first-hashed id). In `verifyChain()`, treat null-hash rows *before* the marker as legacy-skipped and null-hash rows *after* it as a chain break (`valid: false`).

## 3. Backfill historical rows

- [ ] 3.1 Add a `lib/Repair/` or `lib/Migration/` step that walks existing audit rows in insertion order and seals each with `previousHash`/`hash`, idempotently (skip already-hashed rows). Records the cutover marker.

## 4. Close the immutability bypasses

- [ ] 4.1 In `LogService::deleteLog()`/`deleteLogs()` (`lib/Service/LogService.php:371-448`): either remove them, or require admin + write an "immutability override" audit entry before deletion, matching `clearAll()`.
- [ ] 4.2 In `AuthorizationAuditService`, persist `logSchemaAuthorizationChange`/`logRegisterAuthorizationChange`/`logRoleDefinitionChange` via `AuditTrailMapper::createAuditTrailEntry()` in addition to the existing `logger->info()`.

## 5. Verification

- [ ] 5.1 Unit test: insert N audit rows, corrupt one payload in the DB, assert `verifyChain()` reports `valid: false` at that index.
- [ ] 5.2 Unit test: post-cutover null-hash row → `valid: false`; pre-cutover null-hash row → skipped, still valid.
- [ ] 5.3 `composer check:strict` passes; no regression to opencatalogi/softwarecatalog audit consumers.

## Acceptance criteria

- Every new audit row carries a non-null `hash` and correct `previousHash`.
- `verifyChain()` detects a tampered row (returns `valid: false` at the break).
- A post-cutover null-hash row fails verification.
- `LogService` deletion helpers cannot silently bypass immutability.
- Authorization-config changes appear as durable audit rows.
