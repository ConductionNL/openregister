## MODIFIED Requirements

### Requirement: JSON-AST calculation expressions MUST be evaluated by a pure-function evaluator
OpenRegister MUST provide a JSON-AST derivation engine, distinct from the Twig-based
`ComputedFieldHandler`. `CalculationEvaluator::evaluate(array $object, mixed $expression)` MUST
accept a bare scalar (resolved through `PlaceholderResolver`) or a single-key `{ "<op>": <args> }`
array, dispatching on the key. An alias MUST be accepted identically to its symbol form at
validation and at evaluation. The evaluator MUST be side-effect-free, and a malformed expression
MUST raise an `EvaluationException`, never return a silent default.

#### Scenario: The recognised v1 vocabulary
- **GIVEN** an expression whose operator key is one of `prop`, `lit`, `concat`, `if`, `not`, `and`, `or`, `+ - * / %`, the aliases `add` (`+`), `sub` (`-`), `mul` (`*`), `div` (`/`), `eq ne lt lte gt gte`, `now`, `diffDays`, `formatDate` or `dateDiff`
- **WHEN** it is validated and then evaluated
- **THEN** both MUST accept it, and the validator and the evaluator MUST NOT disagree on which operator names are valid
- **AND** any other key, a non-numeric arithmetic operand, a zero divisor or an unknown `dateDiff` unit MUST raise an `EvaluationException`
- **AND** evaluation MUST perform no I/O and no database access

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
`CalculationAnnotationValidator::validate()` MUST reject a materialised calculation (`materialise: true`) whose name is not a declared
schema property, with a `calculation-output-not-in-properties` error naming the calculation and the
property to add. `MagicMapper` writes only declared properties, so without this check the value is
computed and then silently dropped at save.

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
