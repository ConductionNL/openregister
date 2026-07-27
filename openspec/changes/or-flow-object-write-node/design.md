## Context

`FlowEngine` walks a Petri net; each transition dispatches to a node resolved
from `FlowNodeRegistry`; nodes implement `IFlowNode` and exchange an n8n-shaped
item LIST (`FlowItems`: `json` / `binary` / `pairedItem`). OpenRegister
contributes nine built-ins via `FlowNodeRegistrationListener`:

| node | what it does | persists? |
|---|---|---|
| `openregister.set-fields` | reshape each item's record | no |
| `openregister.filter` | drop items | no |
| `openregister.switch` | route on a condition | no |
| `openregister.router` | tag items to an output branch | no |
| `openregister.merge` | join branches | no |
| `openregister.loop` | batch iteration | no |
| `openregister.wait` | suspend and resume | no |
| `openregister.stop` | end the run | no |
| `openregister.sub-flow` | run another flow | no |

Nothing in that column says yes. The engine can compute anything and remember
nothing.

Object writes today live in the older schema-attached declarative engine
(`x-openregister-lifecycle` and the other `x-openregister-*` blocks). That
engine is bound to "this object changed" — it reacts to a subject, it cannot be
placed as a step in a graph, and it cannot be reached from a flow that started
from a schedule, a webhook or an MCP call.

The constraint that makes this urgent is the flows-first programme (ADR-065:
the OR flow engine is the fleet's ONE flow engine; PO decision 2026-07-27:
"porting apps onto the control plane creates no code, just flows"). That
programme is only true if a flow can materialise data. Two first consumers make
the gap concrete:

- **hydra-console `hydra-cache`** — maintained today only by OpenConnector
  synchronizations, the last remaining zero-code write path. A flow cannot keep
  it fresh, and cannot prune an entry whose source retired it.
- **triage agentflow** — an agent node produces a verdict, and there is no step
  that can record it on the `finding` object it is about.

Four facts about the surrounding code shape this design rather than being
incidental. Each was verified against the tree, not assumed:

1. `ObjectService::saveObject()` already takes `?IUser $currentUser`
   (`lib/Service/ObjectService.php:1130`) — so correct attribution for saves
   needs no signature change, only discipline in the caller.
2. `ObjectService::deleteObject()` does **not**
   (`lib/Service/ObjectService.php:1809`). It passes `userId: null` into
   `checkPermission()`, which resolves the subject from `IUserSession`. A flow
   run is a sessionless caller, so a delete node cannot be attributed without
   widening that signature.
3. `ObjectService::patchObject()` **already exists by name**
   (`lib/Service/ObjectService.php:4204`) — but as a facade that cannot carry
   the semantics this change needs. See D3.
