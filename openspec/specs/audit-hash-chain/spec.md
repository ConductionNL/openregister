---
status: in-progress
---

# audit-hash-chain Specification

---
status: implemented
---

## Purpose

@e2e exclude cryptographic backend service — covered by PHPUnit
Cryptographic SHA-256 hash chaining on audit trail entries with genesis hash, verification endpoint, and tamper detection reporting. Each entry's hash chains to the previous entry, making any tampering detectable by auditors.

## OpenSpec changes

- `audit-seal-backlog-repair` (in-progress) — builds the bulk seal pass that the
  fail-soft write path has assumed since `harden-audit-seal-concurrency`: a
  bounded, resumable, idempotent driver (`occ` command + capped background job)
  that drains unsealed rows in ascending chain order, one lock per window rather
  than one per row, never re-hashing an already-sealed row. Adds a partial index
  for the backlog cursor and a cutover marker so `verifyChain()` stops reporting
  `valid: true` over rows it silently skipped.

- `flow-object-attribution` (active) — the canonical form gains the three flow
  attribution fields so a row's run/node/step is hash-covered, the genesis seed
  moves to `openregister-genesis-v2`, and a seed change is defined as a
  verify-then-rechain migration: the outgoing canonicaliser is frozen in the
  codebase and used for the pre-check, whose verdict is persisted before the
  re-seal makes the prior state underivable.

## Requirements

### Requirement: Every audit trail entry MUST include a SHA-256 hash chained to the previous entry
Each audit trail entry MUST contain a `hash` field computed as `SHA-256(previous_hash + canonical_json(entry_data))`. The `previous_hash` field links to the preceding entry's hash, forming a tamper-evident chain.

#### Scenario: First audit entry uses genesis hash
- **WHEN** the first audit trail entry is created in the system (no previous entries exist)
- **THEN** the entry MUST have `previousHash` set to `SHA-256("openregister-genesis-v2")`
- **AND** the entry MUST have `hash` set to `SHA-256(genesis_hash + canonical_json(entry_data))`

> ⚠️ **This scenario said `-v1` until 2026-09-18, and the code did not.**
> `AuditHashService::GENESIS_SEED` is `openregister-genesis-v2`, and the
> development instance's own chain agrees: its first sealed row carries
> `ce429ddf6fb0601d34d2a40bb8758c79610f4d59cd9342aa9c5c1e3ac46e4fce`, which is
> SHA-256 of the v2 seed (read from `oc_openregister_audit_trails` on
> 2026-09-18). The move is `flow-object-attribution` task 4.1, **which is
> unticked**, so the seed went ahead of both its own task and this text.
>
> The requirement is corrected to the shipped value rather than the code to the
> text, because the chains that exist are the ones that matter and they are
> already v2. Two consequences are recorded rather than left to be met:
>
> 1. **An instance seeded before the move still carries a v1 first row.** The
>    genesis only enters the hash of row 1 — every later row chains to its
>    predecessor — so such an instance verifies cleanly from row 2 and reports
>    row 1 as broken. That is a one-row false positive on old instances, not a
>    chain-wide failure, and `flow-object-attribution` defines the fix as a
>    verify-then-rechain migration.
> 2. **A seed change must never be a silent edit.** That change's own design
>    says the outgoing canonicaliser is frozen and used for a pre-check whose
>    verdict is persisted before the re-seal makes the prior state underivable.
>    This one was not; saying so here is the only place a reader of the spec
>    would find out.

#### Scenario: Subsequent entries chain to previous hash
- **WHEN** audit trail entry N is created after entry N-1 with hash `abc123...`
- **THEN** entry N MUST have `previousHash` set to `abc123...`
- **AND** entry N MUST have `hash` set to `SHA-256("abc123..." + canonical_json(entry_data_N))`

#### Scenario: Canonical JSON excludes hash fields
- **WHEN** computing the hash for an audit trail entry
- **THEN** the canonical JSON MUST include all entry fields except `hash` and `previousHash`
- **AND** the JSON MUST use sorted keys and no whitespace (compact canonical form)

### Requirement: The system MUST provide a hash chain verification endpoint
An API endpoint MUST allow auditors to verify the integrity of the hash chain.

#### Scenario: Verify full chain integrity
- **WHEN** a GET request is made to `/api/audit-trails/verify`
- **THEN** the system MUST iterate all audit trail entries in order
- **AND** recompute each hash and compare to the stored hash
- **AND** return a JSON response with `{"valid": true, "entriesVerified": <count>}`

