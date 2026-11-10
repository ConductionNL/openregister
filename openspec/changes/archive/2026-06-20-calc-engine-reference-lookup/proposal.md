---
kind: code
---

## Why

OpenRegister's per-object calculation engine (`CalculationEvaluator`, a pure JSON-AST
interpreter behind `x-openregister-calculations`, materialised at save time by
`CalculationOnSaveListener`) can only read fields on the object *being saved* plus the
`@self` system metadata the listener injects. It cannot read fields on **other**
objects — e.g. "look up today's mileage rate from the `MileageRate` master table" or
"read the acquisition cost from the linked `FixedAsset`". The prior change
`calc-engine-scalar-functions` (696af7f67) added the missing scalar primitives but
explicitly left cross-object reads out of scope.

Because of that gap, leaf apps fall back to imperative PHP guard services to do the
lookup before save (against ADR-031, which says derived values should be declarative
on the schema). A shillinq audit found **8 such guard calcs** that are pure
cross-object reads: 6 effective-dated rate-table lookups (ExchangeRate, MileageRate,
PerDiemRate, ZzpDeductionAmounts ×3) and 2 `relatedObject` reads on
`DepreciationSchedule` against `FixedAsset`. These are not aggregations (no folding
over many rows) — each resolves to exactly one referenced object — so they belong on
the per-object path, not `x-openregister-aggregations`.

## What Changes

- Add a new schema annotation **`x-openregister-references`**: a map of named
  references, each declaring a target `schema` and a resolution `mode`:
  - **`relatedObject`** (resolve by FK): a local `field` holds the referenced
    uuid/id → resolved via `ObjectService::find()`.
  - **`lookup`** (resolve by criteria): a `filters` map (each value a literal or a
    `@self.<field>` token) → resolved via `ObjectService::findAll(['filters'=>…])`,
    taking the first/most-relevant row. Supports an optional `effectiveDate`
    declaration so a rate-table row valid as-of the object's date is selected.
- **Extend `CalculationOnSaveListener`** (NOT the evaluator) to pre-resolve every
  declared reference in the same pre-step that injects `@self`, and inject each
  resolved object's data into the payload under `@ref.<name>`. JSON-AST expressions
  then read it via `{ "prop": "@ref.<name>.<field>" }` — exactly mirroring `@self`.
  The evaluator stays **pure** (no I/O); all resolution happens in the listener.
- Mirror the same pre-resolution in `RematerialiseCalculationsCommand` so the
  recompute path resolves references too.
- Extend `CalculationAnnotationValidator` to (a) recognise `@ref.<name>.<field>` prop
  tokens as valid when `<name>` is a declared reference, and (b) validate the
  `x-openregister-references` shape.
- Reuse `ObjectService`'s existing RBAC + multitenancy scoping (defaults `true`) on
  every lookup so a reference NEVER leaks cross-tenant data. Because `find()`/
  `findAll()` are read operations they do NOT dispatch `Creating`/`Updating` events,
  so resolving a reference cannot recursively re-trigger calculations.
- A missing / unresolvable reference injects `null` (calcs read it gracefully) and
  is logged at warning level — it MUST NOT fatal the save.
- Add unit/harness coverage: FK resolve, criteria (effective-dated) resolve,
  missing-ref→null, RBAC scoping (mocked).

**Snapshot semantics (the contract):** a materialised value is a snapshot at save
time. If the referenced row later changes, dependents are stale until re-saved;
`openregister:rematerialise-calculations` refreshes them. Live propagation is
out of scope.

## Capabilities

### New Capabilities
<!-- none -->

### Modified Capabilities
- `computed-fields`: the materialisation surface (`CalculationOnSaveListener` +
  `RematerialiseCalculationsCommand` + `CalculationAnnotationValidator`) gains a
  declarative cross-object reference mechanism (`x-openregister-references` →
  `@ref.<name>.<field>`), making single-object reference reads expressible without
  imperative PHP guards. The pure `CalculationEvaluator` vocabulary is unchanged.

## Impact

- **Code:** `lib/Listener/CalculationOnSaveListener.php` (pre-resolve references,
  inject `@ref`), `lib/Command/RematerialiseCalculationsCommand.php` (mirror),
  `lib/Service/Calculation/CalculationAnnotationValidator.php` (validate `@ref`
  tokens + references block), a new
  `lib/Service/Calculation/ReferenceResolver.php` (the shared, testable resolution
  helper). The `CalculationEvaluator` is **untouched** (stays pure).
- **Tests:** `tests/Unit/Service/Calculation/ReferenceResolverTest.php`.
- **Dependent apps:** unlocks 8 shillinq guard calcs (declarative migration is a
  separate follow-up); additive, no app breaks.
- **No** DB-schema, route, or dependency changes. New annotation is opt-in.
- **Performance:** per-object resolution is N+1 on bulk save; caching slow-changing
  reference data is noted as a follow-up, correctness-first here.