4. `FlowMcpToolProvider::runFlow()` calls `$this->runner->queue(...)` with no
   `user:` argument, so MCP-triggered runs carry a null `triggeredBy`
   (ConductionNL/openregister#2158). Any node that writes must have an answer
   for that, and "write as system" is the wrong one.

**Product owner review, 2026-07-27.** All four questions this change had
deferred were reviewed and answered against the provisional positions recorded
here. Delete ships in v1 with a designed guard set (D7) rather than as a
follow-up change; the PUT-semantics protection moves into `ObjectService` as a
PATCH-semantic method (D3) rather than living in the node; matching accepts
composite keys now (D8) rather than starting single-property; and the per-step
write cap ships in v1 (D9) rather than being a candidate. The confirmed-as-is
decisions — `onMissing` defaulting to omit-the-key, fail-closed on ownerless
runs, explicit item-level errors — are unchanged and are recorded in D2, D4 and
D5.

## Goals / Non-Goals

**Goals:**

- One built-in node, `openregister.object-write`, that creates / updates /
  upserts / deletes an OpenRegister object per item.
- Writes are indistinguishable from API writes by the same user: same RBAC,
  same audit trail, same multitenancy, same schema validation, same
  soft-delete.
- A PATCH-semantic write path on `ObjectService` that any caller can use, so
  the PUT-semantics trap is closed once rather than per consumer.
- Destructive operations that cannot be reached by accident and cannot be
  expressed unboundedly.
- Safe defaults against the two known fleet defect classes — PUT-semantic
  field loss, and swallow-to-empty success.
- Enough templating to build a payload and a match key from upstream items
  without a code node.

**Non-Goals:**

- No hard delete / purge path. `delete` is the ordinary soft-delete; emptying
  the trash is an administrative action, not a flow step.
- No bulk / batch path. `saveObjects()` and `deleteObjects()` are deliberately
  not used.
- No expression language extension. Field mapping and match values use
  `{{dotted.path}}` substitution; anything richer belongs to `FlowExpression`
  (JSONLogic) or to an upstream `set-fields` step. Matching in particular stays
  equality-only.
- No new permission model, no service-account concept, no `_rbac` escape hatch.
- No migration of existing `patchObject()` callers. The new contract is a
  superset; adoption is opportunistic.
- No UI work. The node renders from the palette catalogue like every other
  built-in; a bespoke config panel is a separate concern.

## Decisions

### D1 — A built-in node, not a contributed one and not a new engine hook

`ObjectWriteNode implements IFlowNode`, registered in
`FlowNodeRegistrationListener` alongside the other nine.

*Alternatives considered.* (a) Extend `FlowStepDispatcher` with a special
"write" step type — rejected: a dispatcher with a type switch in it is half an
engine, which is the exact failure `or-flow-nodes` was written to end.
(b) Ship it from a consuming app — rejected: writing an OpenRegister object is
OpenRegister's own capability, and every consumer needs it; contributing it
from hydra-console would make the fleet's most basic step optional.

*Why it matters that it is a built-in:* the palette is what an author sees. A
flow engine whose palette has no write step teaches authors that flows are for
notifications.

### D2 — Attribution is `context.triggeredBy`, passed explicitly, with no fallback

The node reads `context['triggeredBy']`, resolves it to an `IUser`, and passes
it to every service call — `saveObject(currentUser: …)`,
`patchObject(currentUser: …)` and `deleteObject(currentUser: …)` — with `_rbac`
and `_multitenancy` left at their `true` defaults.

The delete half of that is not free. `deleteObject()` today resolves its
permission subject from the session (`checkPermission(userId: null …)`), which
for a background flow run means "anonymous", and anonymous is default-deny. So
either the node runs deletes inside a faked session, or `deleteObject()` gains
the parameter `saveObject()` already has. The second is obviously right: it is
the same shape, it makes the sessionless-caller case explicit rather than
accidental, and `saveObject()`'s own docblock already states the rule —
"Non-HTTP callers (cron, import pipelines, event listeners) MUST pass an
explicit user to avoid the default-deny fall-through". `deleteObject()` was
simply never brought up to it.

*Alternatives considered.* (a) Write as a system user, or wrap in
`runAsSystem()` — rejected: it makes every flow a privilege-escalation
primitive and produces audit entries that name nobody. `runAsSystem()`'s own
docblock scopes it to app-initiated maintenance whose inputs originate from
shipped data; a flow document is neither. (b) Let the flow declare an owner in
its config — rejected: same escalation, one indirection further away. (c) Fall
back to the schema's owning organisation — rejected: an org is not an actor and
cannot be held to a permission check.

*Fail-closed is the whole decision.* Given #2158, "no owner" is a state that
occurs in production today. The node throws (REQ-OWN-004) with a message that
names the missing attribution. An unattributable row in a register is worse
than a failed run, because the failed run is visible — and an unattributable
*delete* is worse again, because there is no row left to notice.

`SubFlowNode` already reads `($context['triggeredBy'] ?? null)` when queuing a
child run, so owner propagation through sub-flows is existing behaviour, not
something this node has to invent.

### D3 — PATCH semantics belong in `ObjectService`, not in the node

**Reversal of the earlier position.** The first draft put fetch-merge-save
inside the node, explicitly to keep the blast radius at one new file, and
recorded "add a PATCH-semantic method to `ObjectService`" as an attractive
future refactor. The PO review of 2026-07-27 reversed that, and the reversal is
right for a reason the first draft undersold: the PUT-semantics trap is
fleet-wide. `saveObject()` nulls omitted properties, every partial-write caller
must know this, and the ones who did not have already cost live data. A merge
implemented in one node fixes one caller and leaves the trap armed everywhere
else. The mitigation has to sit where the trap sits.

The naming question resolves itself. `patchObject()` **already exists** on
`ObjectService` (line 4204), so this is not a new method with a new convention
to argue about — it is an existing name whose implementation never grew up.
Verified against the file, today's body is:

```php
$existing     = $this->objectMapper->find((int) $objectId);
$merged       = array_merge($existing->getObject(), $data);
$merged['id'] = $objectId;
return $this->saveObject(object: $merged);
```

Four defects in five lines, each of which this change must fix:

1. **`(int) $objectId`.** The parameter is documented as "Object ID or UUID". A
   uuid cast to `int` becomes its leading digits or `0` — so it either misses,
   or resolves to an unrelated row. The uuid half of the contract has never
   worked.
2. **`$_rbac` and `$_multitenancy` are accepted and forwarded nowhere.** A
   caller passing them reads as if it configured something; nothing downstream
   sees the value. A parameter read by nobody is worse than no parameter,
   because the call site lies.
3. **No `?IUser $currentUser`.** So no attribution, and the `@self.folder`
   default-deny fall-through that `saveObject()`'s docblock warns non-HTTP
   callers about applies in full.
4. **`array_merge` is shallow and unscoped.** A partially-provided nested
   object replaces the whole stored one, and the lookup has no register/schema
   scope, so it can reach across magic tables.

The completed contract (REQ-OWN-013): resolve uuid / slug / id against a scoped
mapper lookup; merge with provided-wins, omitted-preserved, explicit-null-
clears, nested-objects-recursive; forward `currentUser`, `_rbac` and
`_multitenancy`; validate and audit exactly as `saveObject()` does.

*Two sub-decisions worth naming.* **Explicit null clears** rather than being
treated as "not provided": without it there is no way to unset a property
through PATCH at all, and JSON Merge Patch (RFC 7386) sets the same precedent,
so the semantics are borrowed rather than invented. **Arrays replace
wholesale** rather than merging element-wise: there is no stable identity to
merge array elements on, and a positional merge corrupts any reordered list —
a defect that surfaces late and looks like data loss.

*Alternatives considered.* (a) Keep the merge in the node, as originally
drafted — rejected above. (b) Add a differently-named method
(`mergeObjectData()`, `partialSave()`) and leave `patchObject()` as it is —
rejected: two methods that both claim to patch, one of them broken, is worse
than one method that works. (c) Fix `patchObject()` in its own chained change
first, per ADR-032, and depend on it — genuinely arguable; see DEFERRED_QUESTIONS.

*Trade-off accepted:* this change now touches a core service, which widens its
review surface from one new file to a hot write path. That is paid for with
full unit coverage of the merge rules and a regression pass over the existing
callers, and it is the correct place for the cost to land.

*Node-side surface:* `update` and the update half of `upsert` call
`patchObject()`. `replace: true` bypasses it and calls `saveObject()` with only
the mapped fields. `replace` defaults to `false` and is meaningless for
`create` and `delete`, where `validateConfig()` rejects it.

### D4 — Throw; never return a hollow item

Every failure path — no owner, unresolvable register/schema, no match for an
`update`, ambiguous match, cap exceeded, permission denied, validation
rejected, store error — throws. The engine's `outcomeForFailedStep()` then
applies the step's `onError` policy (`stop` is the default and also catches an
unknown policy, so a typo fails safe).

