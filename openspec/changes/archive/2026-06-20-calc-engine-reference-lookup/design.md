# Design — calc-engine-reference-lookup

## Context

The calculation engine has three collaborators:

- **`CalculationEvaluator`** — a PURE JSON-AST interpreter. Inputs: object payload +
  expression AST. No I/O, no DB, no HTTP. This purity is load-bearing (it makes the
  engine unit-testable and side-effect-free) and MUST be preserved.
- **`CalculationOnSaveListener`** — fires on `ObjectCreatingEvent` / `ObjectUpdatingEvent`.
  Before evaluation it injects `@self` system metadata into the payload (listener
  lines ~123–147), then for each `materialise: true` calc runs the evaluator and
  patches the result back into the object, finally stripping the synthetic `@self`.
- **`RematerialiseCalculationsCommand`** — re-runs the same materialisation over all
  existing objects of a (register, schema) on demand (operator/cron).

The prior change `calc-engine-scalar-functions` (696af7f67) added per-object scalar
ops but deliberately excluded cross-object reads. This change adds them — by
following the `@self` precedent exactly: resolve in the listener, inject into the
payload, read via `prop`.

## Decision (ADR-031 alignment)

> ADR-031: derived/calculated values are declared on the schema (declarative),
> not computed by per-app imperative PHP services.

| Decision | Choice | Why |
|---|---|---|
| Where does reference resolution happen? | **In the listener** (via a `ReferenceResolver` helper), NOT in the evaluator | Keeps `CalculationEvaluator` pure (no I/O). Mirrors how `@self` is injected. |
| How do expressions read a reference? | `{ "prop": "@ref.<name>.<field>" }` | Reuses the evaluator's existing dotted-path `prop` resolution unchanged — exactly like `@self.created`. Zero evaluator change. |
| How are references declared? | New schema annotation `x-openregister-references` (sibling of `x-openregister-calculations`) | Keeps the calc block focused on expressions; references are a separate, reusable concern that a calc consumes by name. |
| FK resolution | `mode: relatedObject`, `field: <localUuidField>`, resolved via `ObjectService::find()` | One referenced object by id. |
| Criteria resolution | `mode: lookup`, `filters: {…}`, resolved via `ObjectService::findAll(['filters'=>…])`, take first | One row from a master/rate table, parameterised by `@self.*`. |
| Effective-dating | `lookup` + optional `effectiveDate: { field: <rateField>, op: lte, value: "@self.<date>" }` | Rate tables keep history; pick the latest row valid as-of the object's date. |
| Tenant / RBAC scoping | Reuse `ObjectService` defaults `_rbac:true, _multitenancy:true` | No new scoping logic; a reference can NEVER read outside the saver's tenant/permission scope. |
| Recursion safety | Resolution uses `find()`/`findAll()` — READ ops that do NOT dispatch Creating/Updating events | The referenced object's own calcs are never re-triggered → no infinite loop. |
| Staleness | **Snapshot at save time** + `rematerialise` command to refresh | Live propagation is out of scope (documented below). |

### Why not extend the evaluator with a `lookup` operator?

The shillinq guards write `lookup('MileageRate', {…})` as a *string DSL*. Putting an
I/O-performing `lookup` op inside the evaluator would break its purity contract
(every existing test constructs the evaluator with only a `PlaceholderResolver` and
asserts it touches nothing else). Instead the listener resolves the reference *once*
per save and injects it; the evaluator only ever does a pure `prop` read. This is
strictly more testable and matches the `@self` precedent.

## The annotation shape

Declared as a sibling of `x-openregister-calculations` on the schema (stored in
`schema.configuration`, same as calculations):

```json
"x-openregister-references": {
  "rate": {
    "schema": "MileageRate",
    "mode": "lookup",
    "filters": {
      "fiscalYear":  { "year": "@self.journeyDate" },
      "vehicleType": "@self.vehicleType",
      "country":     "@self.country"
    },
    "nullable": true
  },
  "asset": {
    "schema": "FixedAsset",
    "mode": "relatedObject",
    "field": "fixedAssetId",
    "nullable": true
  }
}
```

Then a calc consumes it by name via `@ref.<name>.<field>`:

```json
"x-openregister-calculations": {
  "ratePerKm": {
    "type": "number",
    "materialise": true,
    "expression": { "prop": "@ref.rate.ratePerKm" }
  },
  "totalAmount": {
    "type": "number",
    "materialise": true,
    "expression": { "*": [ { "prop": "distance" }, { "prop": "@ref.rate.ratePerKm" } ] }
  }
}
```

### Reference object fields

