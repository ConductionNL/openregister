# Tasks — calc-engine-aggregate-reference

## 1. Aggregate-reference resolver (core)
- [x] 1.1 Add `lib/Service/Calculation/AggregateReferenceResolver.php` (mirrors `ReferenceResolver`): given the payload (with `@self` injected), an `x-openregister-aggregate-refs` map, and a register/schema context, return an `@aggregate` array. Injects `AggregationRunner` + `LoggerInterface`.
- [x] 1.2 Implement the tiny criterion-token resolver (literals pass through; `@self.<field>` reads the object's own top-level field; single-key `{year: …}` AST node) — NOT the full evaluator, keeping the evaluator pure.
- [x] 1.3 Per declared aggregate-ref: build `AggregationQuery::create(metric, field, resolvedFilters, groupBy)` and call `AggregationRunner::runAdhocByRef(registerRef, schemaRef, query)`.
- [x] 1.4 Map the result envelope: scalar `{value}` → inject the scalar under `@aggregate.<name>`; grouped `{groups:[{key,value}]}` → inject a `{stringKey: value}` map.
- [x] 1.5 Wrap every resolution in try/catch → on `\Throwable` inject `null` + `logger->warning`; never rethrow.

## 2. Wire into the save + recompute paths
- [x] 2.1 Extend `CalculationOnSaveListener::process()` to call `AggregateReferenceResolver` in the same pre-step as `@self`/`@ref`, inject `@aggregate`, and strip it before persist. Inject `AggregateReferenceResolver` via the constructor; add a `getAggregateRefs()` reader.
- [x] 2.2 Mirror the same pre-resolution in `RematerialiseCalculationsCommand` so the recompute path refreshes aggregates too.

## 3. sha256 scalar op
- [x] 3.1 Add a `sha256` arm + handler to `CalculationEvaluator::evaluate()` (uses `firstOperand()`; `null` → `null`; otherwise `hash('sha256', (string) $value)`).

## 4. Validation + vocabulary
- [x] 4.1 Extend `CalculationAnnotationValidator`: accept `@aggregate.<name>` / `@aggregate.<name>.<field>` prop tokens when `<name>` is a declared aggregate-ref; add `sha256` to `VALID_OPS`.
- [x] 4.2 Add `validateAggregateRefs()` to `CalculationAnnotationValidator`: each entry has a non-empty `schema` + a valid `metric`; non-`count` metric requires a `field`. Collect the declared names so the prop walker accepts `@aggregate.<name>` tokens.
- [x] 4.3 Register `x-openregister-aggregate-refs` in `Schema::ANNOTATION_VOCABULARY` (Db/Schema.php) — else the annotation is silently dropped on schema save (Ext1 gotcha).

## 5. Tests
- [x] 5.1 Add `tests/Unit/Service/Calculation/AggregateReferenceResolverTest.php`: scalar resolve→inject, grouped resolve→map, `@self` filter parameterisation (assert resolved literal on the `AggregationQuery`), unresolvable/throws→null, RBAC scoping (assert `runAdhocByRef` called with no bypass).
- [x] 5.2 Add `sha256` cases to the evaluator test suite: determinism (`sha256("abc")` known digest), non-string stringification, null-operand→null.
- [x] 5.3 Add validator cases: `@aggregate` token accepted when declared / rejected otherwise; aggregate-refs shape (missing schema / bad metric / non-count without field); `sha256` accepted as op.

## 6. Quality + traceability
- [x] 6.1 `php -l` every changed/new PHP file.
- [x] 6.2 SPDX headers on the new file; `@spec` tags on changed/new methods.
- [x] 6.3 Run the calc test suites + `composer check:strict` on changed files — fix any new findings; report pre-existing (the 3 known SchemaMapper unused-prop PHPStan warnings are pre-existing, leave them).

## 7. Live verify (shillinq, part of verification)
- [x] 7.1 Live-verified end-to-end on the running NC34 :80 instance via a dedicated isolated register (`calc-agg-verify`, since deleted) rather than mutating the production shillinq register (safer for the fleet foundation, and exercises the real listener path more fully). Created `timeentry` + `urenregistratie` schemas carrying `x-openregister-aggregate-refs` (sum of `hours` filtered by `@self.resourceId` + `billable:true`) and `x-openregister-calculations` with `@aggregate` ratio + `sha256` calcs. RESULTS: annotation survived schema save (vocab OK); POSTing a UrenRegistratie object materialised `billableHoursThisPeriod=240` (the correct filtered SUM over res-7 billable rows, EXCLUDING non-billable + res-9 — filter parameterisation by `@self` proven), `utilizationPercent=160` (= round(240/150*100,1)), and `contentHash=e9435a2f…572d3` which matches `sha256("res-7:"+uuid)` computed independently (sha256 determinism proven). Null-safety proven: a UrenRegistratie with a non-matching resourceId materialised `billableHoursThisPeriod=null`/`utilizationPercent=null` and the save SUCCEEDED (no fatal), while sha256 still computed. `openregister:rematerialise-calculations calc-agg-verify urenregistratie` ran the aggregate pre-resolution in the command path with no aggregate errors (the per-calc divide-by-null log + the CLI-no-session save-permission block are pre-existing, unrelated to this change).

Acceptance criteria:
- `x-openregister-aggregate-refs` resolves via `AggregationRunner::runAdhoc()` in the listener (and command), injects `@aggregate.<name>`, and the pure `CalculationEvaluator` is untouched by part (a).
- `sha256` dispatches from `evaluate()`, is deterministic, and is null-safe.
- RBAC/tenant scope is inherited from `runAdhoc` (never bypassed); unresolvable → null, never fatal.
- `x-openregister-aggregate-refs` is registered in `ANNOTATION_VOCABULARY`; the validator accepts `@aggregate.*` tokens.
- No existing operator or annotation behaviour changes (backward-compatible).

Quality:
- New code carries EUPL-1.2 SPDX header + full PHPDoc per OR conventions.
- No new PHPMD/PHPStan/Psalm regressions beyond documented suppressions.
