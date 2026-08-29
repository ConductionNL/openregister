## Context

See `proposal.md — Why`. The constraints that shape the approach:

- **The audit trail is hash-chained (ADR-003).** `AuditHashService::getCanonicalJson()` hashes `AuditTrail::jsonSerialize()` minus `hash`/`previousHash`. Adding a key to that serialisation changes the canonical form of every row ever written. This is the reason `purgedAt` was deliberately left OUT of the hash, with the residual weakness documented in `AuditHashService` (~line 1074).
- **Both audit insert paths share one builder.** `createAuditTrail()` and the batched `insertAuditTrails()` both go through `buildAuditTrail()`. A stamp applied to the insert methods rather than the builder would silently miss every batched write.
- **`FlowRun` already carries `subjectUuid`** — the trigger's object. It is not a record of what the run touched and must not be confused with one.
- **`AuditHashService` already has what a re-seal needs**: `rechainAll()` (batched), `sealRows()`, an advisory lock serialising seal passes, and tombstone-aware verification.
- **A run is not one process.** `FlowRunWorker` advances several runs in sequence, and a suspended run resumes in a later process entirely. Anything process-global must be scoped per step, not per request.

## Goals / Non-Goals

**Goals:**
- Attribution that captures writes made by code that has never heard of flows.
- One place that decides whether a write is attributed, so the answer cannot differ per call site.
- A seed migration that cannot silently convert a tampered chain into a verified one.

**Non-Goals:**
- Re-attributing history. Rows written before this change stay unattributed; there is no inference pass.
- Attribution for non-OpenRegister side effects (an email sent, a webhook posted). Those are `FlowRunStep.output`'s job.
- The dossiq flow itself, and the human-task model — companion change `case-flow-human-steps`.
- Making `purgedAt` hash-covered. It stays out; this change does not reopen it.

## Decisions

### D1 — Ambient context, not a parameter

A `FlowRunContext` service holds the current `(runUuid, nodeId, sequence)`. `RegistryStepDispatcher::dispatch()` pushes before the step and pops in a `finally`.

*Why:* the whole point is to catch writes the writing code does not know are part of a flow — a leaf app called by a node, a cascade, a lifecycle hook. Threading a parameter through `ObjectService` would attribute only what a node explicitly passed, which is the same blind spot as the link-table option that was rejected.

*Alternative rejected:* passing attribution down `SaveObject`/`DeleteObject` signatures. Wider blast radius (every caller of a changed signature — and a new argument breaks positional callers one slot later), and still blind to leaf-app writes.

*Shape:* a **stack**, not a scalar. A sub-flow node dispatches a nested run; popping must restore the parent's frame rather than clear to empty, or the parent's remaining steps in that same dispatch go unattributed.

### D2 — Stamp in `buildAuditTrail()`

One read of the context, in the shared builder, so single and batched inserts are identical. `createAuditTrailEntry()` (archival/retention entries) and `createToolInvocationEntry()` (MCP) get the same treatment for consistency.

*Consequence, accepted:* `GetObject` writes a `read` audit row, so reads performed during a run are attributed too. This is more truthful than filtering them out — the run did touch the object. The read surface exposes `action`, so a caller wanting writes only can say so.

### D3 — Inside the hash, with a v2 seed and a full re-chain

Per the decision recorded on this change: the three fields join the canonical JSON, `GENESIS_SEED` becomes `openregister-genesis-v2`, and `rechainAll()` re-seals the table. Attribution is therefore tamper-evident — re-pointing a row at a different run breaks verification.

*Alternatives rejected:* excluding the keys from the canonical form (the `purgedAt` precedent) would ship with zero risk to the existing chain but leaves attribution unprotected; emitting the keys only when set would preserve old rows byte-identically but introduces an omit-when-null rule in the canonicaliser.

### D4 — The pre-migration check uses a FROZEN v1 canonicaliser

This is the decision that makes D3 safe, and it is the one that is easy to get wrong.

The migration must verify the chain **before** re-sealing it. If that verification canonicalises with the new code, it includes the new keys, every pre-existing row mismatches, and the verdict is "broken" whether the chain was healthy or compromised. The re-chain then overwrites the evidence either way. The check would run, produce output, and be worthless.

So the migration carries `AuditCanonicalV1` — a frozen, private copy of the v1 key list and canonicalisation rules, never updated again. The pre-check verifies against it, and its verdict is written as an audit row (action `audit.rechain.preverify`, sealed under v1 as the last v1 row) recording `valid`, `entriesVerified`, `brokenAt`, and the seed version moved from/to.

