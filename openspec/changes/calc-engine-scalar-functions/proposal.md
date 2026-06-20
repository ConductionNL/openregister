---
kind: code
---

## Why

OpenRegister's per-object calculation engine (`CalculationEvaluator`, a JSON-AST
interpreter behind `x-openregister-calculations`) supports arithmetic, comparison,
logical, string, and a few date operators — but lacks the common scalar functions
that derived fields routinely need: `max`, `min`, `coalesce`, `abs`, `round`,
`year`, and `monthsElapsed`. A fleet audit (shillinq) found ~43 declarative
calculations that cannot be expressed today because these primitives are missing,
forcing apps back to imperative PHP (against ADR-031). Adding them is purely
additive and unlocks those calcs fleet-wide.

## What Changes

- Add **seven new per-object scalar operators** to `CalculationEvaluator::evaluate()`'s
  `match($op)` dispatch, each a pure function over the object payload:
  - `max` — largest of N numeric sub-expression operands.
  - `min` — smallest of N numeric sub-expression operands.
  - `coalesce` — first non-null operand of N sub-expressions (null-fallback).
  - `abs` — absolute value of one numeric operand.
  - `round` — round one numeric operand to an optional second `precision` operand (default 0).
  - `year` — extract the four-digit year (integer) from one date operand.
  - `monthsElapsed` — whole calendar months between two date operands (later, earlier).
- Each operator follows the established evaluator conventions: operands are
  sub-expressions evaluated via `evaluate()`, malformed arity / non-numeric /
  non-date inputs raise `EvaluationException`, and unparseable date operands
  return `null` (matching `diffDays`/`formatDate`).
- Add unit-test coverage for each new operator (happy path + null/edge cases) in
  the evaluator test suite.
- **Non-goal (explicitly out of scope):** cross-object folding operators
  (`sum`/`lookup`/`map` over *other* objects). Those belong to the aggregation
  engine (`x-openregister-aggregations`), not this per-object evaluator.

This change is **backward-compatible** — additive operators only. No existing
operator, signature, or behaviour changes. No new routes, schemas, or dependencies.

## Capabilities

### New Capabilities
<!-- none -->

### Modified Capabilities
- `computed-fields`: the `CalculationEvaluator` vocabulary (currently `prop, lit,
  concat, if, not, and, or, +, -, *, /, %, eq/ne/lt/lte/gt/gte, now, diffDays,
  formatDate, dateDiff`) gains seven scalar operators (`max, min, coalesce, abs,
  round, year, monthsElapsed`). This is a requirement-level extension of the
  "String, Date, and Math Operations" surface for the JSON-AST evaluator path.

## Impact

- **Code:** `lib/Service/Calculation/CalculationEvaluator.php` (add operator arms +
  private handlers). No change to `CalculationOnSaveListener` (the `materialise: true`
  gate, serialisation, and dispatch are operator-agnostic).
- **Tests:** `tests/Unit/Service/Calculation/CalculationEvaluatorTest.php` (and/or a
  new sibling test class) — one group of cases per new operator.
- **Dependent apps:** every Conduction app that declares `x-openregister-calculations`
  benefits; none break (additive). shillinq's ~43 stalled calcs become expressible.
- **No** DB, API, schema, or dependency changes.
