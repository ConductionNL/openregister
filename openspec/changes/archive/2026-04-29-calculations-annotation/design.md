# Design: Calculations Annotation

## Approach
Sit on top of the implemented `zoeken-filteren` spec and the parallel `aggregations-annotation` change (which adds the placeholder resolver). The evaluator is a pure function: object payload + parsed expression AST → typed value. No I/O, no DB access, no HTTP. Two integration points:

- An on-save listener (subscribed to `ObjectCreatingEvent` + `ObjectUpdatingEvent`) materialises declared fields into the persisted object before write.
- A render hook in `ObjectService::renderObject()` materialises virtual fields at response time when `_include=calculations` is set.

## Files Affected
- `lib/Service/SchemaService.php` — schema-save validation gains `x-openregister-calculations` rules (every property reference exists, every function in v1 vocabulary, no cyclic dependencies, declared `type` matches expression return type best-effort).
- `lib/Service/Calculation/CalculationEvaluator.php` — pure-function evaluator. Inputs: object + AST. Output: typed value or `EvaluationException`.
- `lib/Service/Calculation/ExpressionParser.php` — parses the v1 DSL into AST. Vocabulary: bare property refs, arithmetic, comparison, logical, `concat`, conditional `if`, date `now()` / `diffDays()` / `formatDate()`.
- `lib/Listener/CalculationOnSaveListener.php` — subscribes to `ObjectCreatingEvent` + `ObjectUpdatingEvent`; runs evaluator for every materialised calculation; patches the object before persistence; idempotent (skips when none of the calculation's input fields changed).
- `lib/Service/ObjectService.php` (existing) — render hook materialises virtual calculations when `_include=calculations` is present.
- `lib/Command/ValidateCalculations.php` + `lib/Command/RematerialiseCalculations.php` — `occ` commands for migration.

## Out of scope
- External calls in expressions (no HTTP, no I/O — pure evaluation).
- Aggregations over calculation outputs (covered by `aggregations-annotation`).
- Materialised calculations whose inputs span multiple objects (no joins; one-object-at-a-time).