*The named anti-pattern:* `hermiq/lib/Flow/HermiqAgentNode.php` catches
`Throwable` and continues with `$answer = ''`, and its template renderer
returns `''` for an unresolved path. The result is a run that reports success
having produced nothing — and downstream, a mandatory field quietly set to the
empty string. `IFlowNode::execute()`'s own docblock already says catching here
"defeats that policy and produces a run that reports success while doing
nothing". This node obeys that.

### D5 — `{{dotted.path}}` substitution, with type preservation and no empty-string fallback

Values in `fields` — and in `match` values — are templated against the item's
`json`. A whole-value template preserves the resolved type (arrays stay
arrays); an inline template stringifies. An unresolved path is governed by
`onMissing`, defaulting to *omit the key*.

*Alternatives considered.* (a) JSONLogic per field via `FlowExpression` —
correct for conditions, verbose for "copy this field"; the two can coexist
later. (b) A code node — explicitly ruled out by `FlowExpression`'s own design
note: running user-authored JavaScript inside the Nextcloud process is not a
trade worth making.

*Why omit and not null:* OpenRegister's validator rejects both `{}` and `null`
for an object property nested inside an array item. The shape it accepts is an
absent key. Emitting `""` — hermiq's behaviour — is worse still, because it
passes validation and writes a wrong value.

