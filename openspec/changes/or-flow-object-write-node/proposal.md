---
kind: code
---

## Why

The graph flow engine cannot write an object. Its entire built-in palette —
`FilterNode`, `LoopNode`, `MergeNode`, `RouterNode`, `SetFieldsNode`,
`StopNode`, `SubFlowNode`, `SwitchNode`, `WaitNode`
(`lib/Listener/FlowNodeRegistrationListener.php`) — transforms items and
persists nothing. Object writes exist only in the older schema-attached
declarative engine (`x-openregister-lifecycle` and friends), which is bound to
"an object changed" and cannot be reached from a graph step.

That makes the flows-first programme (ADR-065: the OR flow engine is the
fleet's ONE flow engine) structurally impossible to finish. The fleet decision
of 2026-07-27 — "porting apps onto the control plane creates no code, just
flows" — assumes a flow can materialise data. Today it cannot:

- The hydra-console chain's `hydra-cache` register can only be fed by
  OpenConnector synchronizations, the single remaining zero-code write path. A
  flow cannot maintain it, and cannot prune it when a source drops a record.
- A triage agentflow can call an agent node, get a verdict, and then has
  nowhere to put it — the verdict cannot be recorded on the `finding` object it
  is about.
- Every app the programme moves off bespoke services still needs one PHP
  service for the write, which is exactly the code the programme exists to
  delete.

A second, wider gap surfaced while designing the node. `ObjectService` has no
usable partial-write path. `saveObject()` is PUT-semantic — a property absent
from the payload is written as null — and `patchObject()`, which exists by
name, is a facade that casts its identifier to `int` (so uuids degrade),
accepts `_rbac` / `_multitenancy` and forwards neither, and takes no acting
user. Every caller that wants "change these two fields" therefore reimplements
fetch-merge-save, and the ones that forget have silently nulled live data. The
flow node needs that path; so does everyone else.

## What Changes

- **New built-in node `openregister.object-write`**, registered through
  `RegisterFlowNodesEvent` like every other built-in. Config: `register` +
  `schema` (slug or uuid), `operation` (`create` | `update` | `upsert` |
  `delete`), a `match` block of one or more property/value pairs for
  `update` / `upsert` / `delete`, and a `fields` map whose values support
  `{{dotted.path}}` templating against the item's `json`.
- **A real `ObjectService::patchObject()`.** The PUT-semantics protection moves
  out of the node and into the core service as PATCH semantics: provided keys
  win, omitted keys are preserved, an explicit `null` clears, nested objects
  merge recursively and arrays replace wholesale. It takes `?IUser
  $currentUser` for attribution and register/schema scope, matching
  `saveObject()`'s parameter vocabulary, and it resolves uuids instead of
  casting them to `int`. This is a core service addition, not node-local
  plumbing — the node is simply its first correct caller.
- **Executes per item.** The data channel is an n8n-shaped item LIST
  (`FlowItems`); one input item produces one output item carrying the saved (or
  deleted) object, with `pairedItem` provenance preserved.
- **Writes as the run's owner.** Saves go through `saveObject()` /
  `patchObject()` with `currentUser` set from the run's `triggeredBy`; deletes
  go through `deleteObject()`, which gains the same explicit acting-user
  parameter because it currently resolves the permission subject from the
  session and a flow run has none. RBAC, audit trail and multitenancy apply
  unchanged. There is no `_rbac: false` escape hatch on this node.
- **Fails closed with no owner.** When `context.triggeredBy` is absent — the
  known `FlowMcpToolProvider::runFlow()` gap, ConductionNL/openregister#2158 —
  the node raises an error. It never writes anonymously or as a system user.
- **Delete ships in v1, behind four guards.** An explicit `match` is mandatory
  (no wildcard, no empty match, nothing that could template-to-all); the match
  must resolve to exactly one object (ambiguity fails naming the count, zero is
  an error by default and a no-op only under an explicit `onNoMatch: skip`);
  the configuration must carry `confirmDelete: true` so deletion cannot be
  reached by mistyping an enum value; and the removal goes through the ordinary
  `deleteObject()` path, keeping RBAC, audit trail, soft-delete, append-only
  and archival-immutability semantics intact. No hard-delete option is exposed.
- **Composite match keys.** `match` takes one or more property/value pairs,
  ANDed. Real consumers key on more than `sourceId` — a tenant plus a source
  id, a register plus an external reference — and a single-property match would
  push them into ambiguity, which under the delete guards means a hard failure.
- **A per-step write cap.** `maxWrites` bounds how many writes one step
  execution may perform, defaulting to 1000 and configurable per step and
  instance-wide. Exceeding it is an ordinary step failure honouring `onError`;
  it is never a silent truncation.
- **Explicit item-level errors.** A failed write throws so the engine's
  `onError` policy (`stop` / `continue` / `dead_letter`) decides. It never
  returns an empty or hollow item — the `HermiqAgentNode`
  swallow-to-empty-string pattern (`catch (Throwable) { $answer = ''; }`) is
  the named anti-pattern.
- **Validation at save time.** `validateConfig()` throws on missing
  register / schema / operation, on an unknown operation, on `update` /
  `upsert` / `delete` without a resolvable match, on `delete` without
  `confirmDelete: true`, and on a non-positive `maxWrites`. An unresolvable
  register or schema at execution time is an error, not a skipped item.

**Non-goals (v1, named so they are not assumed):**

- No hard delete, no purge, no `_retentionSweep`. `delete` means the ordinary
  soft-delete; emptying the trash stays an administrative action.
- No bulk / batch write beyond per-item iteration. `saveObjects()` and
  `deleteObjects()` are not used.
- No bypass flags of any kind (`_rbac`, `_multitenancy`, `silent`) exposed to
  flow authors, and no `runAsSystem()` wrapping.
- No expression-language matching. `match` is equality on named properties;
  a query DSL here would make the delete guard unauditable.
- No migration of existing `patchObject()` callers onto the new capabilities.
  The contract is a superset of today's behaviour; adoption is opportunistic.

## Capabilities

### New Capabilities

- `flow-object-write-node`: a built-in graph node that creates, updates,
  upserts or deletes an OpenRegister object per item, as the run's owner,
  through the normal `ObjectService` path — with PATCH-semantic updates,
  guarded deletion, composite matching, a per-step write cap, fail-closed
  attribution, explicit error propagation and save-time config validation.
  The capability also owns the completed `ObjectService::patchObject()`
  contract, which is a core service surface the node consumes rather than a
  detail of the node.

### Modified Capabilities

<!-- None as OpenSpec capabilities. `flow-nodes`, `flow-engine` and `flow-runs`
     are still unarchived changes (openspec/changes/or-flow-nodes,
     or-flow-engine, or-flow-runs); this change adds a node through their
     existing contracts and changes no requirement of theirs. The
     `patchObject()` / `deleteObject()` widening touches `ObjectService`, whose
     CRUD facade methods carry `@spec exclude` today and belong to no
     capability — REQ-OWN-013 gives `patchObject()` its first spec owner. -->

## Impact

**New code**

- `lib/Service/Flow/Nodes/ObjectWriteNode.php` — the node.

**Modified code**

- `lib/Service/ObjectService.php` — `patchObject()` completed into a real
  PATCH-semantic, attributable, scoped write path (REQ-OWN-013);
  `deleteObject()` widened with an explicit acting-user parameter so a
  sessionless caller can be attributed (REQ-OWN-003).
- `lib/Listener/FlowNodeRegistrationListener.php` — one more constructor
  dependency and one more `registerNode()` call.

**Consumed contracts (unchanged)**

- `OCA\OpenRegister\Service\Flow\IFlowNode` — the node contract.
- `OCA\OpenRegister\Service\Flow\FlowItems` — item shape and `pairedItem`.
- `OCA\OpenRegister\Service\ObjectService::saveObject()` — already accepts
  `?IUser $currentUser`, so no signature change is needed there.
- `FlowEngine::ON_ERROR_*` — the per-step error policy the node throws into.
- `OCP\IAppConfig` — reads the instance-level default write cap, the same way
  `FlowScheduleService` reads its register and schema names.

**Blast radius of the service change**

`patchObject()` has a small existing caller set and today's behaviour is a
proper subset of the new contract (numeric id, session attribution, shallow
merge on a payload with no explicit nulls). `deleteObject()` gains an optional
parameter defaulting to today's behaviour. Both are therefore additive at the
call site, but both are core write paths and are treated as such: full unit
coverage of the merge rules, and a `composer check:strict` plus
opencatalogi / softwarecatalog regression pass before merge.

**Related defects / follow-ups**

- ConductionNL/openregister#2158 — `FlowMcpToolProvider::runFlow()` queues
  without `user:`, so MCP-triggered runs have a null `triggeredBy`. This node
  fails closed on such runs; fixing #2158 is what makes them usable, and is
  out of scope here.
- ConductionNL/openregister#1638 — the unscoped-delete cross-table defect. The
  node always passes register and schema to `deleteObject()`, using its scoped
  signature, so it cannot reproduce it.
- Migrating existing fetch-merge-save call sites in this app and in leaf apps
  onto `patchObject()` is a follow-up, one caller at a time.

**Interlock with `openconnector-flow-nodes`**

That sibling change settled its open question in favour of
`openconnector.synchronization-run` emitting one item per synchronised object
rather than a single summary item. A synchronisation feeding an object-write
step therefore turns one trigger into one write per synchronised record. The
write cap of REQ-OWN-015 is what keeps that pairing bounded, and is the reason
the cap moved from "candidate follow-up" into v1.

**First consumers**

- hydra-console: flow-materialised `hydra-cache` maintenance — upsert on
  harvest, guarded delete when a source retires a record — and triage results
  written onto `finding` objects.
- Every app the flows-first programme moves off a bespoke write service.
