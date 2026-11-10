# Design — calc-engine-aggregate-reference

## Context

This is **Extension 2** of the per-object calculation engine, building directly on
**Extension 1** (`calc-engine-reference-lookup`, `@ref`) and
`calc-engine-scalar-functions`. The design copies Extension 1's structure exactly so
the two reference mechanisms (`@ref`, single-object; `@aggregate`, many-row fold) are
symmetric and learnable together.

The pure JSON-AST `CalculationEvaluator` reads everything through one dotted-path
`prop` mechanism: `@self.<field>` (object metadata + own fields), `@ref.<name>.<field>`
(one other object). This change adds a third injected namespace, `@aggregate.<name>`
(a folded scalar or a grouped map), without touching the evaluator's I/O-free core.

## The `@aggregate` pre-resolution design

```
                 ObjectCreatingEvent / ObjectUpdatingEvent
                                  │
                CalculationOnSaveListener::process()
                                  │
   ┌──────────────────────────────┼───────────────────────────────┐
   │ 1. inject @self (id/created/…  + own fields top-level)        │
   │ 2. inject @ref  (ReferenceResolver — one object per ref)      │  ← Ext 1
   │ 3. inject @aggregate (AggregateReferenceResolver —            │  ← THIS CHANGE
   │      AggregationRunner::runAdhoc() per declared aggregate)    │
   │ 4. evaluate each materialise:true calc (PURE evaluator)       │
   │ 5. strip @self / @ref / @aggregate before persist            │
   └──────────────────────────────────────────────────────────────┘
```

A new helper `AggregateReferenceResolver` (mirrors `ReferenceResolver`) is injected
into both the listener and the command. Given the payload (with `@self` already
injected), the `x-openregister-aggregate-refs` map, and the register/schema context, it
returns an `@aggregate` array keyed by reference name.

Per declared aggregate-reference it:
1. Parameterises the `filters` map by resolving `@self.<field>` tokens against the
   payload (reusing the same tiny token resolver shape Ext 1 used — literals pass
   through; `@self.<field>` reads the object's own field; small AST nodes like
   `{ "year": "@self.date" }` are supported for symmetry). This keeps the evaluator
   pure — token resolution lives in the helper, not the evaluator.
2. Builds an `AggregationQuery::create(metric, field, filters, groupBy)`.
3. Calls `AggregationRunner::runAdhocByRef(registerRef, schemaRef, query)` — the
   ref-based convenience wrapper that loads register+schema then calls `runAdhoc()`.
4. Maps the result envelope to the injected value:
   - **scalar** (`{value: …}`): inject the scalar directly under `@aggregate.<name>`.
   - **grouped** (`{groups: [{key, value}, …]}`): inject a `{<stringKey>: <value>}`
     map under `@aggregate.<name>` so a calc reads `@aggregate.<name>.<groupKey>`.
5. Wraps every resolution in `try/catch` → on `\Throwable`, inject `null` + log a
   warning; never rethrow (the save is never failed).

### `AggregationRunner::runAdhoc()` signature (verified)

```php
public function runAdhoc(Register $register, Schema $schema, AggregationQuery $query): array
public function runAdhocByRef(string $registerRef, string $schemaRef, AggregationQuery $query): array
```