*One asymmetry, deliberately.* `onMissing: omit` applies to `fields` only. An
unresolved placeholder in a `match` value always fails the item. Omitting a
field narrows what is written; omitting a match pair **widens what is
matched**, which under `delete` is the difference between removing one object
and removing the wrong one. A guard that gets weaker when its input is missing
is not a guard.

### D6 — Per-item iteration, never `saveObjects()` / `deleteObjects()`

One item in, one write, one item out, `pairedItem` preserved. The bulk path
would collapse many items into one unit of work, making `onError: continue`
meaningless and provenance unrecoverable — and provenance is the thing every
debugging session starts from.

### D7 — Delete ships in v1 behind four independent guards

**Reversal of the earlier position.** The first draft excluded `delete` and
made the exclusion a testable requirement, on the recorded ground that
"destructive writes need their own guard story". The PO review kept the premise
and rejected the conclusion: the guard story is designable now, and a flow
engine that can create data but never retire it produces registers that only
grow. hydra-cache is the immediate proof — a cache that cannot evict is not a
cache.

So the con is answered by designing the guards rather than by deferring. Four,
chosen so that no single mistake reaches a deletion:

| # | Guard | Failure it prevents |
|---|---|---|
| 1 | An explicit `match` is mandatory; no wildcard, no empty match, no filter-less form | Template-to-all. There is no configuration shape that means "delete everything in scope" |
| 2 | The match must resolve to **exactly one** object; >1 fails naming the count, 0 is an error unless `onNoMatch: skip` | Deleting a neighbour because the key was not selective, and mass-deletion by an over-broad match |
| 3 | `confirmDelete: true` must be present and boolean | Reaching deletion by typo. Changing `update` → `delete` alone does not save; a second deliberate key is required |
| 4 | The removal goes through the ordinary `deleteObject()` path with the run owner as explicit actor | Bypassing RBAC, the audit trail, soft-delete, `AppendOnlyException`, `ArchivalImmutableException` and the transferred-object rejection |

Guards 1 and 3 are enforced at **save time** (`validateConfig()`), not run
time. A flow that could delete unboundedly must not be persistable at all —
catching it when the schedule fires at 3am is too late to be called a guard.

*Guard 2's zero-match default is `error`, not `skip`.* "The object I meant to
delete is not there" is far more often a broken flow than an idempotent re-run.
`skip` exists for the genuinely idempotent case and is opt-in, and it emits
`deleted: false` on the output item (REQ-OWN-007) so a skip is never
indistinguishable from a removal in the run log.

*Guard 4 is why there is no hard-delete flag.* `deleteObject()`'s soft-delete
is what makes a mistaken flow recoverable; a `hardDelete: true` key would
remove the last thing standing between a bad match and permanent loss. Purging
soft-deleted objects stays administrative. `_retentionSweep` is likewise never
passed — it exists to let the retention cron bypass archival immutability, and
a flow is not the retention cron.

