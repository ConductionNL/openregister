# ADR-003: Immutable, hash-chained audit trail

**Status**: accepted (documents the decision as implemented; see the write-path
gap in `openspec/changes/fix-audit-hash-chain-write-path`)

**Date**: 2026-07-07

## Context

OpenRegister is the system of record for governed data used by compliance-bound
consumers (Archiefwet, NEN-7510, GDPR Art. 30). Those consumers need
*tamper-evidence*: proof that an audit entry was not altered or deleted after
the fact, independent of Nextcloud's own activity log (which is mutable by
anyone with DB or file access).

OpenRegister implements this as a SHA-256 **hash chain** over audit-trail rows
(`lib/Service/AuditHashService.php`): each entry's `hash` is
`sha256(previousHash + canonicalJSON(entry))`, seeded by a fixed genesis
constant `GENESIS_SEED = 'openregister-genesis-v1'`
(`lib/Service/AuditHashService.php:52`). The `hash`/`previousHash` fields are
themselves excluded from the canonicalised payload. `verifyChain()` walks the
chain and reports the first break.

ADR-022 (company-wide) *references* this audit trail as an abstraction that
consuming apps must reuse rather than reimplement, but the mechanism — genesis
seed, canonicalisation rules, verification semantics, and the immutability
guarantee — has no OpenRegister-side ADR. That gap has real consequences: the
write path was shipped without wiring the hash fields (fixed separately), and
`LogService::deleteLog()` exists as an un-gated deletion method that would
violate the immutability contract if ever wired.

## Decision

**The audit trail is an append-only, SHA-256 hash-chained log. Entries are never
updated or deleted through any public surface. Tamper-evidence is a first-class
guarantee, not a best-effort side effect.**

### Numbered rules

#### Rule 1 — Every audit row is hash-chained at insert time

The two insert paths — `AuditTrailMapper::createAuditTrail()` and
`createAuditTrailEntry()` — MUST compute `previousHash` from the current chain
head (`AuditHashService::getLastHash()`) and set the row's `hash`
(`computeHash()`) before persisting. A row inserted with a null hash is a
defect: `verifyChain()` treats null-hash rows as *skipped*, so an all-null table
reports `valid: true` with `entriesVerified: 0` — a false guarantee.

#### Rule 2 — No update or delete surface for audit entries

Controllers MUST reject mutation of audit entries.
`AuditTrailController::destroy()`/`destroyMultiple()` returning HTTP 405 is the
canonical behaviour. Service-layer deletion helpers (`LogService::deleteLog()`,
`deleteLogs()`) MUST NOT exist without an explicit admin gate *and* a recorded
"immutability override" audit entry — matching `clearAll()`'s `requireAdmin()`.

#### Rule 3 — Governance-config changes are audited durably, not only logged

Authorization/RBAC changes on registers and schemas
(`AuthorizationAuditService`) MUST be persisted as audit-trail rows, not only
written to `ILogger`. Application logs are rotated and mutable; "who changed
access rules and when" is compliance evidence and belongs in the hash chain.

#### Rule 4 — Canonicalisation is stable and versioned

The canonical JSON form and genesis seed are part of the chain's identity.
Changing either breaks verification of pre-existing rows, so any change is a
migration event with a new seed version, never an in-place edit.

## Consequences

- (+) Independent, portable tamper-evidence for compliance consumers.
- (+) A single verification routine (`verifyChain()`) any auditor can run.
- (−) Insert paths carry hashing cost and MUST be the only way to write audit
  rows; bulk/back-door inserts silently break the chain.
- Follow-up: the write-path wiring and the `LogService` deletion methods are
  addressed in `openspec/changes/fix-audit-hash-chain-write-path`.
