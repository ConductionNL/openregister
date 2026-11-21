## Context

`TransitionEngine` (`lib/Service/Lifecycle/TransitionEngine.php`) implements the
static form of `x-openregister-lifecycle`: `transitions` is a fixed
`action → { from: [literal states], to: literal }` map, and both `transition()` and
`availableActions()` compare the object's current **literal** field value against
those literals. `LifecycleAnnotationValidator` shape-checks that map at schema-save
time and requires `field`, a string-literal `initial`, and a non-empty `transitions`
map, with an enum constraint on the field.

Government case processes (procest) model status as a **relation**: `case.status` is
a `$ref` UUID to a `statusType` object. The valid set of statuses is not a fixed
enum on the case schema — it is the set of `statusType` objects whose `caseType` FK
points at the case's own `caseType`, ordered by `statusType.order`, with terminal
states flagged by `statusType.isFinal`. Because the case schema cannot enumerate
these UUIDs statically (they differ per parent `caseType` and are authored as data),
the static engine produces `[]` from `/available-actions` and the lifecycle field is
frozen. hydra ADR-062 rule 10 forbids `readOnly` on such fields until a dynamic mode
exists.

Constraints: fully backwards compatible; no DB migration; derivation must reuse the
existing `ObjectService` read path (RBAC + multitenancy already enforced there); the
static path must remain byte-for-byte unchanged and take precedence.

## Goals / Non-Goals

**Goals:**
- Add a declarative `graph` block to `x-openregister-lifecycle` that derives the
  available and target transitions at runtime from FK-scoped sibling objects.
- Support `allowedMoves` = `forward` | `adjacent` | `any`, terminal-state lockout via
  `finalField`, and stable `move-to-<uuid>` action ids with human-readable labels.
- Support object-form `initial` (`{ from, field }`) that seeds the starting status
  from the parent, including **auto-seed at object-create time** when the lifecycle
  field is empty (v1 behaviour, per Ruben 2026-07-08).
- Keep static `transitions` behaviour unchanged; static takes precedence when both
  are declared.
- Fire `ObjectTransitionedEvent` and run the same save-path validation for graph-mode
  transitions as for static ones.

**Non-Goals:**
- No consumer register JSON here — the procest `case`/`statusType` register change
  lives in the procest repo. This change ships only the OR engine + validator.
- No new HTTP endpoints — `/transition` and `/available-actions` are reused as-is.
- No change to the static `transitions` shape or semantics.
- No cross-parent transitions (a case can only move among statuses of its own
  `caseType`).
- No graph editing UI.

## Decisions

### Declarative-vs-imperative decision

