---
kind: code
---

## Why

OpenRegister's per-object calculation engine (`CalculationEvaluator`, a pure JSON-AST
interpreter behind `x-openregister-calculations`, materialised at save time by
`CalculationOnSaveListener`) can read fields on the object *being saved* (`@self`),
plus — since `calc-engine-reference-lookup` (c3e5306c9) — fields on exactly one OTHER
object via `@ref.<name>.<field>`. What it still cannot do is read a value that folds
over MANY rows: "the total billable hours booked against this resource this period",
"the number of contributing line items", "the sum of amounts in a related set". Those
are aggregations, not single-object reads, so `@ref` cannot express them.

OpenRegister already has a complete, RBAC-gated, multi-backend aggregation engine
(`AggregationRunner`) reachable both via the `x-openregister-aggregations` named-spec
path and — since b2262a053 — via a programmatic ad-hoc path
(`AggregationRunner::runAdhoc(Register, Schema, AggregationQuery)`). But the per-object
calc engine has no way to pull an aggregate into a materialised field. Leaf apps
therefore fall back to imperative PHP guard services to compute the rollup before
save, against ADR-031 (derived values should be declarative on the schema).

Two concrete unlocks motivate this change:
- **UrenRegistratie.utilizationPercent** = `@aggregate.billableHoursThisPeriod /
  @aggregate.availableHoursThisPeriod` — a ratio of two grouped/scalar aggregations
  scoped to the saving object's resource + period.
- **Account.emuAggregationHash** = `sha256(@aggregate.contributingIds + …)` — a stable
  content hash over the set of contributing object ids, which needs both an aggregate
  pull AND a `sha256` scalar op the evaluator does not yet have.

This change mirrors the just-landed `@ref` design exactly: resolution lives in the
LISTENER (pre-resolved before any calculation evaluates and injected into the payload);
the `CalculationEvaluator` stays PURE.

## What Changes

- **(a) Aggregate-reference annotation `x-openregister-aggregate-refs`**: a map of
  named aggregations, each declaring a `schema` to aggregate over, a `metric`
  (count/sum/avg/min/max), an optional `field`, an optional `filters` map (whose
  values may be literals or `@self.<field>` tokens, parameterising the aggregation by
  the saving object), and an optional `groupBy`. The
  **`CalculationOnSaveListener` pre-resolves** each declared aggregate-reference via
  `AggregationRunner::runAdhoc()` (which is programmatically invocable and RBAC-gated)
  and injects the result into the payload under `@aggregate.<name>`. Scalar
  aggregations inject the scalar value directly (`@aggregate.<name>`); grouped
  aggregations inject a `{<groupKey>: <value>}` map so calcs read `@aggregate.<name>.<field>`.
  JSON-AST expressions then read it via `{ "prop": "@aggregate.<name>" }` (or
  `@aggregate.<name>.<field>` for grouped) — exactly mirroring `@self` and `@ref`.
  The pure `CalculationEvaluator` is **untouched** by this part (it reads `@aggregate.*`
  through its existing dotted-path `prop` mechanism).
- **Mirror the same pre-resolution in `RematerialiseCalculationsCommand`** so the
  recompute path refreshes aggregates the same way the save path does (exactly as
  `@ref` did).
- **Extend `CalculationAnnotationValidator`** to (a) recognise `@aggregate.<name>` /
  `@aggregate.<name>.<field>` prop tokens as valid when `<name>` is a declared
  aggregate-reference, and (b) validate the `x-openregister-aggregate-refs` shape
  (each entry has a `schema` + a valid `metric`; non-count metrics require a `field`).
- **Register `x-openregister-aggregate-refs` in `Schema::ANNOTATION_VOCABULARY`**
  (the Ext1-discovered gotcha — the schema-save fold drops any `x-openregister-*` key
  not in that allow-list, so the annotation would be silently lost on import).
- **(b) `sha256` scalar op on `CalculationEvaluator`**: a pure single-argument op
  `{ "sha256": [<expr>] }` returning the hex SHA-256 digest of the stringified value
  of its operand. Trivial, additive, matches the existing op conventions
  (`abs`/`round`/`year`). Add it to `evaluate()`'s `match`, to the validator's
  `VALID_OPS`, and unit-test determinism + null-safety.

**Cross-cutting concerns (same contract as `@ref`):**
- **RBAC/tenant scoping:** `runAdhoc()` already gates on
  `PermissionHandler::hasPermission(list)` and applies the multi-tenancy predicate; the
  listener calls it under the saving user's session — an aggregate NEVER folds over
  rows the saver cannot read, and never leaks cross-tenant data.
- **Null-safety:** an unresolvable / erroring aggregation injects `null` (scalar) or an
  empty map (grouped) and is logged at warning level — it MUST NOT fatal the save.
- **Recompute / staleness:** a materialised aggregate value is a SNAPSHOT at save time.
  If a contributing row later changes, dependents are stale until re-saved;
  `openregister:rematerialise-calculations <register> <schema>` refreshes them. Live
  propagation is out of scope.
- **Performance:** one ad-hoc aggregation runs per declared aggregate-reference per
  save. `AggregationRunner` already caches results (60 s TTL, RBAC-scoped key) and has
  native DB fast paths; bulk-save N+1 amplification and a save-time aggregate cache are
  noted as a follow-up — correctness-first here.

Out of scope: ComplianceReport-style structured folds (multi-row object-shaped
reductions) — those stay imperative guards.

## Capabilities

- `computed-fields`: the materialisation surface (`CalculationOnSaveListener` +
  `RematerialiseCalculationsCommand` + `CalculationAnnotationValidator` +
  `CalculationEvaluator`) gains (a) a declarative aggregate-reference mechanism
  (`x-openregister-aggregate-refs` → `@aggregate.<name>`), making save-time rollups
  expressible without imperative PHP guards, and (b) a `sha256` scalar operator.

## Impact

- **Code:** `lib/Listener/CalculationOnSaveListener.php` (pre-resolve aggregate
  references, inject `@aggregate`), `lib/Command/RematerialiseCalculationsCommand.php`
  (mirror), `lib/Service/Calculation/CalculationAnnotationValidator.php` (validate
  `@aggregate` tokens + the aggregate-refs block), `lib/Service/Calculation/CalculationEvaluator.php`
  (`sha256` op), `lib/Db/Schema.php` (`ANNOTATION_VOCABULARY`), and a new
  `lib/Service/Calculation/AggregateReferenceResolver.php` (the shared, testable
  resolution helper, mirroring `ReferenceResolver`).
- **Tests:** `tests/Unit/Service/Calculation/AggregateReferenceResolverTest.php` (inject
  resolves + null-safety + RBAC-scoped via `runAdhoc`) and a `sha256` case in the
  evaluator test suite (determinism + null-safety).
- **Dependent apps:** unlocks `UrenRegistratie.utilizationPercent` and
  `Account.emuAggregationHash` (declarative shillinq migration is part of the
  verification leg); additive, no app breaks.
- **No** DB-schema, route, or dependency changes. New annotation + op are opt-in.
- **Performance:** one ad-hoc aggregation per declared aggregate-reference per save;
  `AggregationRunner`'s 60 s cache + native paths apply. Save-time aggregate caching is
  a follow-up.