**Test the check by breaking the thing it checks**: seed a chain, tamper with one row, run the migration, and assert the recorded verdict names that row. A test that only seeds a healthy chain passes identically when the pre-check is a stub returning `valid: true`.

### D5 — A stamp, not a foreign key

`flow_run` is a plain string column with no referential link. `FlowRunRetentionJob` prunes runs; a FK would either block pruning or cascade into immutable audit rows. Reading an object's history must not fail because the run has been pruned — the identifiers are the historical fact, and resolving them to a live run is best-effort.

### D6 — Index for the run direction

`(flow_run)` alone is enough for "what did this run touch"; the object direction is already served by the existing `object_uuid` index. No composite index until a query needs one — ADR-009 treats speculative indexes on the audit hot path as a cost, not a hedge.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Stamping attribution onto audit rows | **imperative** | Engine and persistence internals in OpenRegister core. There is no app schema here and no `x-openregister-*` extension that expresses "attribute the ambient run onto the audit row" — this is the mechanism such declarations would be recorded BY. |
| Canonicalisation and re-seal | **imperative** | Cryptographic integrity operation, migration-time. |
| Read surfaces (run → objects, object → runs) | **imperative** | A query filter and a controller endpoint over a native (non-OR-object) table; `flow_runs` and `audit_trails` are native tables by design (`flow-storage`). |

No part of this change introduces or modifies an OpenRegister schema, so it declares no lifecycle, aggregation, calculation, notification, relation or widget.

## Seed Data (ADR-001)

**None.** This change adds no OpenRegister schemas and no register objects, so there is no `_registers.json` entry to generate. Attribution rows are produced by running a flow, not by seeding. The demonstrable data for this change is a flow run in the companion dossiq change; a seeded audit row would be a fabricated record of something that never happened, which is precisely what an immutable audit trail must not contain.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| **A context left in place attributes later, unrelated writes to a finished run.** Silent: the rows are well-formed and plausible. | Push/pop in `finally`. The test that matters asserts the LEAK direction — perform a non-flow write after a run and require the columns to be null. A happy-path test passes with the leak present. |
| **The re-chain re-blesses history.** Once re-sealed under v2, any tampering that predates the migration is undetectable, permanently. | Accepted deliberately (D3). D4's pre-check plus its persisted verdict is the compensating control: the state of the chain at migration time survives the migration that erases the ability to re-derive it. |
| **Rollback is not available.** Reverting the code does not restore v1 hashes; the v1 values are overwritten in place. | Treat as a one-way migration. Require a database backup before the release, state it in the upgrade note, and make the migration refuse to start if it cannot write its pre-verify verdict. |
| **Mixed code versions during a rolling deploy** would seal some rows under v1 and some under v2, interleaved. | The existing advisory seal lock serialises seal passes; the migration runs to completion within one release step, and the seed version is read from one constant rather than per-row. |
| **A long re-chain on a large audit table** blocks the upgrade. | `rechainAll()` already batches; the migration is resumable and idempotent (spec scenario) so an interrupted upgrade continues rather than restarts. |
| **Attribution widens what an audit row discloses** — it names a run and node a reader might not otherwise see. | Attribution is returned only on rows the caller may already read; the run→objects endpoint reuses the run visibility rule that `resume()` applies rather than inventing a second one. A validator and an executor each owning a copy of the same rule is how they drift apart. |
| **`FlowRunWorker` advances several runs per process**, so a leak crosses runs, not just steps. | Same `finally`; plus a test that runs two runs in sequence in one process and asserts the second's writes carry the second's run uuid. |

## Migration Plan

1. **Schema migration** — add `flow_run`, `flow_node`, `flow_step` to `oc_openregister_audit_trails`, plus the `flow_run` index. Nullable; no backfill.
2. **Repair step, registered in `appinfo/info.xml` in the same commit.** (A repair step written but never registered does nothing and reports nothing — four apps in the fleet were found in that state.) In order: pre-verify against `AuditCanonicalV1` → persist the verdict as a v1-sealed audit row → `rechainAll()` under the v2 seed.
3. **Code cutover** — `jsonSerialize()` emits the three keys; `GENESIS_SEED` is `openregister-genesis-v2`.
4. **Post-check** — `verifyChain()` under v2 returns `valid: true` over the full table.

**Rollback:** none for step 2/3 (see Risks). Steps 1 and 4 are reversible; the re-seal is not.

## Open Questions

- Whether the run→objects endpoint should page. Deferred: it does not change the specs, the approach or the task breakdown, and the shape of real runs will answer it. The first consumer (a case flow with ~12 nodes) is far below any page boundary.