#### Scenario: Verify chain with range parameters
- **WHEN** a GET request is made to `/api/audit-trails/verify?from=100&to=200`
- **THEN** the system MUST verify only entries with IDs between 100 and 200 (inclusive)
- **AND** return `{"valid": true, "entriesVerified": 101, "range": {"from": 100, "to": 200}}`

#### Scenario: Detect tampered entry in chain
- **WHEN** an entry in the chain has been modified after creation (stored hash does not match recomputed hash)
- **THEN** the verification endpoint MUST return `{"valid": false, "brokenAt": <entry_id>, "entriesVerified": <count>}`
- **AND** the `brokenAt` field MUST identify the first entry where the chain breaks

#### Scenario: Handle entries without hashes (pre-migration)
- **WHEN** the verification encounters entries with null `hash` values (created before hash chaining was enabled)
- **THEN** those entries MUST be skipped in the verification
- **AND** the response MUST include `"skippedNullHashes": <count>`

### Requirement: Hash chain writes MUST be serialized to prevent race conditions

Concurrent audit trail inserts MUST NOT produce broken hash chains. All seal
passes MUST serialize their critical section (predecessor-hash read through hash UPDATE) under one
exclusive advisory lock with the well-known key `openregister/audit-seal`
(`OCP\Lock\ILockingProvider`), so no two passes can interleave a predecessor
read with another pass's write. Lock acquisition MUST be bounded (short
retry window, no indefinite wait) and fail-soft: when the lock cannot be
acquired the pass MUST log a warning and leave the rows unsealed — a later
seal pass chains them, and unsealed rows are skipped by both verification
and predecessor lookup — rather than blocking the audit write path. The
lock MUST always be released, including when the seal pass throws.

#### Scenario: Two simultaneous audit writes

- **WHEN** two audit trail entries are created at the same moment
- **THEN** both entries MUST be correctly chained (each referencing the correct previous hash)
- **AND** no two entries MUST share the same `previousHash` value (except the genesis hash for the first entry)

#### Scenario: Seal passes run under the shared exclusive advisory lock

- **WHEN** a seal pass seals audit rows
- **THEN** the exclusive lock `openregister/audit-seal` is acquired before the predecessor hash is read
- **AND** the lock is released after the hash UPDATE completes

#### Scenario: Lock contention is fail-soft

- **WHEN** the seal lock cannot be acquired within the bounded retry window
- **THEN** a warning is logged and the rows are left unsealed
- **AND** no database read or write is performed by the abandoned pass
- **AND** the audit rows themselves remain persisted (sealing is fail-soft, the insert is not)

#### Scenario: Lock released when the seal pass throws

- **WHEN** the seal pass fails mid-flight (e.g. a database error)
- **THEN** the advisory lock is released regardless
- **AND** the failure propagates to the caller's existing fail-soft handling

### Requirement: A database migration MUST add hash columns
The migration MUST add `hash` and `previous_hash` columns to the audit trails table.

#### Scenario: Migration adds nullable hash columns
- **WHEN** the migration runs on an existing database with audit trail entries
- **THEN** columns `hash` (VARCHAR 64) and `previous_hash` (VARCHAR 64) MUST be added
- **AND** existing entries MUST retain null values for both columns
- **AND** an index MUST be created on the `hash` column for verification queries

### Requirement: Audit rows are hash-chained by the seal sweep

Every audit-trail row SHALL be sealed into the SHA-256 hash chain by
`AuditSealJob` through `AuditHashService::sealUnsealed()`, the only sealer. The
insert paths SHALL NOT seal: with one sealer, unsealed rows are always a
contiguous tail, which is what rules out two rows chaining onto one predecessor.
The sweep SHALL walk the tail in id order, set each row's `previousHash` to the
`hash` of the row before it and compute `hash` (`computeHash()`). The hashed
payload SHALL exclude the `hash` and `previousHash` fields themselves. Fields
inside the hash SHALL be set before the row is inserted.

#### Scenario: A sealed audit row carries a hash

- **WHEN** an object write produces an audit-trail row and the next sweep has run
- **THEN** the persisted row has a non-null `hash`
- **AND** its `previousHash` equals the `hash` of the immediately preceding row
  (or the genesis seed for the first hashed row)

#### Scenario: An insert does not seal

- **WHEN** an audit-trail row has just been inserted
- **THEN** its `hash` is null until the next sweep
- **AND** `verifyChain()` treats the unsealed tail as not yet vouched for, not as tampering

