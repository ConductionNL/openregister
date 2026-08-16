# Content Versioning & Audit Trail

## Overview

OpenRegister provides a complete content versioning and immutable audit trail system for all register objects. Every mutation is recorded as a versioned snapshot in a tamper-evident, hash-chained log with a minimum 10-year retention guarantee. The system satisfies Dutch government compliance requirements including Archiefwet 1995, BIO, and AVG/GDPR Article 30.

**Tender demand**: 56% of analyzed government tenders require immutable audit trail capabilities.

## Content Versioning

### Semantic Versioning

Every object carries a `_version` field following semantic versioning (`MAJOR.MINOR.PATCH`):

| Event | Version Change |
|-------|---------------|
| Object created | `1.0.0` |
| Normal save/update | PATCH increment (`1.0.1`, `1.0.2`, …) |
| Draft promoted to published | MINOR increment (`1.1.0`) |
| Schema-breaking change or explicit user action | MAJOR increment (`2.0.0`) |
| Bulk update (each object independently) | PATCH increment per object |

### Version History

Every version is stored as a full snapshot in the audit trail. Users can:

- List all versions of an object: `GET /api/objects/{register}/{schema}/{id}/audit`
- Compare any two versions with field-level diffs
- Roll back to any previous version: `POST /api/objects/{register}/{schema}/{id}/revert/{version}`

### Draft / Published Lifecycle

Objects support a draft/published lifecycle for editorial workflows:

- Objects start in draft state
- Draft changes are version-tracked but not visible to read-only users
- Publishing promotes a draft to the active version (MINOR increment)
- The previous published version remains in history for comparison

## Immutable Audit Trail

### Hash Chaining

Every audit trail entry is cryptographically chained to the previous entry using SHA-256:

```
entry_hash = SHA-256(entry_json + previous_entry_hash)
```

This tamper-evident chain allows independent verification: if any entry is modified or deleted, the chain breaks at that point. The chain can be verified via:

```
GET /apps/openregister/api/audit-trails/verify?from={entryId}&to={entryId}
```

`from` and `to` are audit entry **IDs**, not timestamps, and both are optional — omit them to walk the whole trail. The endpoint is admin-only. It returns:

```json
{ "valid": true, "entriesVerified": 309036, "brokenAt": null, "skippedNullHashes": 0 }
```

`brokenAt` is the ID of the first entry whose recomputed hash disagreed with the hash stored against it; verification stops there, so `entriesVerified` counts only the entries confirmed *before* the break.

### Sealing

Chaining an entry is called **sealing**, and it is a separate step from writing it. An entry is inserted first and sealed immediately afterwards, because its hash depends on the entry before it — so sealing has to be serialised, while writing does not.

Serialisation uses an exclusive advisory lock (`openregister/audit-seal`). Without it, two concurrent seal passes can each read the same predecessor and then write, leaving many entries chained onto **one** predecessor. That is a fan-out rather than a chain, and verification correctly reports it as broken even though nobody tampered with anything.

Sealing is **fail-soft**. If the lock cannot be acquired within three attempts, the write still succeeds and the entry is simply left unsealed — an audit entry with no hash. Refusing the write instead would mean a lock contention could lose audit data, which is worse than a gap.

That makes a sweeper necessary rather than optional:

| | |
|---|---|
| **Job** | `OCA\OpenRegister\BackgroundJob\AuditSealJob` |
| **Interval** | every 5 minutes |
| **Work per tick** | up to 10 passes × 500 entries |
| **Order** | oldest unsealed first, so it is resumable by construction |

A backlog after heavy write activity is normal and drains on its own. A backlog that never shrinks is not: it means sealing is failing on the write path *and* in the sweep, and the job logs a warning saying so.

**An unsealed entry is not a tampered entry.** It is an entry the chain cannot vouch for either way. Verification skips unsealed entries and carries the last sealed hash forward, so gaps weaken the guarantee without producing false alarms.

Seal coverage is shown under **Administration settings → Open Register → Log integrity**, alongside a button that runs the full verification on demand. The two numbers answer different questions and the page keeps them apart deliberately: coverage says whether the sweeper is keeping up, and says nothing about whether the stored hashes still agree with their rows. Only verification answers that.

### Repairing a broken chain

If verification reports `valid: false`, read the entry at `brokenAt` before doing anything else. There are two causes and they are not equally serious:

1. The entry was altered after it was written — the case the chain exists to expose.
2. It was sealed by a historical concurrent pass and chained onto the wrong predecessor — a bookkeeping fault, with the entry itself intact.

