## 1. Storage

- [ ] 1.1 Add `flow_run`, `flow_node`, `flow_step` (nullable) plus a `flow_run` index to `oc_openregister_audit_trails` in a new `lib/Migration/Version1Date*.php`; verify `occ migrations:execute` applies and the columns exist on MySQL, PostgreSQL and SQLite
- [ ] 1.2 Add the three fields to `AuditTrail` with getters/setters and `addType()` registration; verify a hydrated row round-trips the values through the mapper

## 2. Ambient run context

- [ ] 2.1 Add `lib/Service/Flow/FlowRunContext.php` holding a STACK of `(runUuid, nodeId, sequence)` frames with `push()`, `pop()` and `current()`; verify unit tests cover empty, single and nested frames
- [ ] 2.2 Push in `RegistryStepDispatcher::dispatch()` before the step and pop in a `finally`; verify a test asserts the context is empty after a step that THROWS, not only after one that succeeds
- [ ] 2.3 Verify the leak direction end to end: run a flow, then perform a non-flow object write in the same process, and assert the resulting audit row's three columns are null — this test must fail if the `finally` is removed
- [ ] 2.4 Verify the two cross-run isolation cases: a sub-flow restores its parent frame (its writes name the sub-flow, the parent's next step names the parent), and two runs advanced sequentially by one `FlowRunWorker` process each attribute to their own run uuid

## 3. Stamping

- [ ] 3.1 Read `FlowRunContext::current()` in `AuditTrailMapper::buildAuditTrail()` and stamp the three fields; verify BOTH `createAuditTrail()` and the batched `insertAuditTrails()` produce stamped rows from the one change
- [ ] 3.2 Apply the same stamp in `createAuditTrailEntry()` and `createToolInvocationEntry()`; verify a retention entry written during a run is attributed, and that a write made by a leaf app called from inside a node is stamped identically to the node's own write without that app referencing any flow API

## 4. Hash chain (ADR-003 Rule 4)

- [ ] 4.1 Add the three keys to `AuditTrail::jsonSerialize()` and move `GENESIS_SEED` to `openregister-genesis-v2`; verify a freshly seeded chain verifies end to end under v2
- [ ] 4.2 Add `AuditCanonicalV1` — a frozen private copy of the v1 key list and canonicalisation rules, marked never-to-be-updated; verify it reproduces the stored hash of a row sealed before this change
- [ ] 4.3 Verify tampering with `flow_run`, `flow_node` or `flow_step` on a sealed row makes `verifyChain()` report a break at that row

## 5. Verify-then-rechain migration

- [ ] 5.1 Add the repair step (pre-verify against `AuditCanonicalV1` → persist verdict as a v1-sealed `audit.rechain.preverify` row → `rechainAll()` under v2) AND register it in `appinfo/info.xml` in the same commit; verify `occ maintenance:repair` runs it and the registration is present
- [ ] 5.2 Verify the pre-check discriminates: seed a chain, tamper with one row, run the migration, and assert the persisted verdict names that row — the test must fail if the pre-check is stubbed to return valid
- [ ] 5.3 Verify the migration's three safety properties: it refuses to start when it cannot persist its verdict, it is resumable and idempotent when interrupted mid-re-seal, and a chain containing retention tombstones re-seals with those rows carried forward as tombstones rather than reported as breaks

## 6. Read surfaces

- [ ] 6.1 Add `GET /api/flow-runs/{uuid}/objects` returning objects grouped by node with action and step, reusing the visibility rule `FlowRunController::resume()` applies; verify a caller who may not read the run is refused without learning the run exists, a suspended run reports what it has touched so far, and a run that wrote nothing returns an empty collection rather than an error
- [ ] 6.2 Add a `flowRun` filter to the audit-trail query and expose the three fields in the audit-trail serialisation; verify filtering returns only that run's rows and a pruned run's rows still read

## 7. Frontend

- [ ] 7.1 Show both directions — the objects a run touched on the run detail (grouped by node, joined to the existing step history) and the runs that touched an object in its sidebar; verify a step that touched nothing still renders with its status and timing, and a pruned run renders its recorded identifiers instead of failing

## 8. Quality

- [ ] 8.1 Run `composer check:strict` and `npm run lint`, and fix any pre-existing findings touched by these files
- [ ] 8.2 Mutation-check the two guards that fail silently — the `finally` pop (2.3) and the pre-verify discrimination (5.2): disable each, confirm the suite goes red, restore, confirm the source is byte-identical

**Acceptance criteria**

- Every audit row written during a run names the run, node and step; every row written outside one names none.
- A write by an app that has never heard of flows is attributed identically to a node's own write.
- `verifyChain()` returns `valid: true` over the full table after the migration, and `valid: false` when any attribution value is altered.
- The pre-migration verdict is readable after the re-seal, and states where the chain stood before it.
- No `@spec` tag is missing on a changed method; `@e2e exclude` reasons are carried on the backend-only scenarios.
- i18n: new frontend strings go through `t()`; no hardcoded user-facing text.