| Key | Required | Meaning |
|---|---|---|
| `schema` | yes | Target schema slug/uuid/id to resolve against. Resolved within the saver's own register unless `register` overrides it. |
| `register` | no | Target register (defaults to the saving object's register). |
| `mode` | yes | `relatedObject` (FK) or `lookup` (criteria). |
| `field` | `relatedObject` only | Local field holding the referenced uuid/id. |
| `filters` | `lookup` only | Map of `targetField → criterion`. A criterion is a literal, a `@self.<field>` token, or a single-key AST node (e.g. `{ "year": "@self.date" }`) evaluated against `@self`. |
| `effectiveDate` | no (`lookup`) | `{ "field": <rateField>, "op": "lte", "value": "@self.<date>" }` — filter rows to those valid as-of the object's date, then sort desc on `field` and take the first. |
| `nullable` | no (default `true`) | When `true`, an unresolved reference injects `null`; when `false`, it still injects `null` but logs at a higher level. The save NEVER fails. |

`@ref.<name>` is injected as the **full data array** of the resolved object (plus its
`@self` sub-key), so any field is reachable: `@ref.rate.ratePerKm`, `@ref.asset.cost`,
`@ref.asset.@self.uuid`.

## Resolution algorithm (in `ReferenceResolver`, called by the listener)

For each declared reference `name → spec`, in declaration order, BEFORE evaluating any calc:

1. **Build the criteria** by resolving every `@self.*` / AST-node token in `field`
   value or `filters` against the already-injected `@self` + object data (pure, via a
   tiny token resolver — NOT the full evaluator, to avoid coupling; literals and
   `@self.x` and single-key AST nodes like `{year: @self.date}` are supported).
2. **Resolve**:
   - `relatedObject`: read the local `field` value (a uuid/id); if empty → `null`.
     Else `ObjectService::find(id, schema: <schema>, _rbac:true, _multitenancy:true)`.
   - `lookup`: `ObjectService::findAll(['filters'=>['register'=>…, 'schema'=>…, …criteria], 'limit'=>…])`.
     If `effectiveDate` present, add the `op` filter and sort desc on its `field`;
     take the first row. Otherwise take the first match.
3. **Inject**: `$payload['@ref'][$name] = $resolvedObject?->getObject() ?? null`
   (with `@self` of the resolved object available too). On ANY `\Throwable` →
   inject `null` + `logger->warning(...)`; never rethrow.
4. After all references injected, the existing calc loop runs unchanged — `@ref.*`
   is just another dotted `prop` path.
5. Strip the synthetic `@ref` (like `@self`) before persisting.

**Evaluation order:** references resolve in the SAME pre-step as `@self`, strictly
before the calc loop. A calc can therefore always read `@ref.*`. References cannot
read each other (they only read `@self`/object fields), so no inter-reference cycle is
possible.

## Cross-cutting concerns (each implemented)

- **RBAC / tenant scoping** — `find()`/`findAll()` are called with their default
  `_rbac:true, _multitenancy:true`, so the lookup runs under the saver's own
  permission + tenant context. A user who cannot read `MileageRate` gets `null`, not
  another tenant's rate. No bypass flag is ever passed.
- **Cycle / None-safety** — resolution uses READ paths that do not dispatch
  Creating/Updating events, so the resolved object's calcs never re-run → no
  recursion. Missing FK field, empty lookup result, or any exception → `null`,
  logged, save proceeds.
- **Recompute / staleness (the contract)** — materialised values are **snapshots**
  taken at the saving object's save time. If a `MileageRate` row is later edited, the
  already-saved `MileageEntry.ratePerKm` does NOT change automatically; it is stale
  until that entry is re-saved. The supported refresh path is
  `occ openregister:rematerialise-calculations <register> <schema>` (this change wires
  reference pre-resolution into that command so it refreshes correctly). A
  change-trigger (re-materialise dependents when a referenced row changes) is noted as
  a possible future enhancement but is explicitly **out of scope** — snapshot +
  rematerialise is the contract.
- **Performance** — each object resolves its references independently, so a bulk save
  / rematerialise of N objects performs N lookups (N+1). For slow-changing master
  data (rate tables) this is wasteful; caching resolved reference rows per
  (schema, criteria) within a single command run is a noted **follow-up**. Correctness
  of the per-object path comes first; no cache in this change.

## Test Plan

Unit tests on a new `ReferenceResolver` (the listener's resolution is delegated to it
so it is testable without a live NC kernel; mocks `ObjectService`):

1. **FK resolve** — `relatedObject` with a populated `field` → `find()` returns an
   entity → `@ref.asset` injected with its data; a calc reading `@ref.asset.cost`
   computes.
2. **Criteria resolve (effective-dated)** — `lookup` with `filters` + `effectiveDate`
   → `findAll()` returns rows → the latest valid row is selected → `@ref.rate`
   injected.
3. **Missing-ref → null** — FK field empty / `findAll()` returns `[]` / `find()`
   returns `null` → `@ref.<name>` injected as `null`, no exception, calc reading it
   yields `null`.
4. **RBAC scoping (mock)** — assert `find()`/`findAll()` are invoked with
   `_rbac:true` and `_multitenancy:true` (never bypassed).
5. **Exception safety** — `ObjectService` throws → resolver injects `null` + logs,
   does not rethrow.

Because PHPUnit may not run in this environment (NC34 OCP-stub drift), the resolution
logic is additionally proven via a standalone PHP harness that fakes the
`ObjectService` contract; CI runs the real suite. The end-to-end proof is the
shillinq live-verify (a real `MileageEntry` saved against real seeded `MileageRate`
rows resolves the rate and computes `totalAmount`).
