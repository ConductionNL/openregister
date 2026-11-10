## MODIFIED Requirements

### Requirement: JSON-AST calculation expressions MUST be evaluated by a pure-function evaluator

OpenRegister MUST provide a second derivation engine, distinct from the Twig-based
`ComputedFieldHandler`, that evaluates calculations expressed as a single-key JSON
expression AST. `CalculationEvaluator::evaluate(array $object, mixed $expression)`
MUST accept either a bare scalar literal (resolved through the shared
`PlaceholderResolver`) or a single-key array of the form `{ "<op>": <args> }`, and MUST
dispatch on the operator key. The recognised v1 vocabulary is: `prop`, `lit`, `concat`,
`if`, `not`, `and`, `or`, the arithmetic operators `+ - * / %`, the natural-language
arithmetic aliases `add` (alias of `+`), `sub` (alias of `-`), `mul` (alias of `*`), `div`
(alias of `/`), the comparison operators `eq ne lt lte gt gte`, and the date operators
`now`, `diffDays`, `formatDate`, `dateDiff`. An alias MUST be accepted identically to its
symbol form at both validation time and evaluation time — the two MUST NOT disagree on
which operator names are valid. The evaluator MUST be side-effect-free: no I/O, no
database access. Any malformed expression, unknown operator, non-numeric arithmetic
operand, zero divisor, or unknown `dateDiff` unit MUST raise an `EvaluationException`
rather than returning a silent default.

#### Scenario: Single-key dispatch on a known operator
- **GIVEN** the expression `{ "+": [{ "prop": "aantal" }, 1] }` and an object `{ "aantal": 4 }`
- **WHEN** `evaluate()` is called
- **THEN** the result MUST be `5`

#### Scenario: The mul alias dispatches identically to the * operator
- **GIVEN** the expression `{ "mul": [{ "prop": "aantal" }, 3] }` and an object `{ "aantal": 4 }`
- **WHEN** `evaluate()` is called
- **THEN** the result MUST be `12`, identical to `{ "*": [{ "prop": "aantal" }, 3] }`

#### Scenario: add/sub/div aliases dispatch identically to their symbol forms
- **GIVEN** the expressions `{ "add": [2, 3] }`, `{ "sub": [5, 2] }`, `{ "div": [10, 2] }`
- **WHEN** each is evaluated
- **THEN** the results MUST be `5`, `3`, `5` respectively, identical to `+`/`-`/`/`

#### Scenario: Bare scalar resolves through the placeholder resolver
- **GIVEN** the expression is the string `"$now"` (a placeholder, not an array)
- **WHEN** `evaluate()` is called
- **THEN** the value MUST be resolved by the shared `PlaceholderResolver` and returned as-is for non-placeholder scalars

#### Scenario: Dotted-path and @self property resolution
- **GIVEN** an object whose `@self` metadata was injected by the on-save listener
- **WHEN** the expression `{ "prop": "@self.created" }` is evaluated
- **THEN** the value at the dotted path MUST be returned
- **AND** a path that does not exist MUST resolve to null rather than raising

#### Scenario: Expression with more than one top-level key is rejected
- **GIVEN** the expression `{ "+": [1, 2], "-": [3] }` (two keys)
- **WHEN** `evaluate()` is called
- **THEN** an `EvaluationException` MUST be raised stating the expression must be a single-key object

#### Scenario: Arithmetic guards reject non-numeric and zero-divisor operands
- **GIVEN** the expression `{ "/": [{ "prop": "a" }, { "prop": "b" }] }`
- **WHEN** `b` resolves to `0`
- **THEN** an `EvaluationException` MUST be raised requiring non-zero numeric operands
- **AND** when any operand of `+ - * %` (or their aliases) is non-numeric an `EvaluationException` MUST be raised

#### Scenario: Comparison normalises ISO-date operands before ordering
- **GIVEN** the expression `{ "lt": [{ "prop": "start" }, { "prop": "end" }] }` with ISO-8601 date strings
- **WHEN** `evaluate()` is called
- **THEN** both operands MUST be coerced to integer timestamps before the `<` comparison
- **AND** an ordering comparison against a null operand MUST yield false

#### Scenario: dateDiff computes a signed integer difference in the requested unit
- **GIVEN** the expression `{ "dateDiff": { "from": "now", "to": { "prop": "@self.dueDate" }, "unit": "days" } }`
- **WHEN** `to` is after `from`
- **THEN** the result MUST be a positive integer day count
- **AND** when `to` is before `from` the result MUST be negative
- **AND** an unsupported `unit` MUST raise an `EvaluationException`
- **AND** an unparseable `from` or `to` MUST yield null

## ADDED Requirements

### Requirement: A materialised calculation's declared name MUST also be a declared schema property
`CalculationAnnotationValidator::validate()` MUST reject a calculation whose declared name is not
also present in the schema's `properties`, with a `calculation-output-not-in-properties` error
naming the calculation and stating that a matching property must be added. This closes the gap
between "the annotation validates" and "the computed value is actually persisted": `MagicMapper`
only writes payload keys present in `properties`, so a calculation output whose name was never
mirrored into `properties` evaluates correctly but is silently dropped at save time with no
indication in the validation result.

#### Scenario: A calculation whose name has no matching property is rejected
- **GIVEN** a schema declares `x-openregister-calculations: { "total": { "type": "number",
  "expression": {...} } }` and `properties` does NOT contain a `total` key
- **WHEN** the schema is saved
- **THEN** validation MUST fail with a `calculation-output-not-in-properties` error naming `total`

#### Scenario: A calculation whose name matches a declared property validates
- **GIVEN** the same calculation as above, and `properties` DOES contain a `total` key (type
  `number`, matching the calculation's declared `type`)
- **WHEN** the schema is saved
- **THEN** validation MUST succeed and the calculated value MUST be persisted on save

#### Scenario: A previously-silently-dropped calculation output is now caught at save time
- **GIVEN** a schema whose calculation `total` was previously validating successfully while its
  output was silently dropped by `MagicMapper` (no matching property)
- **WHEN** the schema is re-saved after this fix
- **THEN** the save MUST surface the `calculation-output-not-in-properties` error instead of
  succeeding silently, so the author is prompted to add the missing property
