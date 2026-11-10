---
kind: fix
depends_on: []
adr: openspec/architecture/adr-003-immutable-hash-chained-audit-trail.md
---

## Why

OpenRegister advertises a tamper-evident, SHA-256 hash-chained audit trail
(ADR-003). The verification side is fully built — `AuditHashService::verifyChain()`
(`lib/Service/AuditHashService.php:174-265`) walks the chain and reports breaks —
but the **write side never populates it**. The only two insert paths,
`AuditTrailMapper::createAuditTrail()` (`lib/Db/AuditTrailMapper.php:341-458`) and
`createAuditTrailEntry()` (`:1477-1514`), never call `computeHash()`/`getLastHash()`
nor `setHash()`/`setPreviousHash()`, so every audit row is inserted with
`hash = NULL`.

Because `verifyChain()` deliberately *skips* null-hash rows
(`skippedNullHashes++`, `:209-212`) instead of failing, a table that is 100%
null-hash reports `valid: true` with `entriesVerified: 0`. The compliance
feature reports "intact" while providing **zero** tamper-evidence. This is a
false security guarantee for Archiefwet / NEN-7510 / GDPR Art. 30 consumers.

Two adjacent gaps compound it:

- `LogService::deleteLog()` / `deleteLogs()` (`lib/Service/LogService.php:371-448`)
  call `auditTrailMapper->delete()` directly with no admin guard, contradicting
  the HTTP-405 immutability enforcement in `AuditTrailController`. Currently
  dead (no callers), but a live immutability bypass waiting to be wired.
- `AuthorizationAuditService` records RBAC/authorization changes only via
  `logger->info()`, never to the audit trail — so "who changed access rules"
  is not durable, tamper-evident evidence.

## What Changes

- Populate `hash` and `previousHash` on every audit row at insert time in both
  `createAuditTrail()` and `createAuditTrailEntry()`, using
  `AuditHashService::getLastHash()` + `computeHash()` before `insert()`.
- Make `verifyChain()` distinguish "legacy pre-hash rows" (a bounded, dated
  backfill window) from "unexpected null hash after the cutover" — a null hash
  on a row created after the fix is a chain break, not a silent skip.
- Provide a one-off migration/repair to backfill hashes for existing rows in
  insertion order, sealing the historical segment.
- Gate or remove `LogService::deleteLog()`/`deleteLogs()`: require admin + emit
  an "immutability override" audit entry, matching `clearAll()`.
- Persist authorization-config changes via `AuditTrailMapper::createAuditTrailEntry()`
  in addition to logging.

## Impact

- Affected: `lib/Db/AuditTrailMapper.php`, `lib/Service/AuditHashService.php`,
  `lib/Service/LogService.php`, `lib/Service/AuthorizationAuditService.php`,
  one new `lib/Migration/` or `lib/Repair/` backfill step.
- Consumers reading `verifyChain()` results get real signal; no API shape change.
- Risk: the backfill must run once, deterministically, in insertion order —
  a wrong order permanently mis-seals the historical segment.
