# Proposal: audit-hash-chain

`kind: feature` per ADR-032 — cryptographic tamper detection on audit trail entries.

## Summary

Add SHA-256 hash chaining to all audit trail entries in OpenRegister, enabling auditors to
detect tampering. Each entry's hash chains to the previous entry's hash (or a genesis hash for
the first entry), forming a tamper-evident chain. A new verification endpoint allows auditors
to verify the integrity of the entire hash chain and identify any broken links. Writes are
serialized to prevent race conditions.

## Motivation

Audit trails are critical for compliance and forensics. If an audit entry is modified after
creation (e.g., via a database injection), the tampering may go undetected. Hash chaining
makes any modification immediately detectable: changing an entry breaks the chain for all
subsequent entries. The verification endpoint gives auditors a programmatic way to validate
the chain's integrity without manual inspection.

## Affected Projects

- [x] Project: `openregister` — new audit trail hash chaining backend (database migration,
  hash computation, verification endpoint, serialized writes), audit UI updates to show hash
  verification status.

## Scope

### In Scope

- Database migration to add `hash` (VARCHAR 64) and `previous_hash` (VARCHAR 64) columns to
  the audit trails table with an index on `hash`.
- Hash computation logic: SHA-256(`previous_hash` + canonical JSON of entry data), excluding
  hash fields themselves.
- Genesis hash for the first entry: SHA-256("openregister-genesis-v1").
- New verification endpoint `GET /api/audit-trails/verify` with optional `from` and `to` range
  parameters, returning verification status and the first broken entry ID if tampering is
  detected.
- Serialized audit trail writes (via database locking or atomic transactions) to prevent
  concurrent entries from both referencing the same previous hash.
- Handling of pre-migration entries (entries with null hashes are skipped during verification).

### Out of Scope

- Automatic tampering alerts or webhooks.
- Historical backfill of hashes for existing entries (handled via migration).
- UI visualization of hash chains (auditor dashboard integration deferred).
- Export of audit trails with hash proofs.

## Impact

- **Tamper Detection.** Audit trails become tamper-evident — any modification breaks the chain,
  making forensic investigations more reliable.
- **Compliance.** Supports stronger audit logging requirements (e.g., ISO 27001, SOC 2).
- **Performance.** Hash computation is O(1) per write; verification is O(n) but can be run
  on-demand via the endpoint. Serialization may slightly reduce write concurrency during
  peak audit traffic.
- **Database.** Two new nullable columns + one index. Existing entries continue to work with
  null values; new entries all have hashes.