#### Scenario: Tampering is detected

- **WHEN** a persisted audit row's payload is altered directly in the database
- **AND** `AuditHashService::verifyChain()` is run
- **THEN** it returns `valid: false`
- **AND** it identifies the index at which the chain breaks

#### Scenario: Post-cutover null hash is a break, not a skip

- **WHEN** a row created after the hash-chain cutover marker has a null `hash`
- **AND** `verifyChain()` is run
- **THEN** it returns `valid: false`
- **AND** it does NOT silently count the row as `skippedNullHashes`

#### Scenario: Legacy pre-cutover rows are sealed by backfill

- **WHEN** the backfill repair step runs against pre-existing null-hash rows
- **THEN** each row is sealed with `previousHash`/`hash` in insertion order
- **AND** re-running the step is a no-op (already-hashed rows are skipped)

### Requirement: Audit entries cannot be deleted through the service layer without an audited admin override

Service-layer helpers that delete audit rows SHALL NOT bypass the immutability
guarantee. `LogService::deleteLog()`/`deleteLogs()` SHALL either not exist or
SHALL require an admin caller and record an "immutability override" audit entry
before deletion, matching `LogService::clearAll()`.

#### Scenario: Non-admin cannot delete audit entries via the service

- **WHEN** a non-admin context invokes an audit-deletion service helper
- **THEN** the call is rejected
- **AND** no audit row is deleted

### Requirement: Authorization-configuration changes are durably audited

Changes to register/schema authorization and role definitions SHALL be persisted
as audit-trail rows via `AuditTrailMapper::createAuditTrailEntry()`, not recorded
only in the application log.

#### Scenario: Changing a schema's authorization writes an audit row

- **WHEN** a schema's `authorization` block is modified
- **THEN** a hash-chained audit-trail row describing the change (actor, target,
  before/after) is persisted
- **AND** the row participates in `verifyChain()`

### Requirement: Seal passes MUST chain from the nearest prior SEALED row

The predecessor lookup for a seal pass MUST skip unsealed rows (`hash` NULL
or empty — fail-soft leftovers or pre-migration entries) and MUST return the
nearest PRIOR row with a non-empty `hash` as the `previousHash` source,
falling back to the genesis hash when no sealed predecessor exists.
This mirrors exactly how `verifyChain()` walks the chain (null-hash rows are
skipped and counted as `skippedNullHashes` while the last SEALED hash is
carried forward). Chaining from the immediately-prior row regardless of its
seal state would fall back to genesis whenever that row is unsealed,
producing a PERMANENT false chain break at that link.

#### Scenario: Unsealed predecessor does not break the chain

- **GIVEN** row N is an unsealed fail-soft leftover and row N-1 is sealed with hash `h`
- **WHEN** row N+1 is sealed
- **THEN** row N+1's `previousHash` is `h` (the nearest sealed predecessor), not the genesis hash
- **AND** `verifyChain()` over rows N-1..N+1 returns `valid: true` with `skippedNullHashes: 1`

#### Scenario: Predecessor lookup filters unsealed rows in the query

- **WHEN** the predecessor hash for a seal pass is read
- **THEN** the lookup query excludes rows with `hash` NULL or empty
- **AND** returns the highest-id remaining row below the sealed row's id

### Requirement: A bounded, resumable seal pass SHALL drain unsealed audit rows in chain order

The app SHALL provide a single seal-backlog driver, exposed both as an `occ`
command and as a scheduled background job, that finds audit-trail rows with a
null or empty `hash` and seals them through `AuditHashService::sealRows()`.

The driver SHALL process rows in **ascending id order**, SHALL cut work into
windows bounded by **both** a maximum unsealed-row count and a maximum id span,
and SHALL commit each window independently so an interrupted pass loses at most
one window. The driver SHALL NOT run two windows concurrently.

#### Scenario: A backlog is drained in ascending id order

- **GIVEN** audit rows with null `hash` exist across a wide id range
- **WHEN** the seal pass runs to completion
- **THEN** every previously unsealed row has a non-null `hash`
- **AND** each sealed row's `previousHash` equals the `hash` of the nearest
  preceding sealed row (or the genesis seed)
- **AND** `verifyChain()` returns `valid: true` over the drained range

#### Scenario: An interrupted pass resumes without gaps or rework