*Alternatives considered.* (a) A separate `openregister.object-delete` node —
the earlier provisional position. Rejected: the guards are all config-level,
and a separate node duplicates register/schema resolution, owner resolution,
templating, matching and error handling to carry four extra validation rules.
Two nodes also means two palette entries where authors must learn which one
enforces what. (b) An admin-only scope restriction on deletion — rejected: it
restricts *authoring*, not access, and the write itself is already bounded by
the owner's own RBAC (D2). An author who cannot delete through the API cannot
delete through the node. (c) A dry-run / preview mode — attractive, and a
reasonable follow-up, but it is a UI affordance rather than a guard: it changes
what an author sees before saving, not what the engine will permit at run time.

### D8 — Match is a composite of ANDed property/value pairs

**Reversal of the earlier position.** The first draft matched on one property
equalling one templated value, with "widen only on a concrete need" as the
provisional stance. The concrete need arrived with the same review: real
consumers key on more than one property — a tenant plus a source id, a register
plus an external reference — and under a single-property match those keys
collapse into ambiguity, which D7's Guard 2 turns into a hard failure. The
narrow version does not merely inconvenience those consumers; combined with
delete, it makes them unimplementable.

So `match` is a list of pairs, ANDed. Never ORed: an OR would widen the
resolved set, and every consumer of the match (update, upsert, delete) treats a
wider set as an error, so OR is a way of writing configurations that cannot
succeed.

*Explicitly not a query DSL.* No operators, no ranges, no negation, no raw
store filters. Equality on named properties is auditable by reading the config;
an expression language is not, and D7's Guard 1 depends on a human being able
to look at a delete step and see what it can reach.

*Matching runs through the ordinary read path* with the owner's RBAC and
multitenancy applied, so an object the owner cannot see is not a match. That is
what makes the multitenancy scenario in REQ-OWN-003 hold for delete as well as
for update.

### D9 — A per-step write cap, defaulting to 1000

**Reversal of the earlier position.** The first draft listed a per-step write
budget under Risks as "a candidate follow-up". It is in v1 now, and the reason
is an interlock the first draft could not have known about: the sibling change
`openconnector-flow-nodes` settled its own open question — "should
`synchronization-run` emit one item per synchronised object, or one summary
item?" — in favour of **per-object fan-out**.

That changes the risk profile materially. Before, a flow's item count was
roughly what an author had authored. After, a single `synchronization-run` step
hands the next step one item per synchronised record, from a source whose size
the author does not control. `sync → object-write` is the most obvious pipeline
anyone will build with these two changes, and without a cap it is a write
amplifier bounded only by someone else's API.

`FlowEngine::MAX_TRANSITIONS = 1000` bounds the graph walk but says nothing
about per-item fan-out inside one transition, so the existing ceiling does not
cover this.

*The default is 1000*, per step execution. It matches `MAX_TRANSITIONS`'s order
of magnitude so the two ceilings read as a pair, sits far above any
hand-authored fan-out, and is low enough that a runaway is caught in the same
run rather than the next morning. It is configurable per step (`maxWrites`) and
instance-wide through `IAppConfig`, read the same way `FlowScheduleService`
reads its register and schema names — so an administrator can raise it for a
known-large pipeline without editing every flow.

*Exceeding is a failure, never a truncation.* Writing the first N and silently
dropping the rest is precisely the green-but-dead outcome D4 exists to prevent,
and it is worse here than elsewhere: the register ends up holding a partial
dataset that looks complete. The error names the cap and the count reached, and
states that writes already performed were not rolled back — there is no
transaction spanning the step, and pretending otherwise would be a lie an
operator acts on.

## Declarative vs imperative (ADR-031)

ADR-031 says: when OpenRegister can express behaviour as schema metadata,
prefer that over a service class. This change does not contradict it — it is
what makes it reachable from a second direction.