Only once you have established which, repair with:

```
occ openregister:rechain-audit-trail
```

This recomputes every hash from genesis, in ID order, under the seal lock — so the result is a single chain by construction. It also seals any entry that never had a hash, so one run repairs both failure modes.

It is a command rather than a background job on purpose. Rewriting stored audit hashes is precisely the event the chain is built to make suspicious, so it must be asked for by a person; it prompts for confirmation unless given `--force`, supports `--dry-run`, and logs its start and completion at warning level so the rewrite is itself part of the record.

### Audit Entry Structure

Every create, update, and delete produces an audit trail entry containing:

| Field | Description |
|-------|-------------|
| `id` | Auto-incrementing sequence number |
| `timestamp` | Server-side UTC timestamp (not client-provided) |
| `user` | Nextcloud user ID of the actor |
| `action` | `create`, `update`, `delete`, `read` (for sensitive data) |
| `objectUuid` | UUID of the affected object |
| `schemaUuid` | UUID of the schema |
| `registerUuid` | UUID of the register |
| `changed` | JSON diff: `{ "fieldName": { "old": "...", "new": "..." } }` |
| `snapshot` | Full object state at this point in time (for `create`) |
| `hash` | SHA-256 hash of this entry |
| `previousHash` | Hash of the preceding entry in the chain |
| `ipAddress` | Client IP address |
| `sessionId` | Nextcloud session or API token identifier |

### Retention

- Audit trail entries have a minimum 10-year retention requirement
- Entries cannot be modified or deleted via the API
- Background jobs enforce the minimum retention period before any physical removal
- Audit trail is included in e-Depot SIP packages for permanent archival

## Deletion Audit Trail

The deletion audit trail documents the full lifecycle of every deletion:

### Soft Delete

All API deletions are soft by default:

- `_deleted` field is set with `deletedBy`, `deletedAt`, and optional `reason`
- Object remains in the database and is excluded from normal queries
- Retrievable via `?_deleted=true` or the trash endpoint
- Deletion audit entry is created

### Restore

- Soft-deleted objects can be restored: `POST /api/objects/{register}/{schema}/{id}/restore`
- Restore creates an audit entry with action `restore`
- Version is incremented on restore

### Cascade Delete Tracking

When an object is deleted and other objects reference it:

- Configured `onDelete: cascade` references are deleted transitively
- Each cascaded deletion gets its own audit entry with `reason: cascade_from:{uuid}`
- Cascade chains are tracked to prevent circular reference loops

### Physical Purge

Physical purge (permanent removal) happens only:

1. After the configured `archive.purgeAfter` retention period has elapsed
2. Via the destruction workflow with multi-step approval (see [Archiving](archiving.md))
3. A purge audit entry is written before physical deletion

## GDPR Compliance

For GDPR (AVG) compliance, the audit trail supports:

- **Right of access (Article 15)**: Export all audit entries for a specific user
- **Right to erasure (Article 17)**: Pseudonymization of user identifiers in audit entries (the data itself may be deleted, but the audit record of deletion is retained)
- **Article 30 Register**: Processing activities are linked to objects via the verwerkingsregister integration
- **Data minimization**: Read auditing only activates for properties marked as sensitive (`sensitivity: high`)

## API

```
GET  /api/objects/{register}/{schema}/{id}/audit       List all versions/audit entries for an object
GET  /api/objects/{register}/{schema}/{id}/audit/{n}   Get a specific audit entry
POST /api/objects/{register}/{schema}/{id}/revert/{v}  Revert object to version v
GET  /api/audit/verify                                 Verify hash chain integrity
GET  /api/audit/export                                 Export audit trail (CSV/JSON)
```

## Standards

| Standard | Role |
|----------|------|
| Archiefwet 1995 | 10-year minimum audit retention |
| BIO (Baseline Informatiebeveiliging Overheid) | Audit logging requirements for government systems |
| AVG / GDPR Article 30 | Processing activity documentation |
| NEN-ISO 16175-1:2020 | Records management principles |

## Related Features

- [Object Storage & Lifecycle](object-storage.md) — objects being versioned and audited
- [Archiving & Records Management](archiving.md) — long-term retention and e-Depot transfer
- [Access Control (RBAC)](access-control.md) — audit entries record access decisions
- [Event-Driven Architecture](event-driven-architecture.md) — audit entries are created from lifecycle events