This change **is the declarative path**. It extends the existing declarative
`x-openregister-lifecycle` annotation (ADR-024 / hydra#202) with a `graph` block, so
a consumer declares the FK-scoped status graph as **data** (schema configuration +
`statusType` objects) rather than writing an imperative per-schema transition
controller. The derivation logic itself lives in **engine code** (`TransitionEngine`),
not in the declarative payload, and this is the intended split under the ADR-031
lifecycle-guard/engine exception: the annotation declares *what* the graph is (which
sibling schema, which FK, which order/final fields, which move policy); the engine is
the single shared interpreter that turns that declaration into concrete transitions
at runtime. Putting the traversal in engine code (rather than, say, a calc-expression
or per-app guard) keeps one audited, testable implementation that every consumer app
inherits, matching how the static `transitions` map is already interpreted centrally.

Alternative considered: express the graph as a `requires` guard per app (imperative,
in each consumer). Rejected — it re-implements traversal N times, bypasses
`/available-actions` (the UI would still get `[]`), and duplicates the ordering/final
logic that belongs in one place.

Alternative considered: materialise the derived enum onto the case schema via the
calc engine. Rejected — the valid set is per-parent and per-object, not per-schema,
so there is no single enum to materialise; it would also couple lifecycle to the calc
re-materialisation cycle.

### Derivation algorithm

Given a graph-mode object:
1. Read `parentValue = object.data[graph.parentFrom]` (the parent FK on the object,
   e.g. the case's `caseType` UUID). If absent → no actions (`[]`).
2. Fetch siblings: `ObjectService::findAll` over `graph.schema`, filtered by
   `graph.parentField == parentValue`, sorted ascending by `graph.orderField`. This
   goes through the standard read path so RBAC + multitenancy apply.
3. Locate the current state: `currentUuid = object.data[field]`; find its index in
   the ordered sibling list. If the current value is not among the siblings, treat
   index as "unset" and offer the first sibling only (recover-to-start — confirmed
   by Ruben 2026-07-08).
4. Compute candidate targets by `allowedMoves`:
   - `forward` → sibling at `index + 1` only.
   - `adjacent` → siblings at `index - 1` and `index + 1`.
   - `any` → every sibling except the current one.
5. Terminal lockout: if the **current** sibling's `finalField` is true and
   `allowedMoves` is not `any`, yield no candidates (the state is a sink).
6. Emit one action per candidate: `action = "move-to-<targetUuid>"`,
   `to = <targetUuid>`, `label = <target display name>` (the sibling's title/name
   field), `description = null`, `requires = null` (graph mode has no per-edge
   `requires` in v1).

`transition()` re-runs steps 1–6 and accepts the requested `action` only if it is in
the derived candidate set — the client cannot post a `move-to-<uuid>` that the graph
does not currently allow. On success it mutates `object.data[field] = <targetUuid>`
and saves through the unchanged `ObjectService::saveObject` path, then dispatches
`ObjectTransitionedEvent(from: currentUuid, to: targetUuid, action)`.

### Mode selection & precedence

`getLifecycleAnnotation()` is unchanged. In both `transition()` and
`availableActions()`: if `annotation['transitions']` is a non-empty array → **static
path** (existing code, untouched). Else if `annotation['graph']` is a non-empty array
→ **graph path** (new). Else → existing empty/absent behaviour. This guarantees
static precedence and zero regression for every existing static schema.

### `initial` object-form + auto-seed on create

`LifecycleAnnotationValidator` currently requires `initial` to be a string in the
field enum. Graph mode has no field enum (the field is a `$ref`), so:
- When `graph` is present, `initial` MAY be an object `{ "from": "<parentFrom field>",
  "field": "<field on the parent object>" }`. Validator shape-checks the two string
  keys; it does NOT resolve the parent (runtime concern).

**Auto-seed (v1, decided by Ruben 2026-07-08 — reverses the earlier provisional
"no auto-seed")**: the engine DOES set the lifecycle field from the object-form
`initial` declaration at object-**create** time, with these rules:
- **Hook point**: the `SaveObject` create pipeline, as a lifecycle-seed step that
  runs alongside metadata hydration — i.e. BEFORE schema validation and persistence,
  so a `required` lifecycle field passes validation on a seeded create. It runs only
  on the create path (no existing entity), never on update.
- **Empty-field-only**: seeding applies only when `object.data[field]` is absent,
  null, or the empty string. An explicitly provided value is NEVER overwritten —
  client-supplied status wins.
- **Resolution**: read `parentRef = object.data[initial.from]`; load the parent
  object through the standard `ObjectService` read path (RBAC + multitenancy apply);
  read `seedValue = parent.data[initial.field]`. Set `object.data[field] = seedValue`.
- **Fail-soft no-op**: if the parent ref is empty, the parent cannot be loaded, or
  the parent's `initial.field` is empty, the seed step is a no-op (logged at debug)
  and the create proceeds with the field unset — normal schema validation then
  decides whether an unset field is acceptable (e.g. `required` still rejects).
- **Interaction with the object form**: auto-seed is driven exclusively by the
  object-form `initial`; the legacy literal-string `initial` keeps its existing
  static-mode semantics and is NOT auto-seeded by this step (no behaviour change for
  static schemas).
- No `ObjectTransitionedEvent` is dispatched for a seed — it is an initialisation,
  not a transition.

### Validator changes

`validate()` gains a branch: if `x-openregister-lifecycle.graph` is present, validate
the graph block instead of (or in addition to) `transitions`:
- `graph.schema`, `graph.parentField`, `graph.parentFrom` — required non-empty
  strings.
- `graph.orderField`, `graph.finalField` — required non-empty strings.
- `graph.allowedMoves` — required, one of `forward` | `adjacent` | `any`.
- `field` — required non-empty string; the enum/`type:string` constraint is
  **relaxed** when `graph` is present (a `$ref` field has no enum).
- `initial` — accept either the existing string form or the object form
  `{ from, field }`.
Error codes follow the existing `lifecycle-*` convention (e.g.
`lifecycle-graph-missing-key`, `lifecycle-graph-allowedmoves-invalid`).

### Guards / events

`ObjectTransitionedEvent` and `LifecycleGuardRegistry` are reused verbatim — the
engine dispatches the same typed event after a graph transition. NOTE (discrepancy,
see below): `LifecycleGuardRegistry` is currently **not invoked** by `TransitionEngine`
at all; guard firing today happens on the `saveObject` validation path, not inside the
engine. Graph mode goes through the same `saveObject` path, so whatever guard/`requires`
enforcement exists for static transitions applies identically to graph transitions —
no new wiring, and no attempt to add per-edge `requires` in graph v1.

## Seed Data

Realistic municipal example for PHPUnit fixtures — an *Omgevingsvergunning* (building
permit) case whose status is FK-driven. All UUIDs are nil placeholders.

**`caseType` object** (the parent):
```json
{
  "id": "00000000-0000-0000-0000-000000000000",
  "name": "Omgevingsvergunning",
  "initialStatus": "00000000-0000-0000-0000-000000000001"
}
```

**`statusType` objects** (siblings, all `caseType` → the parent above):
```json
[
  {
    "id": "00000000-0000-0000-0000-000000000001",
    "name": "Ontvangen",
    "caseType": "00000000-0000-0000-0000-000000000000",
    "order": 1,
    "isFinal": false
  },
  {
    "id": "00000000-0000-0000-0000-000000000002",
    "name": "In behandeling",
    "caseType": "00000000-0000-0000-0000-000000000000",
    "order": 2,
    "isFinal": false
  },
  {
    "id": "00000000-0000-0000-0000-000000000003",
    "name": "Afgehandeld",
    "caseType": "00000000-0000-0000-0000-000000000000",
    "order": 3,
    "isFinal": true
  }
]
```

**`case` object** (the transitioning object), with graph annotation on its schema:
```json
{
  "id": "00000000-0000-0000-0000-0000000000aa",
  "caseType": "00000000-0000-0000-0000-000000000000",
  "status": "00000000-0000-0000-0000-000000000002"
}
```

**`case` schema `x-openregister-lifecycle`**:
```json
{
  "field": "status",
  "initial": { "from": "caseType", "field": "initialStatus" },
  "graph": {
    "schema": "statustype",
    "parentField": "caseType",
    "parentFrom": "caseType",
    "orderField": "order",
    "finalField": "isFinal",
    "allowedMoves": "forward"
  }
}
```

Expected derivations for tests:
- `forward` from `Ontvangen` (order 1) → `[move-to-…0002]` (In behandeling).
- `adjacent` from `In behandeling` (order 2) → `[move-to-…0001, move-to-…0003]`.
- `any` from `In behandeling` → `[move-to-…0001, move-to-…0003]` (all but self).
- final lockout: `forward`/`adjacent` from `Afgehandeld` (isFinal) → `[]`; `any` from
  `Afgehandeld` → the other two.
- static-precedence: a schema declaring both `transitions` and `graph` uses only the
  static `transitions`.

## Risks / Trade-offs

- [Extra read per available-actions call: graph mode fetches all siblings] →
  Mitigation: sibling sets are tiny (a handful of statuses per case type); the fetch
  uses the cached `ObjectService` read path. Bound results and rely on existing cache.
- [Current value not among siblings (parent re-typed, orphan status)] → Mitigation:
  treat as unset and offer the first sibling as a recover-to-start move; log at debug
  (confirmed decision).
- [Auto-seed reads the parent on every create of a graph-mode object] → Mitigation:
  single cached `ObjectService` read, create-path only, skipped entirely when the
  client supplied a value; fail-soft no-op keeps create latency bounded on missing
  parents.
- [`allowedMoves: any` bypasses terminal lockout by design] → documented explicitly;
  consumers choosing `any` accept that finality is advisory, not enforced, for that
  schema.
- [Silent divergence between `availableActions` derivation and `transition`
  validation] → Mitigation: both call the **same** private derivation method; a single
  code path prevents drift (unit-tested for parity).
- [Ordering ties on `orderField`] → Mitigation: stable secondary sort by UUID so
  derivation is deterministic; documented.

## Migration Plan

No DB migration. Deploy is code-only:
1. Ship `TransitionEngine` + `LifecycleAnnotationValidator` changes (additive; static
   path untouched).
2. Existing static-mode schemas: no change, no re-save required.
3. procest (separate repo) adopts the `graph` block on its `case` schema and removes
   the ADR-062-rule-10 `readOnly` block once this ships.
Rollback: revert the two files; graph-mode schemas fall back to `[]` from
`/available-actions` (the pre-change behaviour) — no data corruption, no migration to
undo.

## Open Questions

None remaining. Both formerly deferred questions were decided by Ruben on
2026-07-08: (1) auto-seed on create IS in v1 (empty-field-only, never overwrites a
provided value, fail-soft no-op — see "`initial` object-form + auto-seed on
create"); (2) orphan/unknown current status uses recover-to-start (first sibling
offered).