The node is **engine infrastructure**, not business logic. No business rule
lives in `ObjectWriteNode.php`; it contains the mechanics of turning a
configured mapping into an attributed save or an attributed delete. The
business logic lives in the authored flow document — declarative data,
versioned and shareable like any other configuration, exactly as
`x-openregister-lifecycle` blocks are.

The same test applies to the `patchObject()` work, and it passes for the same
reason. A PATCH-semantic write path is not business logic either; it is the
service-layer primitive that declarative callers need in order to exist. ADR-031
is not an argument against service code — it is an argument against business
rules encoded in service code. Every declarative engine rests on primitives, and
the honest reading is that OpenRegister was missing one.

The relationship between the two declarative engines:

- `x-openregister-*` blocks are **subject-bound**: "when this object changes,
  do this to it". Right answer for invariants that belong to the schema.
- Graph flows are **process-bound**: "on this trigger, gather, decide, and
  write or retire — possibly in a different register than the one that
  triggered it".

Before this change, only the first could persist. That made process-shaped
automation land in PHP services by default, which is precisely the outcome
ADR-031 exists to prevent. Adding a write step moves that work from imperative
service code to an authored flow — one new file in OpenRegister, plus one
service method brought up to contract, and no new file in any consuming app.

Delete strengthens this rather than straining it. Retirement rules — "evict a
cache entry when its source retires the record" — are process-bound by nature:
the trigger is an upstream event, not a change to the object being removed. A
subject-bound `x-openregister-*` block cannot express them, so before this
change they had exactly one home: a bespoke PHP service in the consuming app.

Concretely, the win is subtractive: hydra-console does not gain a
`CacheMaintenanceService` or a `CacheEvictionService`; the triage chain does not
gain a `FindingUpdateService`. They gain flow documents.

## Seed Data (ADR-001)

This change introduces no schemas and therefore no persisted seed objects. What
follows is example configuration for docs, tests and the palette's example
gallery. All identifiers are obvious placeholders: the nil UUID
`00000000-0000-0000-0000-000000000000` stands in for every real id, and slugs
carry an `example-` prefix per ADR-001's marker convention.

**Example node configuration — upsert into `hydra-cache` with a composite match:**

```json
{
  "id": "write-cache-entry",
  "type": "openregister.object-write",
  "onError": "stop",
  "config": {
    "register": "example-hydra-cache",
    "schema": "example-cache-entry",
    "operation": "upsert",
    "match": [
      { "property": "sourceId", "value": "{{id}}" },
      { "property": "sourceSystem", "value": "example-source" }
    ],
    "replace": false,
    "onMissing": "omit",
    "maxWrites": 5000,
    "fields": {
      "sourceId": "{{id}}",
      "sourceSystem": "example-source",
      "title": "{{name}}",
      "payload": "{{raw}}"
    }
  }
}
```

Two properties in the match, not one (D8). `sourceId` alone is not unique
across source systems, so a single-property match would resolve to more than
one object and fail as ambiguous. `maxWrites` is raised above the default here
because this step is fed by a synchronisation.

**Example node configuration — a fully guarded delete:**

```json
{
  "id": "evict-retired-entry",
  "type": "openregister.object-write",
  "onError": "stop",
  "config": {
    "register": "example-hydra-cache",
    "schema": "example-cache-entry",
    "operation": "delete",
    "confirmDelete": true,
    "onNoMatch": "skip",
    "match": [
      { "property": "sourceId", "value": "{{retiredId}}" },
      { "property": "sourceSystem", "value": "example-source" }
    ]
  }
}
```

Every guard from D7 is visible in that block: the match is explicit and
composite, `confirmDelete: true` is present and boolean, and `onNoMatch: skip`
is the deliberate opt-in that makes re-running the eviction idempotent. There
is no `fields`, no `replace` and no hard-delete key — `validateConfig()` rejects
the first two here and the third does not exist.