- **GIVEN** a seal pass is interrupted after committing some windows
- **WHEN** the pass is started again
- **THEN** it resumes from the first still-unsealed row
- **AND** rows sealed by the earlier run are not read for hashing again
- **AND** the resulting chain verifies identically to an uninterrupted run

#### Scenario: A window is bounded by span as well as by row count

- **GIVEN** unsealed rows are sparsely interleaved among sealed rows
- **WHEN** the driver cuts a window
- **THEN** the window covers at most the configured maximum id span
- **AND** the number of full rows loaded into memory is bounded by the number of
  unsealed rows in the window, not by the window's id span

#### Scenario: Re-running a fully drained backlog is a no-op

- **GIVEN** no audit rows have a null or empty `hash`
- **WHEN** the seal pass runs
- **THEN** it seals zero rows
- **AND** it issues no `UPDATE` against the audit-trail table

### Requirement: The seal pass SHALL NEVER re-hash an already-sealed row

A row whose `hash` is non-null and non-empty SHALL contribute its stored `hash`
as the next chain link and SHALL NOT be recomputed, rewritten, or included in any
`UPDATE` issued by the seal pass — even when it falls inside a window's id range.

Recomputing a sealed row would overwrite a valid link and produce a false tamper
alarm on the next verification, which is the exact failure the chain exists to
detect.

#### Scenario: Sealed rows interleaved in a window are left untouched

- **GIVEN** a window's id range contains both sealed and unsealed rows
- **WHEN** the seal pass processes that window
- **THEN** the `UPDATE` affects only the unsealed rows
- **AND** every sealed row's `hash` and `previousHash` are byte-identical before
  and after the pass

### Requirement: The scheduled seal job SHALL be hard-bounded per invocation

The background job SHALL cap the work it performs in a single invocation by both
a maximum number of rows and a maximum wall-clock duration, and SHALL exit
cleanly when either cap is reached, leaving the remaining backlog for the next
run.

The job SHALL NOT hold the seal lock across windows, and SHALL abandon the
current window — not the whole pass — when the lock cannot be acquired, so that
foreground writes are never starved.

#### Scenario: A large backlog does not produce a long-running job

- **GIVEN** a backlog far larger than one invocation's cap
- **WHEN** the job runs
- **THEN** it stops at the cap and exits cleanly
- **AND** the next invocation continues from where it stopped

#### Scenario: Lock contention yields instead of blocking writers

- **GIVEN** the seal lock is held by a foreground write
- **WHEN** the job attempts a window
- **THEN** it abandons that window without error
- **AND** the pass continues at the next invocation
- **AND** no foreground insert is blocked waiting on the job


### Requirement: Verification SHALL distinguish legacy unsealed rows from unsealed rows written after the cutover

`verifyChain()` SHALL classify a row with a null or empty `hash` using a
persisted cutover marker recorded when the hash-chain migration ran (on a
fresh install, whose migrations run schema-only, by the `PrepareAuditSealing`
install repair step, which records it before any audit row is written).

Rows created **before** the marker SHALL continue to be counted as
`skippedNullHashes` without failing the chain. Rows created **after** the marker
SHALL fail verification: `verifyChain()` SHALL return `valid: false` and SHALL
NOT count them as skipped.

The result SHALL additionally report the number of post-cutover unsealed rows so
a backlog is visible to callers rather than hidden behind a passing boolean.

This replaces the current behaviour, in which every null hash is skipped
unconditionally and the chain reports `valid: true` regardless of how many rows
were never sealed.

#### Scenario: A post-cutover unsealed row breaks the chain

- **GIVEN** an audit row created after the cutover marker has a null `hash`
- **WHEN** `verifyChain()` runs
- **THEN** it returns `valid: false`
- **AND** the row is not counted in `skippedNullHashes`
- **AND** the post-cutover unsealed count is non-zero in the result

#### Scenario: Legacy pre-cutover rows still do not break the chain

- **GIVEN** audit rows created before the cutover marker have null hashes
- **AND** every post-cutover row is sealed
- **WHEN** `verifyChain()` runs
- **THEN** it returns `valid: true`
- **AND** the legacy rows are counted in `skippedNullHashes`

#### Scenario: A drained backlog restores a passing chain

- **GIVEN** `verifyChain()` reports `valid: false` because of post-cutover
  unsealed rows
- **WHEN** the seal pass drains the backlog
- **AND** `verifyChain()` is run again
- **THEN** it returns `valid: true`
- **AND** the post-cutover unsealed count is zero
