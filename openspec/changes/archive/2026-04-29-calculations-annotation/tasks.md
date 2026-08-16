# Tasks — Calculations Annotation

- [ ] 1.1 Add `x-openregister-calculations` schema-save validation to `SchemaService` — every property reference in `expression` exists; every function name is in the v1 vocabulary; cycle detection (sort calculations by input dependencies, reject cycles); declared `type` matches expression return type (best-effort static check).
- [ ] 1.2 Create `lib/Service/Calculation/ExpressionParser.php` — parses the DSL into an AST. Vocabulary: bare property refs, arithmetic (`+ - * / %`), comparison (`eq ne lt lte gt gte`), logical (`&& || !`), string `concat`, conditional `if(cond, then, else)`, date `now()` / `diffDays(later, earlier)` / `formatDate(d, fmt)`.
- [ ] 1.3 Create `lib/Service/Calculation/CalculationEvaluator.php` — pure-function evaluator over an object payload + AST. Returns typed value or throws `EvaluationException`.
- [ ] 1.4 Unit tests covering every operator, every function, conditionals, null inputs, type mismatches, nested expressions.
- [ ] 1.5 Property tests: random valid expressions evaluate without exception; random invalid expressions produce a clear error code.
- [ ] 1.6 Create `lib/Listener/CalculationOnSaveListener.php` — subscribes to `ObjectCreatingEvent` + `ObjectUpdatingEvent`; for every `materialise: true` calculation, runs the evaluator and patches the field before persistence. Idempotent — skip recomputation when none of the inputs changed (track via `ObjectUpdatingEvent`'s old-state).
- [ ] 1.7 Render hook in `ObjectService::renderObject()` — for `materialise: false` calculations, run the evaluator at render time when `_include=calculations` is in the request. Cache per-request so multiple reads in one request reuse the result.
- [ ] 1.8 Schema-save cycle detection — sort calculations by input dependencies; reject cycles with `{ code: "calculation-cycle", path: [...] }`.
- [ ] 1.9 Integration tests: declare materialised + virtual calculations on a test schema; create / update / read; verify materialisation patches the object; verify virtual rendering applies on read; verify aggregations-annotation can target the materialised field.
- [ ] 1.10 `occ openregister:validate-calculations <register> <schema>` — re-runs every calculation against existing objects and reports any whose stored value differs from the recomputed one.
- [ ] 1.11 `occ openregister:rematerialise-calculations <register> <schema>` — bulk reprocesses every object's materialised calculations.
- [ ] 1.12 Doc: `docs/annotations/x-openregister-calculations.md` — DSL reference (every function with examples), virtual vs materialised, idempotency guarantees, cycle rules.
- [ ] 1.13 Update `openspec/platform-capabilities.md` with the `x-openregister-calculations` row.