**Example flow — triage verdict recorded on a `finding`:**

```json
{
  "id": "example-triage-flow",
  "uuid": "00000000-0000-0000-0000-000000000000",
  "nodes": [
    { "id": "start", "type": "openregister.set-fields",
      "config": { "set": { "stage": "triage" } } },
    { "id": "assess", "type": "hermiq.agent-step",
      "config": { "agentId": "example-triage-agent", "expectJson": true } },
    { "id": "record", "type": "openregister.object-write",
      "onError": "dead_letter",
      "config": {
        "register": "example-findings",
        "schema": "example-finding",
        "operation": "update",
        "match": [ { "property": "uuid", "value": "{{uuid}}" } ],
        "fields": {
          "triageVerdict": "{{verdict}}",
          "triageRationale": "{{rationale}}",
          "triagedAt": "{{@now}}"
        }
      } }
  ],
  "edges": [
    { "from": "start", "to": "assess" },
    { "from": "assess", "to": "record" }
  ]
}
```

**Example output item after a successful write** (what the next step sees):

```json
{
  "json": {
    "uuid": "00000000-0000-0000-0000-000000000000",
    "register": "example-findings",
    "schema": "example-finding",
    "triageVerdict": "duplicate",
    "triageRationale": "Matches example-finding-0001.",
    "title": "Example finding"
  },
  "binary": {},
  "pairedItem": { "item": 0 }
}
```

Note `title` in that output: it was never in the mapping. It survives because
`update` went through `patchObject()` (D3). That is the seed example whose whole
job is to demonstrate the PUT-semantics guard.

**Example output item after a delete that found nothing** (`onNoMatch: skip`):

```json
{
  "json": {
    "retiredId": "example-0007",
    "deleted": false
  },
  "binary": {},
  "pairedItem": { "item": 0 }
}
```

`deleted: false` is what keeps a skip legible in the run log rather than
indistinguishable from a removal.