- Returns `{value, backend, cached}` (ungrouped) or `{groups: [{key,value}], backend, cached}`.
- RBAC: gates on `PermissionHandler::hasPermission(list)` for the active session and
  applies the `_organisation` multi-tenancy predicate in every backend path. No bypass
  flag is passed (the listener runs under the saving user's session — the aggregate is
  exactly the saver's authorised view of the data). `runAdhocByRef` resolves
  register/schema by slug/uuid/id (the values `ObjectEntity::getRegister()/getSchema()`
  carry), so the listener needs no extra mappers.

### Why the listener, not the evaluator (ADR-031 table)

| Concern                    | Lives in                              | Why                                                                 |
|----------------------------|----------------------------------------|---------------------------------------------------------------------|
| Aggregation I/O + RBAC     | `AggregateReferenceResolver` (listener)| Keeps `CalculationEvaluator` pure (no DB, no session) — same as `@ref` |
| Token (`@self.*`) resolve  | `AggregateReferenceResolver`           | Avoids coupling the pure evaluator to payload-shaped lookups        |
| Reading `@aggregate.<name>`| `CalculationEvaluator` (`prop` op)     | Already supports dotted-path reads of injected namespaces           |
| Annotation shape + tokens  | `CalculationAnnotationValidator`       | Save-time fail-fast, mirrors `@ref` token recognition               |
| Vocabulary registration    | `Schema::ANNOTATION_VOCABULARY`        | Else the annotation is silently dropped on schema save (Ext1 gotcha)|

## Snapshot / recompute contract

A materialised aggregate is a **save-time snapshot**, identical to the `@ref` contract:

- The value reflects the aggregation **as computed during this object's save**.
- If a contributing row in the aggregated schema later changes, the previously
  materialised value is **stale** until the object is re-saved.
- `openregister:rematerialise-calculations <register> <schema>` re-resolves every
  aggregate-reference (the command mirrors the listener's pre-resolution step) and
  rewrites the materialised value. This is the supported refresh path.
- Live propagation (auto-refreshing dependents when a contributing row changes) is
  explicitly out of scope. `AggregationRunner`'s own 60 s result cache is an
  orthogonal read-path optimisation and does not change this snapshot semantics.

## `sha256` scalar op

A pure single-argument operator added to `CalculationEvaluator`:

```json
{ "sha256": [ <expr> ] }   // or bare:  { "sha256": <expr> }
```

- Evaluates its operand, stringifies it (`(string)` cast; `null` → `null`, not a hash
  of the empty string — null-safe), and returns `hash('sha256', $string)` (64-char
  lowercase hex).
- Mirrors `abs`/`round`/`year` conventions: uses `firstOperand()` so it accepts both
  `[expr]` and a bare node; `null` operand → `null`.
- Added to the evaluator `match`, to `CalculationAnnotationValidator::VALID_OPS`.
- Pure: no I/O, deterministic — the same input always yields the same digest.

## Security

- **No new public surface.** No routes, no controllers. The only entry point is the
  schema annotation, authored by a schema-write-privileged operator.
- **RBAC + tenant scope are inherited** from `AggregationRunner::runAdhoc()`, never
  bypassed. An aggregate cannot fold over rows the saving user cannot list, and cannot
  cross tenants (fails closed: no active org ⇒ no rows).
- **Fail-safe:** resolution errors inject `null`/empty and log — never fatal a save,
  never leak partial data.
- **`sha256`** is a pure, side-effect-free digest of a stringified value stored as a
  field; no template/SQL/eval surface downstream (same trust model as `formatDate`).

## Test Plan

Unit (PHPUnit, runs in the NC34 container, PHP 8.4):

1. **`AggregateReferenceResolverTest`** (mirrors `ReferenceResolverTest`):
   - scalar aggregate resolves → injects the scalar under `@aggregate.<name>`
     (mock `AggregationRunner::runAdhocByRef` → `{value: 12.0}`).
   - grouped aggregate resolves → injects a `{key: value}` map.
   - `@self.<field>` filter tokens are parameterised by the payload (assert the
     `AggregationQuery` passed to the runner carries the resolved literal).
   - unresolvable / runner throws → injects `null`, never rethrows (null-safety).
   - RBAC scoping: assert the resolver calls `runAdhocByRef` (which is itself
     RBAC-gated) and does NOT pass any bypass flag.
2. **`CalculationEvaluator` `sha256` cases** (in the existing evaluator test):
   - determinism: `sha256("abc")` === the known SHA-256 of `"abc"`
     (`ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad`).
   - stringification: numeric/`@self`-prop operand hashes its string form.
   - null-safety: `sha256` of a `null`-resolving prop → `null` (not a hash).
3. **`CalculationAnnotationValidator` cases**:
   - `@aggregate.<name>` / `@aggregate.<name>.<field>` prop token accepted when
     `<name>` is declared; rejected (`calculation-aggregate-unknown`) otherwise.
   - aggregate-refs shape: missing `schema` / bad `metric` / non-count metric without
     `field` → errors; `sha256` accepted as a known op.

Live (shillinq, part of verification):
- Convert one shillinq register calc to the declarative form and prove the value
  computes end-to-end via `occ maintenance:repair` re-import + a POST. At minimum
  prove `sha256` live; validate `@aggregate` structurally + via unit test, reporting
  honestly what was live-verified vs unit-only.
