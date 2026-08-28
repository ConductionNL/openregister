## ADDED Requirements

### Requirement: Scalar function operators in the calculation evaluator
The `CalculationEvaluator` JSON-AST interpreter SHALL support seven additional pure per-object scalar operators — `max`, `min`, `coalesce`, `abs`, `round`, `year`, and `monthsElapsed` — dispatched from `evaluate()` alongside the existing operator vocabulary. Each operator SHALL evaluate its operands as sub-expressions through `evaluate()`, SHALL raise `EvaluationException` on malformed arity or wrong-typed (non-null) operands, and SHALL return `null` for unparseable date inputs (matching the existing `diffDays` / `formatDate` convention). These operators MUST be additive: no existing operator's name, arity, or behaviour changes. The operators SHALL NOT perform cross-object folding (summing or looking up values on OTHER objects); cross-object reduction remains the responsibility of `x-openregister-aggregations`.

#### Scenario: max returns the largest numeric operand
- **WHEN** an expression `{ "max": [ { "prop": "a" }, { "prop": "b" }, 5 ] }` is evaluated against an object where `a = 3` and `b = 9`
- **THEN** the result MUST be `9`

#### Scenario: max skips null operands and returns null when all operands are null
- **WHEN** `{ "max": [ { "prop": "missing1" }, { "prop": "missing2" } ] }` is evaluated and both properties are absent
- **THEN** the result MUST be `null`
- **AND** when at least one operand is present, only the present operands MUST be considered

#### Scenario: min returns the smallest numeric operand
- **WHEN** `{ "min": [ 10, { "prop": "a" }, 4 ] }` is evaluated against an object where `a = 7`
- **THEN** the result MUST be `4`

#### Scenario: non-numeric operand to max raises an evaluation error
- **WHEN** `{ "max": [ 1, "abc" ] }` is evaluated and `"abc"` is a non-null non-numeric value
- **THEN** an `EvaluationException` MUST be raised

#### Scenario: coalesce returns the first non-null operand
- **WHEN** `{ "coalesce": [ { "prop": "missing" }, { "prop": "fallback" } ] }` is evaluated against an object where `missing` is absent and `fallback = "x"`
- **THEN** the result MUST be `"x"`

#### Scenario: coalesce returns null when every operand is null
- **WHEN** `{ "coalesce": [ { "prop": "a" }, { "prop": "b" } ] }` is evaluated and both are absent
- **THEN** the result MUST be `null`

#### Scenario: abs returns the absolute value
- **WHEN** `{ "abs": [ { "prop": "variance" } ] }` is evaluated against an object where `variance = -12.5`
- **THEN** the result MUST be `12.5`
- **AND** a null operand MUST yield `null`

#### Scenario: round honours an optional precision operand
- **WHEN** `{ "round": [ { "prop": "amount" }, 2 ] }` is evaluated against an object where `amount = 3.14159`
- **THEN** the result MUST be `3.14`
- **AND** `{ "round": [ 3.6 ] }` (no precision) MUST return `4`
- **AND** a non-integer precision operand MUST raise an `EvaluationException`

#### Scenario: year extracts the four-digit year from a date
- **WHEN** `{ "year": [ { "prop": "@self.created" } ] }` is evaluated and `@self.created` is the ISO date `2026-06-20T10:00:00+00:00`
- **THEN** the result MUST be the integer `2026`
- **AND** an unparseable or absent date operand MUST yield `null`

#### Scenario: monthsElapsed returns whole calendar months between two dates
- **WHEN** `{ "monthsElapsed": [ "2026-06-20", "2026-01-20" ] }` is evaluated
- **THEN** the result MUST be the integer `5`
- **AND** a partial trailing month MUST be floored (e.g. `2026-06-19` vs `2026-01-20` MUST yield `4`)
- **AND** when the later operand precedes the earlier operand the result MUST be negative
- **AND** an unparseable operand MUST yield `null`
- **AND** fewer than two operands MUST raise an `EvaluationException`