**Example failure — a run with no owner** (the #2158 case), as it appears in
the run log:

```json
{
  "transition": "record",
  "status": "failed",
  "error": "This flow run has no owner (triggeredBy); an object write must be attributable."
}
```

**Example failure — the write cap:**

```json
{
  "transition": "write-cache-entry",
  "status": "failed",
  "error": "Step exceeded its write cap of 1000 writes; 1000 writes were performed and were not rolled back."
}
```

## Risks / Trade-offs

- **[The change now touches a core write path]** → Accepted deliberately, and
  the largest single risk here. `patchObject()` and `deleteObject()` are used
  outside the flow engine, so a regression lands far from this change.
  Mitigation: today's `patchObject()` behaviour is a strict subset of the new
  contract for its existing callers; `deleteObject()` gains an optional
  parameter that defaults to current behaviour; full unit coverage of the merge
  rules; and a `composer check:strict` plus opencatalogi / softwarecatalog
  regression pass gates the merge.
- **[Delete in v1 is a destructive capability in an authored document]** →
  Bounded by D7's four guards, two of which are enforced at save time so the
  dangerous shape is not even persistable. Residual exposure is a correctly
  guarded delete with a wrong match value — which soft-delete makes
  recoverable, the audit trail makes attributable, and the owner's RBAC bounds
  to what that user could delete through the API anyway.
- **[`confirmDelete` becomes a reflex an author pastes without reading]** →
  Real, and unfixable by design alone; a second key raises the floor, it does
  not eliminate the failure. It is paired with Guard 2 (exactly-one) precisely
  because acknowledgement fatigue is expected: the guard that catches a
  careless author is the match arity, not the checkbox.
- **[#2158 makes MCP-triggered flows unable to write]** → Correct and
  intentional per REQ-OWN-004. Mitigation is to fix #2158 (pass `user:` when
  queueing), not to loosen this node. The error message names the cause so the
  author is not sent hunting through RBAC config.
- **[The PATCH read costs one fetch per updated item]** → Accepted.
  Correctness over throughput for v1; high-volume ingestion stays on
  OpenConnector synchronizations. Revisit if a real workload shows it.
- **[An author sets `replace: true` without understanding PUT semantics]** →
  The safe default is `false`; `replace` is explicit and named for what it does.
  Documented in the palette description and in the seed examples.
- **[A flow becomes a write amplifier]** → This is why D9 is in v1. The
  `synchronization-run` per-object fan-out decision in `openconnector-flow-nodes`
  makes `sync → object-write` a pipeline whose item count comes from an external
  source. `maxWrites` bounds it and fails loudly.
- **[The cap's default of 1000 is wrong for someone]** → Configurable per step
  and instance-wide, and exceeding it produces an error that names the cap, so
  the fix is discoverable from the failure rather than requiring documentation
  archaeology.
- **[A partial write is left behind when the cap trips]** → Real: there is no
  transaction spanning a step. Named explicitly in the error message rather
  than papered over, because an operator who believes the step was atomic will
  make the wrong recovery decision.
- **[Composite matching invites a query DSL by accretion]** → Held at ANDed
  equality deliberately (D8). Anything conditional belongs in a `switch` edge or
  a `set-fields` step upstream. D7's Guard 1 depends on a delete step being
  readable at a glance.
- **[Templating grows into an expression language by accretion]** → Same
  answer, held at substitution (D5).

## Migration Plan

Additive. No schema change, no database migration, no breaking change to any
existing node.

- **Deploy:** ship the new node class plus its registration, and the
  `ObjectService` changes. Both service changes are backward compatible —
  `patchObject()`'s new contract is a superset of the behaviour its current
  callers rely on, and `deleteObject()`'s new parameter is optional and
  defaults to today's session-resolved behaviour. Existing flows are
  unaffected: nothing references a node type that did not exist.
- **Rollback:** remove the `registerNode()` call. Flows using the node then
  fail to resolve their step type, which is a loud, visible failure rather than
  a silent no-op — the correct behaviour for a rollback that removes a step a
  flow depends on. The `ObjectService` changes need not be reverted with it;
  they stand alone and reverting them would reintroduce the uuid-cast defect.
- **Adoption:** hydra-console's `hydra-cache` upsert flow, its eviction flow and
  the triage flow are the first three consumers and act as the live
  verification of the seed examples above.

## Open Questions

None blocking. The four questions this change previously deferred — delete,
PATCH semantics, match expressiveness and the write cap — were all answered by
the PO review of 2026-07-27 and are now specified (D7, D3, D8, D9
respectively). One process question remains and is recorded in
DEFERRED_QUESTIONS below rather than blocking the work.

## DEFERRED_QUESTIONS

- **Should the `patchObject()` work be its own chained change, per ADR-032?**
  There is a real argument that it should. ADR-032's `kind` taxonomy calls a
  change that mixes surfaces an anti-pattern to be split, and while this change
  is `kind: code` throughout — so it is not the `config` + `code` mix ADR-032
  was written against — the spirit of its "reviewer scope per spec is tight"
  argument applies: `ObjectService` is a hot core service with a large reviewer
  surface, and bundling it with a new flow node means one cycle carries both.
  The clean split would be a two-spec chain: spec 1 `or-objectservice-patch-
  semantics` (`kind: code`, `ObjectService::patchObject()` completed +
  `deleteObject()` widened + unit coverage), spec 2 this change with
  `depends_on: [or-objectservice-patch-semantics]`. That would also let the
  PATCH method merge and be adopted by other callers before the node lands.

  **It is folded into this change as instructed**, and two things make that
  defensible rather than merely compliant: the service work is genuinely small
  (two methods, one of which already exists), and it has no consumer other than
  this node until someone migrates one — so a standalone spec would merge a
  capability with zero call sites, which is its own smell. If the implementation
  cycle runs long or the reviewer flags the combined surface, splitting along
  the line above is the pre-agreed fallback and requires no redesign: tasks 1.1
  to 1.3 and REQ-OWN-013 are already separable as written.
